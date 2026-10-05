<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Api\BookmarkManagementInterface;
use Magento\Ui\Api\Data\BookmarkInterface;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Model\GridConfig;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderPlugin;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class ProductDataProviderPluginTest extends TestCase
{
    use DbDoublesTrait;

    private array $config = [];
    private ?GridConfig $gridConfig = null;
    /** @var array<int,array> fetchAll results, consumed in query order */
    private array $results = [];
    private array $selected = [];
    private bool $storeThrows = false;
    private bool $bookmarksThrow = false;
    private array $bookmarkConfigs = [];
    private array $overrides = [];

    private function plugin(): ProductDataProviderPlugin
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('fetchAll')->willReturnCallback(fn () => array_shift($this->results) ?? []);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function () use ($store) {
            if ($this->storeThrows) {
                throw new \RuntimeException('no store');
            }
            return $store;
        });

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->config[$path] ?? null);

        $columns = $this->createStub(ColumnConfigRepository::class);
        $columns->method('getAll')->willReturnCallback(fn () => $this->overrides);

        $bookmarks = $this->createStub(BookmarkManagementInterface::class);
        $bookmarks->method('loadByNamespace')->willReturnCallback(function () {
            if ($this->bookmarksThrow) {
                throw new \RuntimeException('bookmarks');
            }
            $items = [];
            foreach ($this->bookmarkConfigs as $config) {
                $bookmark = $this->createStub(BookmarkInterface::class);
                $bookmark->method('getConfig')->willReturn($config);
                $items[] = $bookmark;
            }
            $results = $this->createStub(SearchResultsInterface::class);
            $results->method('getItems')->willReturn($items);
            return $results;
        });

        return new ProductDataProviderPlugin(
            $resource,
            $storeManager,
            $scope,
            $this->createStub(StockRegistryInterface::class),
            $this->createStub(ImageHelper::class),
            $this->createStub(ProductFactory::class),
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(PriceCurrencyInterface::class),
            $columns,
            $this->createStub(EavConfig::class),
            $bookmarks,
            $this->gridConfig
        );
    }

    private function provider(): ProductDataProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnCallback(function ($code, $join) use ($collection) {
            if ($code === 'broken_attr') {
                throw new \RuntimeException('bad attribute');
            }
            $this->selected[] = [$code, $join];
            return $collection;
        });
        $provider = $this->createStub(ProductDataProvider::class);
        $provider->method('getCollection')->willReturn($collection);
        return $provider;
    }

    public function testBeforeGetDataSelectsForcedOverriddenAndBookmarkedAttributes(): void
    {
        $this->overrides = ['color' => [], 'panth_qty_sold' => [], 'broken_attr' => []];
        $this->bookmarkConfigs = [[
            'views' => ['default' => ['data' => ['columns' => [
                'size' => ['visible' => true],
                'material' => ['visible' => false],
                'panth_availability' => ['visible' => true],
            ]]]],
        ]];

        $result = $this->plugin()->beforeGetData($this->provider());

        $this->assertSame([], $result);
        $codes = array_column($this->selected, 0);
        $this->assertContains('cost', $codes);
        $this->assertContains('url_key', $codes);
        $this->assertContains('color', $codes);
        $this->assertContains('size', $codes);
        $this->assertNotContains('material', $codes);
        $this->assertNotContains('panth_qty_sold', $codes);
        $this->assertNotContains('panth_availability', $codes);
        $this->assertSame(['left'], array_values(array_unique(array_column($this->selected, 1))));
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testBookmarkFailureStillSelectsForcedAttributes(): void
    {
        $this->bookmarksThrow = true;

        $this->plugin()->beforeGetData($this->provider());

        $this->assertContains('special_price', array_column($this->selected, 0));
    }

    public function testAfterGetDataPassesThroughEmptyOrInvalidResults(): void
    {
        $plugin = $this->plugin();
        $provider = $this->createStub(ProductDataProvider::class);

        $this->assertSame([], $plugin->afterGetData($provider, null));
        $this->assertSame(['items' => [], 'totalRecords' => 0], $plugin->afterGetData($provider, ['items' => [], 'totalRecords' => 0]));
        $this->assertSame(['items' => [['sku' => 'x']]], $plugin->afterGetData($provider, ['items' => [['sku' => 'x']]]));
    }

    public function testRowsAreEnrichedWithGridData(): void
    {
        $this->config = [
            'panth_product_grid/columns/low_stock_threshold' => '5',
            'panth_product_grid/modification/show_qty_integer' => '1',
            'panth_product_grid/columns/linked_products_qty' => '0',
            'catalog/seo/product_url_suffix' => '.html',
            'cataloginventory/item_options/manage_stock' => '1',
        ];
        $this->results = [
            [
                ['product_id' => 1, 'category_id' => 3, 'name' => 'Men'],
                ['product_id' => 1, 'category_id' => 4, 'name' => 'Tops & <Tees>'],
                ['product_id' => 1, 'category_id' => 3, 'name' => 'Men'],
                ['product_id' => 2, 'category_id' => 8, 'name' => null],
            ],
            [['product_id' => 1, 'qty_sold' => '17']],
            [['entity_id' => 1, 'cnt' => '2']],
            [
                ['product_id' => 1, 'qty' => '3.0000', 'is_in_stock' => 1, 'manage_stock' => 0,
                    'use_config_manage_stock' => 1, 'backorders' => 0, 'use_config_backorders' => 1],
                ['product_id' => 2, 'qty' => '50', 'is_in_stock' => 0, 'manage_stock' => 1,
                    'use_config_manage_stock' => 0, 'backorders' => 2, 'use_config_backorders' => 0],
                ['product_id' => 3, 'qty' => '0', 'is_in_stock' => 1, 'manage_stock' => 0,
                    'use_config_manage_stock' => 0, 'backorders' => 0, 'use_config_backorders' => 1],
            ],
        ];

        $result = $this->plugin()->afterGetData($this->createStub(ProductDataProvider::class), ['items' => [
            ['entity_id' => '1', 'url_key' => 'tee'],
            ['entity_id' => '2'],
            ['entity_id' => '3', 'qty' => '0'],
            ['sku' => 'no-id'],
        ]]);

        [$one, $two, $three, $four] = $result['items'];
        $this->assertSame([3, 4], $one['panth_categories']);
        $this->assertSame('Men, Tops & <Tees>', $one['panth_categories_text']);
        $this->assertStringContainsString('>Men</span>', $one['panth_categories_label']);
        $this->assertStringContainsString('Tops &amp; &lt;Tees&gt;', $one['panth_categories_label']);
        $this->assertSame(17, $one['panth_qty_sold']);
        $this->assertSame('1', $one['panth_availability']);
        $this->assertSame('0', $one['panth_backorders']);
        $this->assertSame(1, $one['panth_low_stock']);
        $this->assertSame('3', $one['qty']);
        $this->assertSame(2, $one['panth_tier_price_count']);
        $this->assertSame('2 tiers', $one['panth_tier_price_label']);
        $this->assertSame('https://shop.test/tee.html', $one['panth_storefront_url']);
        $this->assertSame(1, $one['panth_linked_products_max']);

        $this->assertSame([8], $two['panth_categories']);
        $this->assertSame('', $two['panth_categories_label']);
        $this->assertSame(0, $two['panth_qty_sold']);
        $this->assertSame('0', $two['panth_availability']);
        $this->assertSame('2', $two['panth_backorders']);
        $this->assertSame(0, $two['panth_low_stock']);
        $this->assertSame('Add', $two['panth_tier_price_label']);
        $this->assertSame('', $two['panth_storefront_url']);

        $this->assertSame('2', $three['panth_availability']);
        $this->assertSame(0, $three['panth_low_stock'], 'unmanaged stock is never low');
        $this->assertSame([], $three['panth_categories']);

        $this->assertSame(['sku' => 'no-id'], $four);
    }

    public function testUseConfigManageStockFollowsTheConfiguredSetting(): void
    {
        $this->config['cataloginventory/item_options/manage_stock'] = '0';
        $this->config['panth_product_grid/columns/low_stock_threshold'] = '5';
        $this->results = [[], [], [], [[
            'product_id' => 7, 'qty' => '1', 'is_in_stock' => 1, 'manage_stock' => 1,
            'use_config_manage_stock' => 1, 'backorders' => 0, 'use_config_backorders' => 1,
        ]]];

        $row = $this->plugin()->afterGetData(
            $this->createStub(ProductDataProvider::class),
            ['items' => [['entity_id' => 7]]]
        )['items'][0];

        $this->assertSame('2', $row['panth_availability']);
        $this->assertSame(0, $row['panth_low_stock']);
    }

    public function testDisabledGridLeavesTheDataProviderUntouched(): void
    {
        $config = $this->createStub(GridConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $this->gridConfig = $config;
        $this->results = [[], [], [], [[
            'product_id' => 7, 'qty' => '1', 'is_in_stock' => 1, 'manage_stock' => 1,
            'use_config_manage_stock' => 0, 'backorders' => 0, 'use_config_backorders' => 0,
        ]]];
        $plugin = $this->plugin();
        $provider = $this->createStub(ProductDataProvider::class);

        $this->assertSame([], $plugin->beforeGetData($provider));
        $result = ['items' => [['entity_id' => 7, 'qty' => '1.0000']], 'totalRecords' => 1];
        $this->assertSame($result, $plugin->afterGetData($provider, $result));
        $this->assertSame([], $plugin->afterGetData($provider, null));
    }

    public function testUseConfigBackordersFollowsTheConfiguredSetting(): void
    {
        $this->config['cataloginventory/item_options/backorders'] = '1';
        $this->results = [[], [], [], [
            ['product_id' => 7, 'qty' => '1', 'is_in_stock' => 1, 'manage_stock' => 1,
                'use_config_manage_stock' => 0, 'backorders' => 0, 'use_config_backorders' => 1],
            ['product_id' => 8, 'qty' => '1', 'is_in_stock' => 1, 'manage_stock' => 1,
                'use_config_manage_stock' => 0, 'backorders' => 2, 'use_config_backorders' => 0],
        ]];

        $items = $this->plugin()->afterGetData(
            $this->createStub(ProductDataProvider::class),
            ['items' => [['entity_id' => 7], ['entity_id' => 8]]]
        )['items'];

        $this->assertSame('1', $items[0]['panth_backorders']);
        $this->assertSame('2', $items[1]['panth_backorders']);
    }

    public function testQtyStaysFloatAndUrlIsEmptyWhenStoreUnavailable(): void
    {
        $this->storeThrows = true;
        $this->config['panth_product_grid/columns/linked_products_qty'] = '4';
        $this->results = [[], [], [], [[
            'product_id' => 5, 'qty' => '2.5', 'is_in_stock' => 1, 'manage_stock' => 1,
            'use_config_manage_stock' => 0, 'backorders' => 1, 'use_config_backorders' => 0,
        ]]];

        $row = $this->plugin()->afterGetData(
            $this->createStub(ProductDataProvider::class),
            ['items' => [['entity_id' => 5, 'url_key' => 'x']]]
        )['items'][0];

        $this->assertSame(2.5, $row['qty']);
        $this->assertSame('', $row['panth_storefront_url']);
        $this->assertSame(4, $row['panth_linked_products_max']);
        $this->assertSame(0, $row['panth_low_stock'], 'unset threshold is 0 and qty 2.5 is above it');
    }
}

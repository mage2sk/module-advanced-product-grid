<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\DataProvider;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderSortPlugin;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddBackordersFilterToCollection;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddLowStockFilterToCollection;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\StockExpressions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockFiltersAndSortTest extends TestCase
{
    use DbDoublesTrait;

    private function expressions(array $config = []): StockExpressions
    {
        $config += [
            'cataloginventory/item_options/manage_stock' => '1',
            'cataloginventory/item_options/backorders' => '0',
            'panth_product_grid/columns/low_stock_threshold' => '5',
        ];
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn ($path) => $config[$path] ?? null);
        return new StockExpressions($scope);
    }

    private function collection(array $fromParts = []): Collection
    {
        $select = $this->select($fromParts);
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getTable')->willReturnCallback(static fn ($t) => 'pfx_' . $t);
        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getResource')->willReturn($resource);
        return $collection;
    }

    private function provider(object $collection): ProductDataProvider
    {
        $provider = $this->createStub(ProductDataProvider::class);
        $provider->method('getCollection')->willReturn($collection);
        return $provider;
    }

    public function testExpressionsFollowTheInventoryConfiguration(): void
    {
        $expr = $this->expressions([
            'cataloginventory/item_options/manage_stock' => '0',
            'cataloginventory/item_options/backorders' => '2',
            'panth_product_grid/columns/low_stock_threshold' => '12',
        ]);

        $this->assertSame(0, $expr->configManageStock());
        $this->assertSame(2, $expr->configBackorders());
        $this->assertSame(12, $expr->lowStockThreshold());
        $this->assertStringContainsString('use_config_manage_stock = 1 THEN 0 ELSE panth_stock.manage_stock', $expr->manageStockExpression());
        $this->assertStringContainsString('use_config_backorders = 1 THEN 2 ELSE panth_stock.backorders', $expr->backordersExpression());
        $this->assertStringContainsString('COALESCE(panth_stock.qty, 0) <= 12 THEN 1 ELSE 0', $expr->lowStockExpression());
        $this->assertStringContainsString('= 0 THEN 2 WHEN panth_stock.is_in_stock = 1 THEN 1 ELSE 0', $expr->availabilityExpression());
        $this->assertSame('COALESCE(panth_qs.qty_sold, 0)', $expr->qtySoldExpression());
    }

    public function testNegativeConfigValuesAreClamped(): void
    {
        $expr = $this->expressions(['cataloginventory/item_options/backorders' => '-3', 'cataloginventory/item_options/manage_stock' => '7']);

        $this->assertSame(0, $expr->configBackorders());
        $this->assertSame(0, $expr->configManageStock());
    }

    public function testJoinsAreAddedOnceAndSkippedWhenAliasExists(): void
    {
        $expr = $this->expressions();
        $expr->joinStock($this->collection());
        $expr->joinQtySold($this->collection());
        $this->assertCount(2, $this->joins);
        $this->assertSame(['panth_stock' => 'pfx_cataloginventory_stock_item'], $this->joins[0][1]);
        $this->assertSame('panth_stock.product_id = e.entity_id AND panth_stock.stock_id = 1', $this->joins[0][2]);
        $this->assertSame(['panth_qs' => 'pfx_panth_product_grid_qty_sold'], $this->joins[1][1]);

        $this->joins = [];
        $expr->joinStock($this->collection(['panth_stock' => []]));
        $expr->joinQtySold($this->collection(['panth_qs' => []]));
        $this->assertSame([], $this->joins);
    }

    public static function lowStockCases(): array
    {
        return [
            'yes array' => [['eq' => '1'], 1],
            'no scalar' => ['0', 0],
            'filter object' => ['filter:1', 1],
        ];
    }

    #[DataProvider('lowStockCases')]
    public function testLowStockFilterUsesTheThresholdExpression(mixed $condition, int $expected): void
    {
        if (is_string($condition) && str_starts_with($condition, 'filter:')) {
            $filter = new Filter();
            $filter->setValue(substr($condition, 7));
            $condition = $filter;
        }
        (new AddLowStockFilterToCollection($this->expressions()))->addFilter($this->collection(), 'panth_low_stock', $condition);

        $this->assertCount(1, $this->joins);
        $this->assertCount(1, $this->wheres);
        $this->assertStringContainsString('<= 5 THEN 1 ELSE 0 END) = ?', $this->wheres[0][0]);
        $this->assertSame($expected, $this->wheres[0][1]);
    }

    public function testLowStockFilterIgnoresUnknownValuesAndOtherCollections(): void
    {
        $filter = new AddLowStockFilterToCollection($this->expressions());
        $filter->addFilter($this->collection(), 'panth_low_stock', ['eq' => '']);
        $filter->addFilter($this->collection(), 'panth_low_stock', 'maybe');
        $filter->addFilter($this->createStub(DataCollection::class), 'panth_low_stock', '1');

        $this->assertSame([], $this->wheres);
        $this->assertSame([], $this->joins);
    }

    public function testBackordersFilterHonoursTheConfigValue(): void
    {
        $filter = new AddBackordersFilterToCollection($this->expressions(['cataloginventory/item_options/backorders' => '1']));
        $filter->addFilter($this->collection(), 'panth_backorders', ['eq' => '2']);
        $filter->addFilter($this->collection(['panth_stock' => []]), 'panth_backorders', '0');
        $filter->addFilter($this->collection(), 'panth_backorders', '5');
        $filter->addFilter($this->createStub(DataCollection::class), 'panth_backorders', '1');

        $this->assertCount(1, $this->joins);
        $this->assertCount(2, $this->wheres);
        $this->assertStringContainsString('use_config_backorders = 1 THEN 1 ELSE panth_stock.backorders END) = ?', $this->wheres[0][0]);
        $this->assertSame([2, 0], array_column($this->wheres, 1));
    }

    public static function sortCases(): array
    {
        return [
            'qty sold desc' => ['panth_qty_sold', 'desc', 'COALESCE(panth_qs.qty_sold, 0) DESC', 'panth_qs'],
            'availability asc' => ['panth_availability', 'asc', 'THEN 2 WHEN panth_stock.is_in_stock = 1 THEN 1 ELSE 0 END) ASC', 'panth_stock'],
            'backorders default direction' => ['panth_backorders', 'bogus', 'ELSE panth_stock.backorders END) ASC', 'panth_stock'],
            'low stock desc' => ['panth_low_stock', 'DESC', '<= 5 THEN 1 ELSE 0 END) DESC', 'panth_stock'],
        ];
    }

    #[DataProvider('sortCases')]
    public function testVirtualColumnsAreSortedBySqlExpression(
        string $field,
        string $direction,
        string $expectedOrder,
        string $expectedAlias
    ): void {
        $proceeded = false;
        $plugin = new ProductDataProviderSortPlugin($this->expressions());

        $result = $plugin->aroundAddOrder(
            $this->provider($this->collection()),
            static function () use (&$proceeded) {
                $proceeded = true;
                return 'proceeded';
            },
            $field,
            $direction
        );

        $this->assertNull($result);
        $this->assertFalse($proceeded);
        $this->assertCount(1, $this->orders);
        $this->assertStringEndsWith($expectedOrder, $this->orders[0]);
        $this->assertSame($expectedAlias, array_key_first($this->joins[0][1]));
    }

    public function testRegularFieldsAndNonProductCollectionsProceed(): void
    {
        $plugin = new ProductDataProviderSortPlugin($this->expressions());
        $calls = [];
        $proceed = static function ($field, $direction) use (&$calls) {
            $calls[] = [$field, $direction];
            return 'core';
        };

        $this->assertSame('core', $plugin->aroundAddOrder($this->provider($this->collection()), $proceed, 'name', 'asc'));
        $this->assertSame(
            'core',
            $plugin->aroundAddOrder($this->provider($this->createStub(DataCollection::class)), $proceed, 'panth_qty_sold', 'desc')
        );

        $this->assertSame([['name', 'asc'], ['panth_qty_sold', 'desc']], $calls);
        $this->assertSame([], $this->orders);
        $this->assertSame([], $this->joins);
    }
}

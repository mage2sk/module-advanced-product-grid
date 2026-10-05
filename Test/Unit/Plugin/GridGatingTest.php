<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Ui\Component\Product\MassAction;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Ui\Component\ExportButton;
use Panth\AdvancedProductGrid\Model\GridConfig;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderFilterPlugin;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderGatePlugin;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderSortPlugin;
use Panth\AdvancedProductGrid\Plugin\Ui\Component\ExportButtonGatePlugin;
use Panth\AdvancedProductGrid\Plugin\Ui\Component\ListingControlsGatePlugin;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\StockExpressions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GridGatingTest extends TestCase
{
    private function gridConfig(bool $enabled): GridConfig
    {
        $config = $this->createStub(GridConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    private function filter(string $field, mixed $value = '1'): Filter
    {
        $filter = new Filter();
        $filter->setField($field);
        $filter->setValue($value);
        $filter->setConditionType('eq');
        return $filter;
    }

    public static function flagValues(): array
    {
        return [
            'yes' => ['1', true],
            'no' => ['0', false],
            'unset' => [null, false],
        ];
    }

    #[DataProvider('flagValues')]
    public function testGridConfigReadsTheEnableFlag(?string $stored, bool $expected): void
    {
        $paths = [];
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static function ($path) use ($stored, &$paths) {
            $paths[] = $path;
            return (bool)$stored;
        });

        $this->assertSame($expected, (new GridConfig($scope))->isEnabled());
        $this->assertSame(['panth_product_grid/general/enabled'], $paths);
    }

    public function testGateDropsModuleFiltersAndSortsWhenDisabled(): void
    {
        $plugin = new ProductDataProviderGatePlugin($this->gridConfig(false));
        $provider = $this->createStub(ProductDataProvider::class);
        $calls = [];
        $proceedFilter = static function ($filter) use (&$calls) {
            $calls[] = 'filter:' . $filter->getField();
            return 'core';
        };
        $proceedOrder = static function ($field, $direction) use (&$calls) {
            $calls[] = 'order:' . $field . ':' . $direction;
            return 'core';
        };

        $this->assertNull($plugin->aroundAddFilter($provider, $proceedFilter, $this->filter('panth_low_stock')));
        $this->assertNull($plugin->aroundAddOrder($provider, $proceedOrder, 'panth_qty_sold', 'DESC'));
        $this->assertSame('core', $plugin->aroundAddFilter($provider, $proceedFilter, $this->filter('sku', '%x%')));
        $this->assertSame('core', $plugin->aroundAddOrder($provider, $proceedOrder, 'name', 'ASC'));

        $this->assertSame(['filter:sku', 'order:name:ASC'], $calls);
    }

    public function testGatePassesEverythingThroughWhenEnabled(): void
    {
        $plugin = new ProductDataProviderGatePlugin($this->gridConfig(true));
        $provider = $this->createStub(ProductDataProvider::class);
        $calls = [];

        $plugin->aroundAddFilter($provider, static function ($f) use (&$calls) {
            $calls[] = $f->getField();
        }, $this->filter('panth_backorders'));
        $plugin->aroundAddOrder($provider, static function ($field) use (&$calls) {
            $calls[] = $field;
        }, 'panth_availability');

        $this->assertSame(['panth_backorders', 'panth_availability'], $calls);
    }

    public function testMassEditActionIsOnlyAllowedWhenEnabled(): void
    {
        $massAction = $this->createStub(MassAction::class);

        $off = new ListingControlsGatePlugin($this->gridConfig(false));
        $this->assertFalse($off->afterIsActionAllowed($massAction, true, 'panth_mass_edit'));
        $this->assertTrue($off->afterIsActionAllowed($massAction, true, 'delete'));
        $this->assertFalse($off->afterIsActionAllowed($massAction, false, 'delete'));

        $on = new ListingControlsGatePlugin($this->gridConfig(true));
        $this->assertTrue($on->afterIsActionAllowed($massAction, true, 'panth_mass_edit'));
        $this->assertFalse($on->afterIsActionAllowed($massAction, false, 'panth_mass_edit'));
    }

    private function exportButton(string $namespace): ExportButton
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getNamespace')->willReturn($namespace);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        return new ExportButton(
            $context,
            $this->createStub(UrlInterface::class),
            [],
            ['name' => 'export_button', 'config' => ['options' => ['cvs' => ['value' => 'csv']]]]
        );
    }

    public function testExportButtonIsDisabledOnlyOnTheProductGridWhenOff(): void
    {
        $off = new ExportButtonGatePlugin($this->gridConfig(false));

        $product = $this->exportButton('product_listing');
        $this->assertNull($off->afterPrepare($product, null));
        $this->assertTrue($product->getData('config')['componentDisabled']);
        $this->assertSame(['cvs' => ['value' => 'csv']], $product->getData('config')['options']);

        $orders = $this->exportButton('sales_order_grid');
        $off->afterPrepare($orders, null);
        $this->assertArrayNotHasKey('componentDisabled', $orders->getData('config'));

        $on = new ExportButtonGatePlugin($this->gridConfig(true));
        $enabled = $this->exportButton('product_listing');
        $on->afterPrepare($enabled, null);
        $this->assertArrayNotHasKey('componentDisabled', $enabled->getData('config'));
    }

    public function testFilterAndSortPluginsStepAsideWhenDisabled(): void
    {
        $eav = $this->createMock(EavConfig::class);
        $eav->expects($this->never())->method('getAttribute');
        $filter = $this->filter('color', '5');
        $filter->setConditionType('like');

        $result = (new ProductDataProviderFilterPlugin($eav, $this->gridConfig(false)))
            ->beforeAddFilter($this->createStub(ProductDataProvider::class), $filter);

        $this->assertSame([$filter], $result);
        $this->assertSame('like', $filter->getConditionType());

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->never())->method('getSelect');
        $provider = $this->createStub(ProductDataProvider::class);
        $provider->method('getCollection')->willReturn($collection);
        $sort = new ProductDataProviderSortPlugin(
            new StockExpressions($this->createStub(ScopeConfigInterface::class)),
            $this->gridConfig(false)
        );

        $this->assertSame('core', $sort->aroundAddOrder($provider, static fn () => 'core', 'panth_qty_sold', 'desc'));
    }
}

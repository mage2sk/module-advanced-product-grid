<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit\Strategy;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\AvailabilityStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\BackordersStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\QtyStrategy;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockStrategiesTest extends TestCase
{
    use DbDoublesTrait;

    public static function availabilityProvider(): array
    {
        return [
            'in stock' => ['1', ['is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 0]],
            'out of stock' => [0, ['is_in_stock' => 0, 'manage_stock' => 1, 'use_config_manage_stock' => 0]],
            'manage disabled' => ['2', ['is_in_stock' => 1, 'manage_stock' => 0, 'use_config_manage_stock' => 0]],
        ];
    }

    #[DataProvider('availabilityProvider')]
    public function testAvailabilityMapsToStockPayloadAndKeepsExistingKeys(mixed $value, array $expected): void
    {
        $product = $this->product(['stock_data' => ['qty' => 7, 'is_in_stock' => 9]]);

        (new AvailabilityStrategy())->apply($product, 'panth_availability', $value);

        $stock = $product->getStockData();
        $this->assertSame(7, $stock['qty']);
        foreach ($expected as $key => $val) {
            $this->assertSame($val, $stock[$key], $key);
        }
    }

    public function testUnknownAvailabilityLeavesStockDataUntouched(): void
    {
        $product = $this->product(['stock_data' => ['qty' => 3]]);

        (new AvailabilityStrategy())->apply($product, 'panth_availability', '5');

        $this->assertSame(['qty' => 3], $product->getStockData());
    }

    public function testBackordersCastsValueAndDisablesConfigFallback(): void
    {
        $product = $this->product(['stock_data' => ['qty' => 1]]);

        (new BackordersStrategy())->apply($product, 'panth_backorders', '2');

        $this->assertSame(['qty' => 1, 'backorders' => 2, 'use_config_backorders' => 0], $product->getStockData());
    }

    public function testBackordersWorksWithoutExistingStockData(): void
    {
        $product = $this->product();

        (new BackordersStrategy())->apply($product, 'panth_backorders', 'abc');

        $this->assertSame(['backorders' => 0, 'use_config_backorders' => 0], $product->getStockData());
    }

    private function qtyStrategy(bool $qtyToStock): QtyStrategy
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn ($path) => $path === 'panth_product_grid/modification/qty_to_stock'
                ? ($qtyToStock ? '1' : '0')
                : null
        );
        return new QtyStrategy($config);
    }

    public function testQtyWritesQuantityWithoutTouchingStockStatusWhenOptionDisabled(): void
    {
        $product = $this->product(['stock_data' => ['is_in_stock' => 1]]);

        $this->qtyStrategy(false)->apply($product, 'qty', '12.5');

        $this->assertSame(['qty' => 12.5], $product->getQuantityAndStockStatus());
        $this->assertSame(['is_in_stock' => 1, 'qty' => 12.5], $product->getStockData());
    }

    public function testNonNumericQtyBecomesZero(): void
    {
        $product = $this->product();

        $this->qtyStrategy(false)->apply($product, 'qty', 'lots');

        $this->assertSame(['qty' => 0.0], $product->getQuantityAndStockStatus());
    }

    public function testPositiveQtyMarksProductInStockWhenOptionEnabled(): void
    {
        $product = $this->product();

        $this->qtyStrategy(true)->apply($product, 'qty', 4);

        $this->assertSame(
            ['qty' => 4.0, 'is_in_stock' => 1, 'use_config_manage_stock' => 0, 'manage_stock' => 1],
            $product->getStockData()
        );
        $this->assertSame(['qty' => 4.0, 'is_in_stock' => 1], $product->getQuantityAndStockStatus());
    }

    public function testZeroQtyMarksProductOutOfStockWhenOptionEnabled(): void
    {
        $product = $this->product(['stock_data' => ['is_in_stock' => 1]]);

        $this->qtyStrategy(true)->apply($product, 'qty', '0');

        $this->assertSame(0, $product->getStockData()['is_in_stock']);
        $this->assertSame(0, $product->getQuantityAndStockStatus()['is_in_stock']);
    }
}

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Panth\AdvancedProductGrid\Observer\ProductSaveAfter;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class ProductSaveAfterTest extends TestCase
{
    use DbDoublesTrait;

    private array $upserts = [];

    private function fire(mixed $product): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $data, $fields) {
            $this->upserts[] = [$table, $data, $fields];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $observer = new Observer();
        $observer->setEvent(new Event(['product' => $product]));
        (new ProductSaveAfter($resource))->execute($observer);
    }

    public function testNewProductGetsZeroQtySoldRow(): void
    {
        $this->fire($this->product(['entity_id' => 15, '_is_new' => true]));

        $this->assertSame(
            [['panth_product_grid_qty_sold', ['product_id' => 15, 'qty_sold' => 0], ['qty_sold']]],
            $this->upserts
        );
    }

    public function testExistingOrUnsavedProductsAreIgnored(): void
    {
        $this->fire(null);
        $this->fire($this->product(['entity_id' => 15, '_is_new' => false]));
        $this->fire($this->product(['entity_id' => null, '_is_new' => true]));

        $this->assertSame([], $this->upserts);
    }
}

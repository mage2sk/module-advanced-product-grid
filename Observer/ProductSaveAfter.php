<?php
/**
 * Seeds a zero qty_sold row for newly-created products so the data
 * provider doesn't have to handle the "no row" branch separately.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if ($product === null || !$product->getId() || !$product->isObjectNew()) {
            return;
        }
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_product_grid_qty_sold');
        $conn->insertOnDuplicate($table, [
            'product_id' => (int)$product->getId(),
            'qty_sold' => 0,
        ], ['qty_sold']);
    }
}

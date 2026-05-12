<?php
/**
 * Forces qty-sold MView subscriptions onto the sales DB connection.
 *
 * Magento's default Subscription only knows about the default connection;
 * when sales tables live on a split connection, triggers must be created
 * there or sales_order_item INSERTs/UPDATEs won't enqueue the indexer.
 *
 * No-op when the view doesn't belong to this module.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mview\View\Subscription;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddQtySoldFilterToCollection;

class SalesConnectionPlugin
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function beforeCreate(Subscription $subject)
    {
        try {
            $view = $subject->getView();
            $viewId = method_exists($view, 'getId') ? (string)$view->getId() : '';
        } catch (\Throwable) {
            return null;
        }
        if ($viewId !== 'panth_product_grid_qty_sold') {
            return null;
        }
        try {
            $salesConn = $this->resource->getConnectionByName('sales');
            if ($salesConn) {
                $subject->setData('connection', $salesConn);
            }
        } catch (\Throwable) {
            // sales is shared with default — nothing to switch.
        }
        return null;
    }
}

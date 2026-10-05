<?php
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
        }
        return null;
    }
}

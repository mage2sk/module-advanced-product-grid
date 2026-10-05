<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Sales\Api\Data\OrderInterface;
use Panth\AdvancedProductGrid\Model\Config\Backend\QtySoldInvalidate;

class OrderSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resource
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $productIds = [];
        foreach ($order->getItems() as $item) {
            if ($item->getProductId()) {
                $productIds[] = (int)$item->getProductId();
            }
        }
        if ($productIds === []) {
            return;
        }
        $ids = array_values(array_unique($productIds));
        $registry = $this->indexerRegistry;
        $runner = static function () use ($registry, $ids): void {
            $indexer = $registry->get(QtySoldInvalidate::INDEXER_ID);
            if (!$indexer->isScheduled()) {
                $indexer->reindexList($ids);
            }
        };

        if ($order instanceof \Magento\Framework\Model\AbstractModel
            && $this->resource->getConnection('sales')->getTransactionLevel() > 0
        ) {
            $order->getResource()->addCommitCallback($runner);
        } else {
            $runner();
        }
    }
}

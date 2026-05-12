<?php
/**
 * Marks the qty-sold indexer dirty for every product on a freshly-saved
 * order. The actual reindex runs either in-process (sync indexer) or
 * via mview cron (schedule indexer), whichever the admin configured.
 *
 * We schedule via addCommitCallback so the indexer doesn't see the
 * order until the surrounding transaction has actually committed.
 */
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
        $this->resource->getConnection()->getTransactionLevel() > 0
            ? $this->resource->getConnection()->addCommitCallback(static function () use ($registry, $ids) {
                $indexer = $registry->get(QtySoldInvalidate::INDEXER_ID);
                if ($indexer->isScheduled()) {
                    return;
                }
                $indexer->reindexList($ids);
            })
            : (function () use ($registry, $ids) {
                $indexer = $registry->get(QtySoldInvalidate::INDEXER_ID);
                if (!$indexer->isScheduled()) {
                    $indexer->reindexList($ids);
                }
            })();
    }
}

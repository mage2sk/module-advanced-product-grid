<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Panth\AdvancedProductGrid\Model\Config\Backend\QtySoldInvalidate;
use Panth\AdvancedProductGrid\Observer\OrderSaveAfter;
use PHPUnit\Framework\TestCase;

class OrderSaveAfterTest extends TestCase
{
    private IndexerRegistry $indexerRegistry;
    private IndexerInterface $indexer;
    private ResourceConnection $resource;
    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->indexerRegistry->method('get')
            ->with(QtySoldInvalidate::INDEXER_ID)
            ->willReturn($this->indexer);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getConnection')->with('sales')->willReturn($this->connection);
    }

    private function makeObserver(): OrderSaveAfter
    {
        return new OrderSaveAfter($this->indexerRegistry, $this->resource);
    }

    private function makeEvent(?Order $order): Observer
    {
        $event = new Event(['order' => $order]);
        $observer = new Observer();
        $observer->setEvent($event);
        return $observer;
    }

    private function makeOrder(array $productIds, ?OrderResource $orderResource = null): Order
    {
        $items = [];
        foreach ($productIds as $id) {
            $item = $this->createMock(Item::class);
            $item->method('getProductId')->willReturn($id);
            $items[] = $item;
        }
        $order = $this->createMock(Order::class);
        $order->method('getItems')->willReturn($items);
        if ($orderResource !== null) {
            $order->method('getResource')->willReturn($orderResource);
        }
        return $order;
    }

    public function testNoTransactionReindexesInlineWithDedupedIds(): void
    {
        $this->connection->method('getTransactionLevel')->willReturn(0);
        $this->indexer->method('isScheduled')->willReturn(false);
        $this->indexer->expects($this->once())->method('reindexList')->with([11, 22]);

        $this->makeObserver()->execute($this->makeEvent($this->makeOrder([11, 22, 11])));
    }

    public function testOpenTransactionDefersToResourceCommitCallbackNotAdapter(): void
    {
        // Note: AdapterInterface deliberately has no addCommitCallback() — any
        // attempt to register the callback on the adapter (the old bug) would
        // fatal here exactly as it did in production.
        $this->connection->method('getTransactionLevel')->willReturn(1);

        $captured = null;
        $orderResource = $this->createMock(OrderResource::class);
        $orderResource->expects($this->once())->method('addCommitCallback')
            ->willReturnCallback(function (callable $cb) use (&$captured, $orderResource) {
                $captured = $cb;
                return $orderResource;
            });

        $this->indexer->method('isScheduled')->willReturn(false);
        $this->indexer->expects($this->once())->method('reindexList')->with([5]);

        $this->makeObserver()->execute($this->makeEvent($this->makeOrder([5], $orderResource)));

        $this->assertNotNull($captured, 'callback must be registered on the order resource');
        $captured(); // simulate the transaction committing
    }

    public function testScheduledIndexerIsLeftToMview(): void
    {
        $this->connection->method('getTransactionLevel')->willReturn(0);
        $this->indexer->method('isScheduled')->willReturn(true);
        $this->indexer->expects($this->never())->method('reindexList');

        $this->makeObserver()->execute($this->makeEvent($this->makeOrder([7])));
    }

    public function testOrderWithoutProductIdsIsIgnored(): void
    {
        $this->indexerRegistry->expects($this->never())->method('get');

        $this->makeObserver()->execute($this->makeEvent($this->makeOrder([0])));
    }

    public function testMissingOrderIsIgnored(): void
    {
        $this->indexerRegistry->expects($this->never())->method('get');

        $this->makeObserver()->execute($this->makeEvent(null));
    }
}

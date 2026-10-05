<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\Config;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Panth\AdvancedProductGrid\Model\Config\Backend\QtySoldInvalidate;
use PHPUnit\Framework\TestCase;

class QtySoldInvalidateTest extends TestCase
{
    private array $requestedIndexers = [];
    private int $invalidations = 0;

    private function backend(string $stored, string $new): QtySoldInvalidate
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($stored);

        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('invalidate')->willReturnCallback(function () {
            $this->invalidations++;
        });
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(function ($id) use ($indexer) {
            $this->requestedIndexers[] = $id;
            return $indexer;
        });

        $backend = new QtySoldInvalidate(
            $context,
            $this->createStub(Registry::class),
            $config,
            $this->createStub(TypeListInterface::class),
            $registry
        );
        $backend->setPath('panth_product_grid/qty_sold/date_from');
        $backend->setValue($new);
        return $backend;
    }

    public function testChangedValueInvalidatesQtySoldIndexer(): void
    {
        $backend = $this->backend('-90 days', '-30 days');

        $this->assertSame($backend, $backend->afterSave());
        $this->assertSame([QtySoldInvalidate::INDEXER_ID], $this->requestedIndexers);
        $this->assertSame(1, $this->invalidations);
    }

    public function testUnchangedValueLeavesIndexerAlone(): void
    {
        $this->backend('-90 days', '-90 days')->afterSave();

        $this->assertSame([], $this->requestedIndexers);
        $this->assertSame(0, $this->invalidations);
    }
}

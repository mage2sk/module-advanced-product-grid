<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\Indexer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AdvancedProductGrid\Model\Indexer\QtySold;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class QtySoldTest extends TestCase
{
    use DbDoublesTrait;

    private array $config = [];
    private array $rows = [];
    private array $columns = [];
    private array $deletes = [];
    private array $truncates = [];
    private array $upserts = [];
    private int $fetches = 0;

    private function indexer(): QtySold
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function () {
            $select = $this->select();
            return $select;
        });
        $connection->method('fetchAll')->willReturnCallback(function () {
            $this->fetches++;
            return $this->rows;
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return 1;
        });
        $connection->method('truncateTable')->willReturnCallback(function ($table) use ($connection) {
            $this->truncates[] = $table;
            return $connection;
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $data, $fields) {
            $this->upserts[] = [$table, $data, $fields];
            return count($data);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->config[$path] ?? null);

        return new QtySold($resource, $scope);
    }

    private function whereValue(string $condition): mixed
    {
        foreach ($this->wheres as [$cond, $value]) {
            if ($cond === $condition) {
                return $value;
            }
        }
        $this->fail('Missing where condition ' . $condition);
    }

    public function testEmptyIdListsAreNoOps(): void
    {
        $indexer = $this->indexer();
        $indexer->executeList([]);
        $indexer->execute([]);
        $indexer->execute('5');

        $this->assertSame(0, $this->fetches);
    }

    public function testFullReindexTruncatesAndUpsertsClampedQuantities(): void
    {
        $this->rows = [
            ['product_id' => '5', 'qty_sold' => '12.0000'],
            ['product_id' => '6', 'qty_sold' => '-3'],
        ];

        $this->indexer()->executeFull();

        $this->assertSame(['panth_product_grid_qty_sold'], $this->truncates);
        $this->assertSame([], $this->deletes);
        $this->assertSame([[
            'panth_product_grid_qty_sold',
            [['product_id' => 5, 'qty_sold' => 12], ['product_id' => 6, 'qty_sold' => 0]],
            ['qty_sold'],
        ]], $this->upserts);
        $this->assertNotContains('soi.product_id IN (?)', $this->whereConditions());
    }

    public function testPartialReindexDeletesOnlyTheGivenProducts(): void
    {
        $this->indexer()->executeList(['7', 8]);

        $this->assertSame([['panth_product_grid_qty_sold', ['product_id IN (?)' => [7, 8]]]], $this->deletes);
        $this->assertSame([7, 8], $this->whereValue('soi.product_id IN (?)'));
        $this->assertSame([], $this->truncates);
        $this->assertSame([], $this->upserts, 'no rows means nothing to insert');
    }

    public function testExecuteRowAndMviewExecuteNarrowToIds(): void
    {
        $this->indexer()->executeRow('9');
        $this->assertSame([9], $this->whereValue('soi.product_id IN (?)'));

        $this->wheres = [];
        $this->indexer()->execute(['3', '4']);
        $this->assertSame([3, 4], $this->whereValue('soi.product_id IN (?)'));
    }

    public function testDefaultStatusesAndDateWindowAreApplied(): void
    {
        $this->indexer()->executeFull();

        $this->assertSame(['complete', 'processing'], $this->whereValue('so.status IN (?)'));
        $from = strtotime($this->whereValue('so.created_at >= ?') . ' UTC');
        $to = strtotime($this->whereValue('so.created_at <= ?') . ' UTC');
        $this->assertEqualsWithDelta(90 * 86400, $to - $from, 120);
    }

    public function testConfiguredStatusesAndDatesAreUsed(): void
    {
        $this->config = [
            'panth_product_grid/qty_sold/statuses' => ' complete , ,closed',
            'panth_product_grid/qty_sold/date_from' => '2024-01-01 00:00:00',
            'panth_product_grid/qty_sold/date_to' => 'not a date at all %%',
        ];

        $this->indexer()->executeFull();

        $this->assertSame(['complete', 'closed'], array_values($this->whereValue('so.status IN (?)')));
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime('2024-01-01 00:00:00')), $this->whereValue('so.created_at >= ?'));
        $this->assertEqualsWithDelta(time(), strtotime($this->whereValue('so.created_at <= ?') . ' UTC'), 120);
    }
}

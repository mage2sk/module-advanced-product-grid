<?php
/**
 * Aggregates ordered quantity per product into panth_product_grid_qty_sold.
 *
 * Three modes (Magento\Framework\Indexer\ActionInterface):
 *   - executeFull(): rebuild the whole table.
 *   - executeList(): rebuild rows for the supplied product IDs only.
 *   - executeRow(): single-product variant of executeList.
 *
 * Mview also calls execute() with the dirty product_ids from sales_order_item.
 *
 * The aggregation honors the configured date range, included statuses,
 * and the subtract-refunded toggle.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\Indexer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Zend_Db_Expr;

class QtySold implements ActionInterface, MviewActionInterface
{
    private const TABLE = 'panth_product_grid_qty_sold';
    private const CFG_FROM = 'panth_product_grid/qty_sold/date_from';
    private const CFG_TO = 'panth_product_grid/qty_sold/date_to';
    private const CFG_STATUSES = 'panth_product_grid/qty_sold/statuses';
    private const CFG_REFUND = 'panth_product_grid/qty_sold/include_refunded';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function executeFull(): void
    {
        $this->aggregate(null);
    }

    /**
     * @param int[] $ids
     */
    public function executeList(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->aggregate(array_map('intval', $ids));
    }

    /**
     * @param int $id
     */
    public function executeRow($id): void
    {
        $this->aggregate([(int)$id]);
    }

    /**
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        if (!is_array($ids) || $ids === []) {
            return;
        }
        $this->aggregate(array_map('intval', $ids));
    }

    /**
     * @param list<int>|null $productIds
     */
    private function aggregate(?array $productIds): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $orderTable = $this->resource->getTableName('sales_order');
        $itemTable = $this->resource->getTableName('sales_order_item');

        $statuses = array_filter(array_map('trim', explode(',', (string)$this->scopeConfig->getValue(self::CFG_STATUSES))));
        if ($statuses === []) {
            $statuses = ['complete', 'processing'];
        }
        $includeRefunded = (bool)$this->scopeConfig->getValue(self::CFG_REFUND);
        $from = $this->resolveDate((string)$this->scopeConfig->getValue(self::CFG_FROM) ?: '-90 days');
        $to = $this->resolveDate((string)$this->scopeConfig->getValue(self::CFG_TO) ?: 'now');

        $qtyExpr = $includeRefunded
            ? new Zend_Db_Expr('SUM(GREATEST(soi.qty_ordered - soi.qty_refunded, 0))')
            : new Zend_Db_Expr('SUM(soi.qty_ordered)');

        $select = $conn->select()
            ->from(['soi' => $itemTable], ['product_id' => 'soi.product_id', 'qty_sold' => $qtyExpr])
            ->joinInner(['so' => $orderTable], 'so.entity_id = soi.order_id', [])
            ->where('so.status IN (?)', $statuses)
            ->where('so.created_at >= ?', $from)
            ->where('so.created_at <= ?', $to)
            ->where('soi.product_id IS NOT NULL')
            ->group('soi.product_id');

        if ($productIds !== null) {
            $select->where('soi.product_id IN (?)', $productIds);
        }

        $rows = $conn->fetchAll($select);

        if ($productIds !== null) {
            $conn->delete($table, ['product_id IN (?)' => $productIds]);
        } else {
            $conn->truncateTable($table);
        }
        if ($rows === []) {
            return;
        }

        $upsert = [];
        foreach ($rows as $row) {
            $upsert[] = [
                'product_id' => (int)$row['product_id'],
                'qty_sold' => max(0, (int)$row['qty_sold']),
            ];
        }
        $conn->insertOnDuplicate($table, $upsert, ['qty_sold']);
    }

    private function resolveDate(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            $ts = time();
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}

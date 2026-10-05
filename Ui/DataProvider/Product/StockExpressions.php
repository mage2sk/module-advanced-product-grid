<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;

class StockExpressions
{
    public const STOCK_ALIAS = 'panth_stock';
    public const QTY_SOLD_ALIAS = 'panth_qs';

    private const CFG_MANAGE_STOCK = 'cataloginventory/item_options/manage_stock';
    private const CFG_BACKORDERS = 'cataloginventory/item_options/backorders';
    private const CFG_LOW_STOCK = 'panth_product_grid/columns/low_stock_threshold';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function joinStock(Collection $collection): void
    {
        $select = $collection->getSelect();
        if (array_key_exists(self::STOCK_ALIAS, $select->getPart(Select::FROM))) {
            return;
        }
        $select->joinLeft(
            [self::STOCK_ALIAS => $collection->getResource()->getTable('cataloginventory_stock_item')],
            self::STOCK_ALIAS . '.product_id = e.entity_id AND ' . self::STOCK_ALIAS . '.stock_id = 1',
            []
        );
    }

    public function joinQtySold(Collection $collection): void
    {
        $select = $collection->getSelect();
        if (array_key_exists(self::QTY_SOLD_ALIAS, $select->getPart(Select::FROM))) {
            return;
        }
        $select->joinLeft(
            [self::QTY_SOLD_ALIAS => $collection->getResource()->getTable(AddQtySoldFilterToCollection::TABLE)],
            self::QTY_SOLD_ALIAS . '.product_id = e.entity_id',
            []
        );
    }

    public function configManageStock(): int
    {
        return (int)$this->scopeConfig->getValue(self::CFG_MANAGE_STOCK) === 1 ? 1 : 0;
    }

    public function configBackorders(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::CFG_BACKORDERS));
    }

    public function lowStockThreshold(): int
    {
        return (int)$this->scopeConfig->getValue(self::CFG_LOW_STOCK);
    }

    public function manageStockExpression(): string
    {
        $a = self::STOCK_ALIAS;
        return '(CASE WHEN ' . $a . '.use_config_manage_stock = 1 THEN ' . $this->configManageStock()
            . ' ELSE ' . $a . '.manage_stock END)';
    }

    public function availabilityExpression(): string
    {
        $a = self::STOCK_ALIAS;
        return '(CASE WHEN ' . $this->manageStockExpression() . ' = 0 THEN 2 WHEN '
            . $a . '.is_in_stock = 1 THEN 1 ELSE 0 END)';
    }

    public function backordersExpression(): string
    {
        $a = self::STOCK_ALIAS;
        return '(CASE WHEN ' . $a . '.use_config_backorders = 1 THEN ' . $this->configBackorders()
            . ' ELSE ' . $a . '.backorders END)';
    }

    public function lowStockExpression(): string
    {
        $a = self::STOCK_ALIAS;
        return '(CASE WHEN ' . $this->manageStockExpression() . ' = 1 AND COALESCE(' . $a . '.qty, 0) <= '
            . $this->lowStockThreshold() . ' THEN 1 ELSE 0 END)';
    }

    public function qtySoldExpression(): string
    {
        return 'COALESCE(' . self::QTY_SOLD_ALIAS . '.qty_sold, 0)';
    }
}

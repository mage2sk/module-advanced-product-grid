<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Framework\DB\Select;
use Panth\AdvancedProductGrid\Model\GridConfig;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\StockExpressions;

class ProductDataProviderSortPlugin
{
    private const STOCK_FIELDS = ['panth_availability', 'panth_backorders', 'panth_low_stock'];

    public function __construct(
        private readonly StockExpressions $stockExpressions,
        private readonly ?GridConfig $gridConfig = null
    ) {
    }

    public function aroundAddOrder(
        ProductDataProvider $subject,
        callable $proceed,
        $field,
        $direction = Select::SQL_ASC
    ) {
        if ($this->gridConfig !== null && !$this->gridConfig->isEnabled()) {
            return $proceed($field, $direction);
        }
        $expression = $this->resolveExpression((string)$field, $subject);
        if ($expression === null) {
            return $proceed($field, $direction);
        }
        $dir = strtoupper((string)$direction) === Select::SQL_DESC ? Select::SQL_DESC : Select::SQL_ASC;
        $subject->getCollection()->getSelect()->order(new \Zend_Db_Expr($expression . ' ' . $dir));
        return null;
    }

    private function resolveExpression(string $field, ProductDataProvider $subject): ?string
    {
        if ($field !== 'panth_qty_sold' && !in_array($field, self::STOCK_FIELDS, true)) {
            return null;
        }
        $collection = $subject->getCollection();
        if (!$collection instanceof Collection) {
            return null;
        }
        if ($field === 'panth_qty_sold') {
            $this->stockExpressions->joinQtySold($collection);
            return $this->stockExpressions->qtySoldExpression();
        }
        $this->stockExpressions->joinStock($collection);
        return match ($field) {
            'panth_availability' => $this->stockExpressions->availabilityExpression(),
            'panth_backorders' => $this->stockExpressions->backordersExpression(),
            default => $this->stockExpressions->lowStockExpression(),
        };
    }
}

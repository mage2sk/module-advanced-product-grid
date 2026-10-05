<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;

class AddBackordersFilterToCollection implements AddFilterToCollectionInterface
{
    public function __construct(
        private readonly StockExpressions $stockExpressions
    ) {
    }

    public function addFilter(DataCollection $collection, $field, $condition = null): void
    {
        if (!$collection instanceof Collection) {
            return;
        }
        if ($condition instanceof Filter) {
            $condition = ['eq' => $condition->getValue()];
        }
        $value = (string)(is_array($condition) ? array_values($condition)[0] : $condition);
        if (!in_array($value, ['0', '1', '2'], true)) {
            return;
        }

        $this->stockExpressions->joinStock($collection);
        $collection->getSelect()->where($this->stockExpressions->backordersExpression() . ' = ?', (int)$value);
    }
}

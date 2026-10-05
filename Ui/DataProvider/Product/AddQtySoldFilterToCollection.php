<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\DB\Select;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;

class AddQtySoldFilterToCollection implements AddFilterToCollectionInterface
{
    public const TABLE = 'panth_product_grid_qty_sold';

    private const OPERATORS = [
        'from' => '>=',
        'gteq' => '>=',
        'to' => '<=',
        'lteq' => '<=',
        'eq' => '=',
    ];

    public function addFilter(DataCollection $collection, $field, $condition = null): void
    {
        if (!$collection instanceof Collection) {
            return;
        }
        if ($condition instanceof Filter) {
            $condition = ['eq' => $condition->getValue()];
        }
        if (!is_array($condition)) {
            $condition = ['eq' => $condition];
        }

        $select = $collection->getSelect();
        if (!array_key_exists('panth_qs', $select->getPart(Select::FROM))) {
            $select->joinLeft(
                ['panth_qs' => $collection->getResource()->getTable(self::TABLE)],
                'panth_qs.product_id = e.entity_id',
                []
            );
        }

        $sold = 'COALESCE(panth_qs.qty_sold, 0)';
        foreach (self::OPERATORS as $key => $operator) {
            if (!isset($condition[$key]) || $condition[$key] === '' || !is_numeric($condition[$key])) {
                continue;
            }
            $select->where($sold . ' ' . $operator . ' ?', (int)$condition[$key]);
        }
    }
}

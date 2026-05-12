<?php
/**
 * Range filter strategy for the qty_sold column. Joins the indexer
 * table only when the filter is active so non-filtered pageloads
 * stay cheap.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;

class AddQtySoldFilterToCollection implements AddFilterToCollectionInterface
{
    public const TABLE = 'panth_product_grid_qty_sold';

    /**
     * @param DataCollection|Collection $collection
     */
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

        $table = $collection->getResource()->getTable(self::TABLE);
        $collection->getSelect()->joinLeft(
            ['panth_qs' => $table],
            'panth_qs.product_id = e.entity_id',
            []
        );

        $sold = 'COALESCE(panth_qs.qty_sold, 0)';
        if (isset($condition['from']) && $condition['from'] !== '') {
            $collection->getSelect()->where("$sold >= ?", (int)$condition['from']);
        }
        if (isset($condition['to']) && $condition['to'] !== '') {
            $collection->getSelect()->where("$sold <= ?", (int)$condition['to']);
        }
        if (isset($condition['eq']) && $condition['eq'] !== '') {
            $collection->getSelect()->where("$sold = ?", (int)$condition['eq']);
        }
    }
}

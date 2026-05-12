<?php
/**
 * Availability filter strategy that respects Manage Stock:
 *   - In Stock      → manage_stock=1 AND is_in_stock=1
 *   - Out of Stock  → manage_stock=1 AND is_in_stock=0
 *   - Manage Off    → manage_stock=0 (the third UI option)
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\AvailabilityOptions;

class AddAvailabilityFilterToCollection implements AddFilterToCollectionInterface
{
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
        $value = (string)(is_array($condition) ? array_values($condition)[0] : $condition);

        $stockTable = $collection->getResource()->getTable('cataloginventory_stock_item');
        $collection->getSelect()->joinLeft(
            ['panth_stock' => $stockTable],
            'panth_stock.product_id = e.entity_id AND panth_stock.stock_id = 1',
            []
        );

        $manageStockExpr = '(CASE WHEN panth_stock.use_config_manage_stock = 1 THEN 1 ELSE panth_stock.manage_stock END)';

        switch ($value) {
            case AvailabilityOptions::IN_STOCK:
                $collection->getSelect()->where("$manageStockExpr = 1 AND panth_stock.is_in_stock = 1");
                break;
            case AvailabilityOptions::OUT_OF_STOCK:
                $collection->getSelect()->where("$manageStockExpr = 1 AND panth_stock.is_in_stock = 0");
                break;
            case AvailabilityOptions::MANAGE_STOCK_DISABLED:
                $collection->getSelect()->where("$manageStockExpr = 0");
                break;
        }
    }
}

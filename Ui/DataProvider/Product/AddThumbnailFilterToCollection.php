<?php
/**
 * "Has image / no image" filter strategy. Compares the product
 * thumbnail attribute value against 'no_selection' (Magento's
 * sentinel for "no image").
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;

class AddThumbnailFilterToCollection implements AddFilterToCollectionInterface
{
    public const ADDED = '1';
    public const NOT_ADDED = '0';

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

        $collection->addAttributeToSelect('thumbnail');
        $collection->joinAttribute('panth_thumb_value', 'catalog_product/thumbnail', 'entity_id', null, 'left');

        if ($value === self::ADDED) {
            $collection->getSelect()->where("panth_thumb_value IS NOT NULL AND panth_thumb_value != 'no_selection' AND panth_thumb_value != ''");
        } elseif ($value === self::NOT_ADDED) {
            $collection->getSelect()->where("panth_thumb_value IS NULL OR panth_thumb_value = 'no_selection' OR panth_thumb_value = ''");
        }
    }
}

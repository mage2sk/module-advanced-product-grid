<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\DataProvider\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;
use Magento\Ui\DataProvider\AddFilterToCollectionInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Api\Filter;

class AddCategoryFilterToCollection implements AddFilterToCollectionInterface
{
    public const ATTRIBUTE_CODE = 'panth_categories';
    public const NO_CATEGORY = '_none_';

    public function addFilter(DataCollection $collection, $field, $condition = null): void
    {
        if (!$collection instanceof Collection) {
            return;
        }
        if ($condition instanceof Filter) {
            $condition = ['eq' => $condition->getValue()];
        }
        $value = is_array($condition) ? array_values($condition)[0] : $condition;
        $values = is_array($value) ? $value : [$value];

        $select = $collection->getSelect();
        if (array_key_exists('panth_cat', $select->getPart(Select::FROM))) {
            return;
        }
        if (in_array(self::NO_CATEGORY, $values, true)) {
            $select->joinLeft(
                ['panth_cat' => $collection->getResource()->getTable('catalog_category_product')],
                'panth_cat.product_id = e.entity_id',
                []
            );
            $select->where('panth_cat.category_id IS NULL');
            return;
        }

        $ids = array_filter(array_map('intval', $values));
        if ($ids === []) {
            return;
        }
        $select->joinInner(
            ['panth_cat' => $collection->getResource()->getTable('catalog_category_product')],
            'panth_cat.product_id = e.entity_id',
            []
        );
        $select->where('panth_cat.category_id IN (?)', $ids);
        $select->group('e.entity_id');
    }
}

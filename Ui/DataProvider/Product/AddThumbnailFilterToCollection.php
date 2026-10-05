<?php
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

    public function addFilter(DataCollection $collection, $field, $condition = null): void
    {
        if (!$collection instanceof Collection) {
            return;
        }
        if ($condition instanceof Filter) {
            $condition = ['eq' => $condition->getValue()];
        }
        $value = is_array($condition) ? array_values($condition)[0] : $condition;
        $value = is_scalar($value) ? trim((string)$value, '% ') : '';

        if ($value === self::ADDED) {
            $collection->addAttributeToFilter('thumbnail', ['nin' => ['no_selection', '']]);
        } elseif ($value === self::NOT_ADDED) {
            $collection->addAttributeToFilter(
                'thumbnail',
                [['null' => true], ['in' => ['no_selection', '']]],
                'left'
            );
        }
    }
}

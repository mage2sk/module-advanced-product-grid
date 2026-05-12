<?php
/**
 * Backorders dropdown — matches Magento's CatalogInventory backorder constants.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;

class BackorderOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '0', 'label' => __('No Backorders')],
            ['value' => '1', 'label' => __('Allow Qty Below 0')],
            ['value' => '2', 'label' => __('Allow Qty Below 0 and Notify Customer')],
        ];
    }
}

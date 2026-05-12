<?php
/**
 * Three-state availability dropdown for the inline editor.
 * The third option ("manage stock disabled") only appears in the
 * filter/dropdown; in the data provider, this maps to manage_stock=0.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;

class AvailabilityOptions implements OptionSourceInterface
{
    public const IN_STOCK = '1';
    public const OUT_OF_STOCK = '0';
    public const MANAGE_STOCK_DISABLED = '2';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::IN_STOCK, 'label' => __('In Stock')],
            ['value' => self::OUT_OF_STOCK, 'label' => __('Out of Stock')],
            ['value' => self::MANAGE_STOCK_DISABLED, 'label' => __('Manage Stock Disabled')],
        ];
    }
}

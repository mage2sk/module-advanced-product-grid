<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MarkerColor implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('None')],
            ['value' => 'red', 'label' => __('Red')],
            ['value' => 'yellow', 'label' => __('Yellow')],
            ['value' => 'green', 'label' => __('Green')],
            ['value' => 'blue', 'label' => __('Blue')],
            ['value' => 'grey', 'label' => __('Grey')],
        ];
    }
}

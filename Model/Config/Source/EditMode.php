<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class EditMode implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'single', 'label' => __('Single Cell (save on blur)')],
            ['value' => 'multi', 'label' => __('Multi Cell (Save / Cancel banner)')],
        ];
    }
}

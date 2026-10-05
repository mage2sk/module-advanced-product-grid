<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Ui\Component;

use Magento\Catalog\Ui\Component\Product\MassAction;
use Panth\AdvancedProductGrid\Model\GridConfig;

class ListingControlsGatePlugin
{
    public const MASS_EDIT_TYPE = 'panth_mass_edit';

    public function __construct(
        private readonly GridConfig $gridConfig
    ) {
    }

    public function afterIsActionAllowed(MassAction $subject, bool $result, $actionType): bool
    {
        if ($result && (string)$actionType === self::MASS_EDIT_TYPE) {
            return $this->gridConfig->isEnabled();
        }
        return $result;
    }
}

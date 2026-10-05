<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Ui\Component;

use Magento\Ui\Component\ExportButton;
use Panth\AdvancedProductGrid\Model\GridConfig;

class ExportButtonGatePlugin
{
    public function __construct(
        private readonly GridConfig $gridConfig
    ) {
    }

    public function afterPrepare(ExportButton $subject, $result = null)
    {
        if ($this->gridConfig->isEnabled()) {
            return $result;
        }
        try {
            $namespace = (string)$subject->getContext()->getNamespace();
        } catch (\Throwable) {
            return $result;
        }
        if ($namespace !== GridConfig::LISTING_NAMESPACE) {
            return $result;
        }
        $config = (array)$subject->getData('config');
        $config['componentDisabled'] = true;
        $subject->setData('config', $config);
        return $result;
    }
}

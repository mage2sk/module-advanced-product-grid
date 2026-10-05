<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class GridConfig
{
    public const XML_PATH_ENABLED = 'panth_product_grid/general/enabled';
    public const LISTING_NAMESPACE = 'product_listing';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }
}

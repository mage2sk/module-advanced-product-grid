<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::inline_edit';

    public const CATALOG_RESOURCE = 'Magento_Catalog::products';

    private const RESTRICTED_CODES = [
        'tier_price' => 'Panth_AdvancedProductGrid::tier_price',
        'panth_tier_price_label' => 'Panth_AdvancedProductGrid::tier_price',
        'image' => 'Panth_AdvancedProductGrid::manage_gallery',
        'small_image' => 'Panth_AdvancedProductGrid::manage_gallery',
        'thumbnail' => 'Panth_AdvancedProductGrid::manage_gallery',
        'swatch_image' => 'Panth_AdvancedProductGrid::manage_gallery',
        'panth_thumbnail' => 'Panth_AdvancedProductGrid::manage_gallery',
    ];

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE)
            && $this->_authorization->isAllowed(self::CATALOG_RESOURCE);
    }

    protected function findDeniedCodes(array $codes): array
    {
        $denied = [];
        foreach ($codes as $code) {
            $code = (string)$code;
            $resource = self::RESTRICTED_CODES[$code] ?? null;
            if ($resource !== null && !$this->_authorization->isAllowed($resource)) {
                $denied[$code] = $code;
            }
        }
        return array_values($denied);
    }
}

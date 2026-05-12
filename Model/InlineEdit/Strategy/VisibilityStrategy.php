<?php
/**
 * Visibility-flag inline edit needs to mark URL rewrites for regen so
 * a hidden product no longer leaves an indexable URL behind.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Visibility;

class VisibilityStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $allowed = [Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH];
        $intValue = (int)$value;
        if (!in_array($intValue, $allowed, true)) {
            $intValue = Visibility::VISIBILITY_BOTH;
        }
        $product->setVisibility($intValue);
        $product->setData('panth_request_url_rewrite_refresh', true);
    }
}

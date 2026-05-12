<?php
/**
 * Default strategy — just setData(). Used for all attributes that
 * don't need any special routing.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

class GenericAttributeStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        if (is_string($value) && $value === '__use_default__') {
            $product->setData($attributeCode, null);
            return;
        }
        // Sentinel sent by the JS popup when the admin clears every
        // checkbox of a multiselect / radio — we explicitly write null
        // so the EAV value row is removed instead of leaving stale data.
        if ($value === '__empty__' || $value === []) {
            $product->setData($attributeCode, null);
            return;
        }
        $product->setData($attributeCode, $value);
    }
}

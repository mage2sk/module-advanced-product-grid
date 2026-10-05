<?php
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

        if ($value === '__empty__' || $value === []) {
            $product->setData($attributeCode, null);
            return;
        }
        $product->setData($attributeCode, $value);
    }
}

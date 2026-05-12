<?php
/**
 * Weight needs the type-transition helper so simple/virtual products
 * flip product_has_weight correctly when weight is set/cleared.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

class WeightStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $product->setWeight($value === '' || $value === null ? null : (float)$value);
        $product->setData('product_has_weight', ($value === '' || $value === null) ? 0 : 1);
    }
}

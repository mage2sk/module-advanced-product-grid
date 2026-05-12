<?php
/**
 * Contract for per-attribute save strategies used by InlineEdit.
 *
 * A strategy is the thing that knows how to translate one
 * "set product.X = Y" instruction from the JS editor into one or
 * more setters on the Magento product model. Keeping these as
 * separate classes (rather than a giant switch in the controller)
 * keeps each branch testable in isolation.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

interface StrategyInterface
{
    /**
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param mixed $value
     */
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void;
}

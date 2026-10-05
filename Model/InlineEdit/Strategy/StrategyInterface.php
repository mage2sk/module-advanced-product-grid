<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

interface StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void;
}

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

class BackordersStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $product->setStockData(array_merge((array)$product->getStockData(), [
            'backorders' => (int)$value,
            'use_config_backorders' => 0,
        ]));
    }
}

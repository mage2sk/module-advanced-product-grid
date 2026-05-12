<?php
/**
 * Maps the three-state availability dropdown back to manage_stock +
 * is_in_stock + use_config_manage_stock combinations.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\AvailabilityOptions;

class AvailabilityStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $payload = match ((string)$value) {
            AvailabilityOptions::IN_STOCK => ['is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 0],
            AvailabilityOptions::OUT_OF_STOCK => ['is_in_stock' => 0, 'manage_stock' => 1, 'use_config_manage_stock' => 0],
            AvailabilityOptions::MANAGE_STOCK_DISABLED => ['manage_stock' => 0, 'use_config_manage_stock' => 0, 'is_in_stock' => 1],
            default => null,
        };
        if ($payload === null) {
            return;
        }
        $product->setStockData(array_merge((array)$product->getStockData(), $payload));
    }
}

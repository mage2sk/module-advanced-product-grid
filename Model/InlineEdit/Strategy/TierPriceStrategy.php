<?php
/**
 * Saves tier prices straight onto the product. The JS modal sends a
 * list of rows shaped exactly the way Magento's product model expects:
 *   [{website_id, customer_group_id, qty, value, value_type, percentage_value}, ...]
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

class TierPriceStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        if (!is_array($value)) {
            $decoded = is_string($value) ? json_decode($value, true) : null;
            $value = is_array($decoded) ? $decoded : [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = ($row['value_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
            $rows[] = [
                'website_id' => (int)($row['website_id'] ?? 0),
                'cust_group' => (int)($row['cust_group'] ?? $row['customer_group_id'] ?? 0),
                'customer_group_id' => (int)($row['cust_group'] ?? $row['customer_group_id'] ?? 0),
                'price_qty' => (float)($row['qty'] ?? $row['price_qty'] ?? 1),
                'qty' => (float)($row['qty'] ?? $row['price_qty'] ?? 1),
                'value_type' => $type,
                'price' => (float)($row['price'] ?? $row['value'] ?? 0),
                'value' => (float)($row['price'] ?? $row['value'] ?? 0),
                'percentage_value' => $type === 'percent' ? (float)($row['percentage_value'] ?? $row['price'] ?? 0) : null,
            ];
        }
        $product->setTierPrice($rows);
    }
}

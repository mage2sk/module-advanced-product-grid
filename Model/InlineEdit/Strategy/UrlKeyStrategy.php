<?php
/**
 * Changing url_key triggers a URL rewrite refresh; the controller picks
 * up the flag after save.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;

class UrlKeyStrategy implements StrategyInterface
{
    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $product->setUrlKey($value === null ? '' : (string)$value);
        $product->setData('panth_request_url_rewrite_refresh', true);
    }
}

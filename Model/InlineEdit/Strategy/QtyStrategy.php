<?php
/**
 * Setting qty inline means updating the stock item, not the product
 * EAV table. When configured to auto-flip stock, qty=0 also flips
 * is_in_stock to false (and the reverse for qty>0). This mirrors what
 * Magento's standard "Source Selection" backoffice flow does.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class QtyStrategy implements StrategyInterface
{
    private const CFG_QTY_TO_STOCK = 'panth_product_grid/modification/qty_to_stock';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $qty = is_numeric($value) ? (float)$value : 0.0;
        $product->setQuantityAndStockStatus(['qty' => $qty]);
        $product->setStockData(array_merge(
            (array)$product->getStockData(),
            ['qty' => $qty]
        ));
        if ((bool)$this->scopeConfig->getValue(self::CFG_QTY_TO_STOCK)) {
            $inStock = $qty > 0 ? 1 : 0;
            $product->setStockData(array_merge(
                (array)$product->getStockData(),
                ['is_in_stock' => $inStock, 'use_config_manage_stock' => 0, 'manage_stock' => 1]
            ));
            $product->setQuantityAndStockStatus(array_merge(
                (array)$product->getQuantityAndStockStatus(),
                ['is_in_stock' => $inStock]
            ));
        }
    }
}

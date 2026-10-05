<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider;

use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Framework\Api\Filter;
use Panth\AdvancedProductGrid\Model\GridConfig;

class ProductDataProviderGatePlugin
{
    public function __construct(
        private readonly GridConfig $gridConfig
    ) {
    }

    public function aroundAddFilter(ProductDataProvider $subject, callable $proceed, Filter $filter)
    {
        if ($this->isModuleField((string)$filter->getField()) && !$this->gridConfig->isEnabled()) {
            return null;
        }
        return $proceed($filter);
    }

    public function aroundAddOrder(ProductDataProvider $subject, callable $proceed, $field, $direction = 'ASC')
    {
        if ($this->isModuleField((string)$field) && !$this->gridConfig->isEnabled()) {
            return null;
        }
        return $proceed($field, $direction);
    }

    private function isModuleField(string $field): bool
    {
        return str_starts_with($field, 'panth_');
    }
}

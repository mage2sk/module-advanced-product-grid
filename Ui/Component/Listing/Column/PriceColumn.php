<?php
/**
 * Wraps Magento's listing price column to also expose the raw numeric
 * value via a sibling field so the inline editor doesn't have to parse
 * the formatted string back to a number on save.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing\Column;

use Magento\Catalog\Ui\Component\Listing\Columns\Price;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\PriceCurrency;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;

class PriceColumn extends Price
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        CurrencyInterface $localeCurrency,
        StoreManagerInterface $storeManager,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $localeCurrency, $storeManager, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        $fieldName = $this->getData('name');
        if (isset($dataSource['data']['items']) && is_array($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$row) {
                if (!isset($row[$fieldName])) {
                    continue;
                }
                $row[$fieldName . '_raw'] = (string)$row[$fieldName];
            }
            unset($row);
        }
        return parent::prepareDataSource($dataSource);
    }
}

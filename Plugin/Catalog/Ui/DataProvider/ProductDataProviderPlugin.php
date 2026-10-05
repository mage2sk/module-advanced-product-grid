<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\AvailabilityOptions;

class ProductDataProviderPlugin
{
    private const CFG_LOW_STOCK = 'panth_product_grid/columns/low_stock_threshold';
    private const CFG_QTY_INT = 'panth_product_grid/modification/show_qty_integer';
    private const CFG_LINKED_QTY = 'panth_product_grid/columns/linked_products_qty';
    private const CFG_MANAGE_STOCK = 'cataloginventory/item_options/manage_stock';
    private const CFG_BACKORDERS = 'cataloginventory/item_options/backorders';

    private const FORCE_SELECT_ATTRIBUTES = [
        'cost', 'special_price', 'special_from_date', 'special_to_date',
        'weight', 'meta_title', 'meta_description', 'meta_keyword',
        'url_key', 'description', 'short_description',
        'tax_class_id', 'manufacturer', 'country_of_manufacture',
        'news_from_date', 'news_to_date',
        'msrp', 'msrp_display_actual_price_type',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ImageHelper $imageHelper,
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly \Panth\AdvancedProductGrid\Model\ColumnConfigRepository $columnConfigRepository,
        private readonly \Magento\Eav\Model\Config $eavConfig,
        private readonly \Magento\Ui\Api\BookmarkManagementInterface $bookmarkManagement,
        private readonly ?\Panth\AdvancedProductGrid\Model\GridConfig $gridConfig = null
    ) {
    }

    public function beforeGetData(ProductDataProvider $subject): array
    {
        if ($this->gridConfig !== null && !$this->gridConfig->isEnabled()) {
            return [];
        }
        $codes = array_unique(array_merge(
            self::FORCE_SELECT_ATTRIBUTES,
            array_keys($this->columnConfigRepository->getAll()),
            $this->collectBookmarkVisibleColumns()
        ));
        try {
            $collection = $subject->getCollection();
            foreach ($codes as $attr) {
                $attr = (string)$attr;
                if ($attr === '' || str_starts_with($attr, 'panth_')) {
                    continue;
                }
                try {
                    $collection->addAttributeToSelect($attr, 'left');
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
        return [];
    }

    private function collectBookmarkVisibleColumns(): array
    {
        $out = [];
        try {
            $bookmarks = $this->bookmarkManagement->loadByNamespace('product_listing');
            foreach ($bookmarks->getItems() as $bookmark) {
                $config = $bookmark->getConfig() ?? [];
                $stack = [[$config]];
                while ($stack) {
                    $node = array_pop($stack);
                    foreach ($node as $key => $value) {
                        if (!is_array($value)) {
                            continue;
                        }
                        if ($key === 'columns') {
                            foreach ($value as $code => $entry) {
                                if (is_array($entry) && !empty($entry['visible'])) {
                                    $out[] = (string)$code;
                                }
                            }
                        } else {
                            $stack[] = $value;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }
        return array_values(array_unique($out));
    }

    public function afterGetData(ProductDataProvider $subject, $result): array
    {
        if ($this->gridConfig !== null && !$this->gridConfig->isEnabled()) {
            return is_array($result) ? $result : [];
        }
        if (!is_array($result) || empty($result['items']) || !is_array($result['items'])) {
            return is_array($result) ? $result : [];
        }

        $ids = [];
        foreach ($result['items'] as $row) {
            if (isset($row['entity_id'])) {
                $ids[] = (int)$row['entity_id'];
            }
        }
        if ($ids === []) {
            return $result;
        }

        $categoryMap = $this->loadCategoryPaths($ids);
        $qtySoldMap = $this->loadQtySold($ids);
        $tierMap = $this->loadTierPriceCounts($ids);
        $stockMap = $this->loadStockData($ids);
        $linkedQty = max(1, (int)$this->scopeConfig->getValue(self::CFG_LINKED_QTY));
        $lowStockThreshold = (int)$this->scopeConfig->getValue(self::CFG_LOW_STOCK);
        $showQtyInt = (bool)$this->scopeConfig->getValue(self::CFG_QTY_INT);
        $configManageStock = (int)$this->scopeConfig->getValue(self::CFG_MANAGE_STOCK) === 1 ? 1 : 0;
        $configBackorders = max(0, (int)$this->scopeConfig->getValue(self::CFG_BACKORDERS));

        foreach ($result['items'] as &$row) {
            $id = (int)($row['entity_id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $row['panth_categories'] = $categoryMap[$id]['ids'] ?? [];
            $labels = isset($categoryMap[$id]['label']) && $categoryMap[$id]['label'] !== ''
                ? array_filter(array_map('trim', explode(',', $categoryMap[$id]['label'])))
                : [];

            if ($labels !== []) {
                $chips = [];
                foreach ($labels as $label) {
                    $chips[] = '<span style="display:inline-block;padding:2px 8px;margin:1px 2px;'
                        . 'background:#eef5fa;color:#1979c3;border-radius:10px;'
                        . 'font-size:11px;white-space:nowrap;">'
                        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                        . '</span>';
                }
                $row['panth_categories_label'] = implode('', $chips);
            } else {
                $row['panth_categories_label'] = '';
            }

            $row['panth_categories_text'] = $categoryMap[$id]['label'] ?? '';
            $row['panth_qty_sold'] = $qtySoldMap[$id] ?? 0;

            $stock = $stockMap[$id] ?? null;
            if ($stock !== null) {
                $manage = ($stock['use_config_manage_stock'] ? $configManageStock : (int)$stock['manage_stock']);
                if ($manage === 0) {
                    $row['panth_availability'] = AvailabilityOptions::MANAGE_STOCK_DISABLED;
                } else {
                    $row['panth_availability'] = $stock['is_in_stock'] ? AvailabilityOptions::IN_STOCK : AvailabilityOptions::OUT_OF_STOCK;
                }
                $row['panth_backorders'] = !empty($stock['use_config_backorders'])
                    ? (string)$configBackorders
                    : (string)$stock['backorders'];
                $qty = (float)$stock['qty'];
                $row['panth_low_stock'] = ($manage === 1 && $qty <= $lowStockThreshold) ? 1 : 0;
                $row['qty'] = $showQtyInt ? (string)(int)$qty : $qty;
            }

            $row['panth_tier_price_count'] = $tierMap[$id] ?? 0;
            $row['panth_tier_price_label'] = isset($tierMap[$id]) && $tierMap[$id] > 0
                ? __('%1 tiers', $tierMap[$id])->render()
                : (string)__('Add');

            $row['panth_storefront_url'] = $this->buildStorefrontUrl($row);
            $row['panth_linked_products_max'] = $linkedQty;
            $this->fixCommaValuesForSelectColumns($row);
        }
        unset($row);

        return $result;
    }

    private function fixCommaValuesForSelectColumns(array &$row): void
    {
    }

    private function loadCategoryPaths(array $ids): array
    {
        $conn = $this->resource->getConnection();
        $cpTable = $this->resource->getTableName('catalog_category_product');
        $eav = $this->resource->getTableName('catalog_category_entity_varchar');
        $attr = $this->resource->getTableName('eav_attribute');

        $select = $conn->select()
            ->from(['ccp' => $cpTable], ['ccp.product_id', 'ccp.category_id'])
            ->joinLeft(['ea' => $attr], "ea.attribute_code = 'name' AND ea.entity_type_id = 3", [])
            ->joinLeft(
                ['cev' => $eav],
                'cev.entity_id = ccp.category_id AND cev.attribute_id = ea.attribute_id AND cev.store_id = 0',
                ['name' => 'cev.value']
            )
            ->where('ccp.product_id IN (?)', $ids);

        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $pid = (int)$row['product_id'];
            $out[$pid]['ids'][] = (int)$row['category_id'];
            if (!empty($row['name'])) {
                $out[$pid]['labels'][] = (string)$row['name'];
            }
        }
        foreach ($out as $pid => &$entry) {
            $entry['ids'] = array_values(array_unique($entry['ids']));
            $entry['label'] = isset($entry['labels']) ? implode(', ', array_unique($entry['labels'])) : '';
            unset($entry['labels']);
        }
        return $out;
    }

    private function loadQtySold(array $ids): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_product_grid_qty_sold');
        $select = $conn->select()
            ->from($table, ['product_id', 'qty_sold'])
            ->where('product_id IN (?)', $ids);
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $out[(int)$row['product_id']] = (int)$row['qty_sold'];
        }
        return $out;
    }

    private function loadTierPriceCounts(array $ids): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity_tier_price');
        $select = $conn->select()
            ->from($table, ['entity_id', new \Zend_Db_Expr('COUNT(*) as cnt')])
            ->where('entity_id IN (?)', $ids)
            ->group('entity_id');
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $out[(int)$row['entity_id']] = (int)$row['cnt'];
        }
        return $out;
    }

    private function loadStockData(array $ids): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('cataloginventory_stock_item');
        $select = $conn->select()
            ->from(
                $table,
                ['product_id', 'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock', 'backorders', 'use_config_backorders']
            )
            ->where('stock_id = 1')
            ->where('product_id IN (?)', $ids);
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $out[(int)$row['product_id']] = $row;
        }
        return $out;
    }

    private function buildStorefrontUrl(array $row): string
    {
        try {
            $store = $this->storeManager->getStore();
        } catch (\Throwable) {
            return '';
        }
        $urlKey = (string)($row['url_key'] ?? '');
        if ($urlKey === '') {
            return '';
        }
        $suffix = (string)$this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
        return rtrim($store->getBaseUrl(), '/') . '/' . $urlKey . $suffix;
    }
}

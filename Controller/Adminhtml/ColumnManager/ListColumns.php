<?php
/**
 * JSON endpoint for the "Manage Columns" slide-in modal.
 *
 * Returns the full attribute catalogue (every product attribute + every
 * panth_* virtual column) with the per-attribute saved overrides merged
 * in. Visibility resolves in three stages:
 *   1. DB override (panth_product_grid_column_config)
 *   2. User bookmark (ui_bookmark current view)
 *   3. Magento standard defaults
 *
 * That way the modal toggles always reflect what's actually shown on the
 * grid right now — not a frozen "everything off" baseline.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class ListColumns extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::column_manager';

    /**
     * Columns Magento's vanilla product_listing.xml shows by default.
     * Used as the third-tier fallback when no DB override + no bookmark
     * entry exists.
     */
    private const DEFAULT_VISIBLE = [
        'entity_id', 'thumbnail', 'name', 'type', 'attribute_set_id', 'attribute_set',
        'sku', 'price', 'qty', 'salable_quantity', 'visibility', 'status', 'websites',
        'updated_at', 'action',
    ];

    /** Virtual columns the module ships — always shown in the panel. */
    private const VIRTUAL_COLUMNS = [
        ['code' => 'panth_thumbnail',        'label' => 'Image',         'type' => 'text',        'group' => 'extras'],
        ['code' => 'panth_categories',       'label' => 'Categories',    'type' => 'multiselect', 'group' => 'extras'],
        ['code' => 'panth_availability',     'label' => 'Availability',  'type' => 'select',      'group' => 'inventory'],
        ['code' => 'panth_backorders',       'label' => 'Backorders',    'type' => 'select',      'group' => 'inventory'],
        ['code' => 'panth_low_stock',        'label' => 'Low Stock',     'type' => 'select',      'group' => 'inventory'],
        ['code' => 'panth_qty_sold',         'label' => 'Qty Sold',      'type' => 'text',        'group' => 'extras'],
        ['code' => 'panth_tier_price_label', 'label' => 'Tier Prices',   'type' => 'text',        'group' => 'pricing'],
        ['code' => 'panth_storefront_url',   'label' => 'Frontend Link', 'type' => 'text',        'group' => 'extras'],
    ];

    private const GROUP_FOR_STATIC = [
        'entity_id' => 'standard', 'name' => 'standard', 'sku' => 'standard',
        'thumbnail' => 'standard', 'type' => 'standard', 'attribute_set' => 'standard',
        'attribute_set_id' => 'standard', 'visibility' => 'standard', 'status' => 'standard',
        'websites' => 'standard', 'created_at' => 'standard', 'updated_at' => 'standard',
        'action' => 'standard',
        'price' => 'pricing', 'special_price' => 'pricing', 'special_from_date' => 'pricing',
        'special_to_date' => 'pricing', 'cost' => 'pricing', 'msrp' => 'pricing',
        'tier_price' => 'pricing',
        'qty' => 'inventory', 'manage_stock' => 'inventory', 'salable_quantity' => 'inventory',
        'quantity_per_source' => 'inventory',
        'meta_title' => 'seo', 'meta_keyword' => 'seo', 'meta_description' => 'seo',
        'url_key' => 'seo', 'include_in_sitemap' => 'seo', 'meta_robots' => 'seo',
    ];

    private const TYPE_MAP = [
        'text' => 'text', 'textarea' => 'textarea',
        'price' => 'price', 'weight' => 'price',
        'date' => 'date', 'datetime' => 'date',
        'select' => 'select', 'boolean' => 'select',
        'multiselect' => 'multiselect',
        'media_image' => 'image',
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ColumnConfigRepository $configRepository,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $columns = $this->buildColumnList();
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
        return $result->setData(['success' => true, 'columns' => $columns]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildColumnList(): array
    {
        $overrides = $this->configRepository->getAll();
        $bookmark = $this->loadBookmarkVisibility();
        $out = [];

        foreach (self::VIRTUAL_COLUMNS as $v) {
            $code = (string)$v['code'];
            $out[] = $this->buildRow(
                $code,
                (string)$v['label'],
                (string)$v['type'],
                (string)$v['group'],
                true,
                $overrides[$code] ?? null,
                false,
                $bookmark[$code] ?? null
            );
        }

        try {
            $attrs = $this->attributeRepository
                ->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $this->searchCriteriaBuilder->create())
                ->getItems();
        } catch (\Throwable) {
            $attrs = [];
        }
        foreach ($attrs as $attr) {
            $code = (string)$attr->getAttributeCode();
            $type = self::TYPE_MAP[(string)$attr->getFrontendInput()] ?? 'text';
            $group = self::GROUP_FOR_STATIC[$code] ?? 'attributes';
            $out[] = $this->buildRow(
                $code,
                (string)$attr->getDefaultFrontendLabel() ?: $code,
                $type,
                $group,
                false,
                $overrides[$code] ?? null,
                (bool)$attr->getIsRequired(),
                $bookmark[$code] ?? null
            );
        }

        usort($out, static function ($a, $b) {
            if (($a['is_virtual'] ?? 0) !== ($b['is_virtual'] ?? 0)) {
                return ($b['is_virtual'] ?? 0) <=> ($a['is_virtual'] ?? 0);
            }
            $sa = (int)$a['sort_order'];
            $sb = (int)$b['sort_order'];
            return $sa <=> $sb ?: strcasecmp((string)$a['label'], (string)$b['label']);
        });

        return $out;
    }

    /**
     * Three-stage visibility:
     *   1. DB override wins.
     *   2. Bookmark wins next.
     *   3. Magento standard default wins last.
     *
     * @param array<string, mixed>|null $override
     */
    private function buildRow(
        string $code,
        string $label,
        string $type,
        string $group,
        bool $isVirtual,
        ?array $override,
        bool $isRequired = false,
        ?bool $bookmarkVisible = null
    ): array {
        if (isset($override['is_visible'])) {
            $visible = (int)$override['is_visible'];
        } elseif ($bookmarkVisible !== null) {
            $visible = $bookmarkVisible ? 1 : 0;
        } else {
            $visible = in_array($code, self::DEFAULT_VISIBLE, true) ? 1 : 0;
        }

        return [
            'code' => $code,
            'label' => $label,
            'type' => $type,
            'group' => $group,
            'is_virtual' => $isVirtual ? 1 : 0,
            'is_required' => $isRequired ? 1 : 0,
            'is_visible' => $visible,
            'is_editable' => isset($override['is_editable']) ? (int)$override['is_editable'] : 0,
            'is_filterable' => isset($override['is_filterable']) ? (int)$override['is_filterable'] : 1,
            'in_export' => isset($override['in_export']) ? (int)$override['in_export'] : 1,
            'custom_label' => (string)($override['custom_label'] ?? ''),
            'sort_order' => (int)($override['sort_order'] ?? 100),
            'marker_color' => (string)($override['marker_color'] ?? ''),
        ];
    }

    /**
     * Reads the current admin user's bookmark JSON for product_listing
     * and returns per-column visibility flags.
     *
     * @return array<string, bool>
     */
    private function loadBookmarkVisibility(): array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('ui_bookmark');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        try {
            $userId = (int)($this->_auth->getUser()?->getId() ?? 0);
        } catch (\Throwable) {
            $userId = 0;
        }
        if ($userId <= 0) {
            return [];
        }
        $select = $conn->select()
            ->from($table, ['config'])
            ->where('namespace = ?', 'product_listing')
            ->where('current = ?', 1)
            ->where('user_id = ?', $userId)
            ->limit(1);
        $config = $conn->fetchOne($select);
        if (!$config) {
            return [];
        }
        $decoded = json_decode((string)$config, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Bookmark JSON can live under different paths depending on
        // Magento version — search the tree for any "columns" map with
        // per-column "visible" flags.
        $out = [];
        $this->collectVisibilityFromTree($decoded, $out);
        return $out;
    }

    /**
     * Recursive walk: every time we see a "columns" associative array
     * whose values are arrays with a "visible" key, harvest the flags.
     *
     * @param array<string, mixed> $node
     * @param array<string, bool> $out
     */
    private function collectVisibilityFromTree(array $node, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'columns') {
                foreach ($value as $code => $entry) {
                    if (is_array($entry) && array_key_exists('visible', $entry)) {
                        $out[(string)$code] = (bool)$entry['visible'];
                    }
                }
            }
            $this->collectVisibilityFromTree($value, $out);
        }
    }
}

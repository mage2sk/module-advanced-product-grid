<?php
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

    private const DEFAULT_VISIBLE = [
        'entity_id', 'thumbnail', 'name', 'type', 'attribute_set_id', 'attribute_set',
        'sku', 'price', 'qty', 'salable_quantity', 'visibility', 'status', 'websites',
        'updated_at', 'action',
    ];

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

    private const STATIC_LABELS = [
        'created_at' => 'Created At',
        'updated_at' => 'Last Updated At',
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
                $this->resolveLabel($code, (string)$attr->getDefaultFrontendLabel()),
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

    private function resolveLabel(string $code, string $label): string
    {
        if (trim($label) !== '') {
            return $label;
        }
        if (isset(self::STATIC_LABELS[$code])) {
            return self::STATIC_LABELS[$code];
        }
        return ucwords(str_replace('_', ' ', $code));
    }

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

        $out = [];
        $this->collectVisibilityFromTree($decoded, $out);
        return $out;
    }

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

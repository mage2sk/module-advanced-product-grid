<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Api\BookmarkManagementInterface;
use Magento\Ui\Component\Listing\Columns as MagentoColumns;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Model\GridConfig;

class Columns extends MagentoColumns
{
    private ?array $bookmarkConfig = null;

    public function __construct(
        ContextInterface $context,
        private readonly AttributeRepository $attributeRepository,
        private readonly ColumnFactory $columnFactory,
        private readonly BookmarkManagementInterface $bookmarkManagement,
        private readonly ColumnConfigRepository $columnConfigRepository,
        array $components = [],
        array $data = [],
        private readonly ?GridConfig $gridConfig = null
    ) {
        parent::__construct($context, $components, $data);
    }

    private function isEnabled(): bool
    {
        return $this->gridConfig === null || $this->gridConfig->isEnabled();
    }

    private function prepareStockGrid(): void
    {
        $sortOrder = 200;
        foreach ($this->attributeRepository->getEditableAttributes() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($this->hasChild($code)) {
                continue;
            }
            $column = $this->columnFactory->create($attribute, $this->getContext(), $sortOrder++);
            $this->addComponent($code, $column);
            $this->addedColumnCodes[] = $code;
        }

        foreach ($this->getChildComponents() as $code => $component) {
            $cfg = $component->getData('config');
            $cfg = is_array($cfg) ? $cfg : [];
            unset($cfg['editor'], $cfg['panthPopupEditor'], $cfg['addField']);
            if (str_starts_with((string)$component->getName(), 'panth_')) {
                $cfg['componentDisabled'] = true;
                $cfg['visible'] = false;
                $cfg['filter'] = false;
            }
            $component->setData('config', $cfg);
            if (in_array((string)$code, $this->addedColumnCodes, true)) {
                $component->prepare();
            }
        }

        $config = $this->getData('config');
        $config = is_array($config) ? $config : [];
        $config['editorConfig'] = array_replace((array)($config['editorConfig'] ?? []), ['enabled' => false]);
        $this->setData('config', $config);

        parent::prepare();
    }

    private array $addedColumnCodes = [];

    private array $originalLabels = [];

    public function prepare(): void
    {
        if (!$this->isEnabled()) {
            $this->prepareStockGrid();
            return;
        }
        $sortOrder = 200;

        foreach ($this->attributeRepository->getEditableAttributes() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($this->hasChild($code)) {
                continue;
            }
            $column = $this->columnFactory->create($attribute, $this->getContext(), $sortOrder++);
            $this->addComponent($code, $column);
            $this->addedColumnCodes[] = $code;
        }

        $this->addUserEnabledColumns($sortOrder);

        $this->addBookmarkEnabledColumns($sortOrder);

        $this->captureOriginalLabels();
        $this->applyColumnConfigOverrides();
        $this->applyBookmarkOverrides();
        $this->normalizePriceEditors();
        $this->annotateColumns();

        foreach ($this->getChildComponents() as $code => $child) {
            if (in_array((string)$code, $this->addedColumnCodes, true)) {
                $child->prepare();
            }
        }

        parent::prepare();
    }

    private function normalizePriceEditors(): void
    {
        foreach ($this->getChildComponents() as $component) {
            $cfg = $component->getData('config');
            if (!is_array($cfg) || !isset($cfg['editor']) || !is_array($cfg['editor'])) {
                continue;
            }
            $type = (string)($cfg['editor']['editorType'] ?? '');
            if ($type === 'price') {
                $cfg['editor']['editorType'] = 'text';
                $component->setData('config', $cfg);
            } elseif ($type === 'select' && !array_key_exists('caption', $cfg['editor'])) {
                $cfg['editor']['caption'] = ' ';
                $component->setData('config', $cfg);
            }
        }
    }

    private function addBookmarkEnabledColumns(int &$sortOrder): void
    {
        foreach ($this->bookmarkVisibleAttributeCodes() as $code) {
            if ($code === '' || str_starts_with($code, 'panth_') || $this->hasChild($code)) {
                continue;
            }
            $attribute = $this->attributeRepository->findByCode($code);
            if ($attribute === null) {
                continue;
            }
            $column = $this->columnFactory->create($attribute, $this->getContext(), $sortOrder++);
            $this->addComponent($code, $column);
            $this->addedColumnCodes[] = $code;
        }
    }

    private function bookmarkVisibleAttributeCodes(): array
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
                                    $out[(string)$code] = true;
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
        return array_keys($out);
    }

    private function addUserEnabledColumns(int &$sortOrder): void
    {
        $configs = $this->columnConfigRepository->getAll();
        if ($configs === []) {
            return;
        }
        foreach ($configs as $code => $cfg) {
            $code = (string)$code;
            if ($this->hasChild($code)) {
                continue;
            }

            if (str_starts_with($code, 'panth_')) {
                continue;
            }
            $shouldAdd = !empty($cfg['is_visible']) || !empty($cfg['is_editable']);
            if (!$shouldAdd) {
                continue;
            }
            $attribute = $this->attributeRepository->findByCode($code);
            if ($attribute === null) {
                continue;
            }
            $column = $this->columnFactory->create($attribute, $this->getContext(), $sortOrder++);
            $this->addComponent($code, $column);
            $this->addedColumnCodes[] = $code;
        }
    }

    private function annotateColumns(): void
    {
        $overrides = $this->columnConfigRepository->getAll();
        foreach ($this->getChildComponents() as $component) {
            $code = (string)$component->getName();
            if ($code === 'ids') {
                continue;
            }
            $cfg = $component->getData('config');
            if (!is_array($cfg)) {
                $cfg = [];
            }

            $type = $this->resolveType($cfg);
            $group = $this->resolveGroup($code, $type);
            $override = $overrides[$code] ?? [];

            $cfg['panthGridMeta'] = [
                'code' => $code,
                'originalLabel' => (string)($this->originalLabels[$code] ?? $cfg['label'] ?? $code),
                'customLabel' => (string)($override['custom_label'] ?? ''),
                'sortOrder' => isset($cfg['sortOrder']) ? (int)$cfg['sortOrder'] : 100,
                'type' => $type,
                'group' => $group,
                'editable' => isset($cfg['editor']),
                'filterable' => !empty($cfg['filter']),
                'isVirtual' => str_starts_with($code, 'panth_') ? 1 : 0,
            ];
            $component->setData('config', $cfg);
        }
    }

    private function captureOriginalLabels(): void
    {
        foreach ($this->getChildComponents() as $component) {
            $cfg = $component->getData('config');
            if (is_array($cfg) && isset($cfg['label'])) {
                $this->originalLabels[(string)$component->getName()] = (string)$cfg['label'];
            }
        }
    }

    private function resolveType(array $cfg): string
    {
        $editor = (string)($cfg['editor']['editorType'] ?? '');
        if ($editor !== '') {
            return $editor;
        }
        $dataType = (string)($cfg['dataType'] ?? 'text');
        return $dataType;
    }

    private function pickFilterTypeFor(array $cfg): string
    {
        $dataType = (string)($cfg['dataType'] ?? 'text');
        return match ($dataType) {
            'date', 'datetime'         => 'dateRange',
            'price', 'weight', 'number' => 'textRange',
            'select', 'multiselect', 'boolean' => 'select',
            default                    => 'text',
        };
    }

    private function pickEditorTypeFor(string $code, array $cfg): string
    {
        $popupFields = [
            'tier_price', 'panth_tier_price_label', 'panth_categories',
            'category_ids', 'description', 'short_description',
            'content', 'content_heading', 'page_content', 'block_content',
            'thumbnail', 'image', 'small_image', 'panth_thumbnail',
        ];
        if (in_array($code, $popupFields, true)) {
            return 'popup';
        }
        $dataType = (string)($cfg['dataType'] ?? 'text');
        return match ($dataType) {
            'date'                => 'date',

            'price', 'weight'     => 'text',
            'select', 'boolean'   => 'select',
            'multiselect'         => 'popup',
            'thumbnail', 'image'  => 'popup',
            'textarea'            => 'popup',
            default               => 'text',
        };
    }

    private function resolveGroup(string $code, string $type): string
    {
        if (in_array($code, ['qty', 'manage_stock', 'panth_availability', 'panth_backorders', 'panth_low_stock', 'salable_quantity', 'quantity_per_source'], true)) {
            return 'inventory';
        }
        if (in_array($code, ['price', 'special_price', 'special_from_date', 'special_to_date', 'cost', 'msrp', 'tier_price', 'panth_tier_price_label'], true)) {
            return 'pricing';
        }
        if (in_array($code, ['meta_title', 'meta_keyword', 'meta_description', 'url_key', 'include_in_sitemap', 'meta_robots'], true)) {
            return 'seo';
        }
        if (in_array($code, ['entity_id', 'name', 'sku', 'thumbnail', 'type', 'type_id', 'attribute_set', 'attribute_set_id', 'visibility', 'status', 'websites', 'created_at', 'updated_at', 'action', 'actions'], true)) {
            return 'standard';
        }
        if (str_starts_with($code, 'panth_')) {
            return 'extras';
        }
        return 'attributes';
    }

    private function applyColumnConfigOverrides(): void
    {
        $configs = $this->columnConfigRepository->getAll();
        if ($configs === []) {
            return;
        }
        foreach ($this->getChildComponents() as $component) {
            $code = $component->getName();
            $cfg = $configs[$code] ?? null;
            if ($cfg === null) {
                continue;
            }
            $data = $component->getData('config');
            $data = is_array($data) ? $data : [];

            if (!empty($cfg['custom_label'])) {
                $data['label'] = (string)$cfg['custom_label'];
            }
            if (isset($cfg['is_visible'])) {
                $data['visible'] = (bool)$cfg['is_visible'];
            }
            if (isset($cfg['is_filterable'])) {
                if ((bool)$cfg['is_filterable']) {
                    if (empty($data['filter'])) {
                        $data['filter'] = $this->pickFilterTypeFor($data);
                    }
                } else {
                    $data['filter'] = false;
                }
            }
            if (isset($cfg['is_editable'])) {
                if ((bool)$cfg['is_editable']) {
                    $editorType = $this->pickEditorTypeFor($component->getName(), $data);
                    if ($editorType === 'popup') {
                        $data['panthPopupEditor'] = [
                            'type' => $this->resolveType($data),
                            'options' => $data['options'] ?? [],
                        ];
                        unset($data['editor']);
                    } else {
                        $data['editor'] = array_replace(
                            ['editorType' => $editorType],
                            (array)($data['editor'] ?? [])
                        );
                        if (!empty($cfg['is_required'])) {
                            $data['editor']['validation']['required-entry'] = true;
                        }

                        $data['addField'] = true;
                    }
                } else {
                    unset($data['editor'], $data['panthPopupEditor']);
                }
            }
            if (!empty($cfg['sort_order'])) {
                $data['sortOrder'] = (int)$cfg['sort_order'];
            }
            if (!empty($cfg['default_width'])) {
                $data['width'] = (int)$cfg['default_width'];
            }
            if (!empty($cfg['marker_color'])) {
                $data['bodyTmpl'] = $data['bodyTmpl'] ?? 'ui/grid/cells/text';
                $data['additionalClasses']['panth-pg-marker-' . preg_replace('/[^a-z]/', '', strtolower((string)$cfg['marker_color']))] = true;
            }
            $component->setData('config', $data);
        }
    }

    private function hasChild(string $name): bool
    {
        foreach ($this->getChildComponents() as $child) {
            if ($child->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    private function applyBookmarkOverrides(): void
    {
        $overrides = $this->loadBookmarkOverrides();
        if ($overrides === []) {
            return;
        }
        foreach ($this->getChildComponents() as $component) {
            $code = $component->getName();
            if (!isset($overrides[$code])) {
                continue;
            }
            $cfg = $component->getData('config');
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $cfg['panthGridColumn'] = array_replace(
                $cfg['panthGridColumn'] ?? [],
                $overrides[$code]
            );
            if (isset($overrides[$code]['visible'])) {
                $cfg['visible'] = (bool)$overrides[$code]['visible'];
            }
            if (isset($overrides[$code]['title']) && $overrides[$code]['title'] !== '') {
                $cfg['label'] = $overrides[$code]['title'];
            }
            $component->setData('config', $cfg);
        }
    }

    private function loadBookmarkOverrides(): array
    {
        if ($this->bookmarkConfig !== null) {
            return $this->bookmarkConfig;
        }

        try {
            $bookmark = $this->bookmarkManagement->getByIdentifierNamespace('current', 'product_listing');
        } catch (\Throwable) {
            return $this->bookmarkConfig = [];
        }
        if ($bookmark === null) {
            return $this->bookmarkConfig = [];
        }
        $config = $bookmark->getConfig();
        if (!is_array($config)) {
            return $this->bookmarkConfig = [];
        }
        $panth = $config['current']['panthGridColumns'] ?? null;
        if (!is_array($panth)) {
            return $this->bookmarkConfig = [];
        }
        $out = [];
        foreach ($panth as $code => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[(string)$code] = $row;
        }
        return $this->bookmarkConfig = $out;
    }
}

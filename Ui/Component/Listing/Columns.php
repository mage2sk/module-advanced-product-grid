<?php
/**
 * Replacement for Magento\Ui\Component\Listing\Columns that auto-adds
 * every is_used_in_grid attribute as an editable column, applies a
 * bookmark-driven config overlay so per-user view choices survive
 * across page loads, and skips columns we don't want.
 *
 * This class is wired in by attribute injection on `product_columns`
 * (see UiConfigPatch) rather than by changing product_listing.xml,
 * so other extensions that also augment that XML don't conflict.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Api\BookmarkManagementInterface;
use Magento\Ui\Component\Listing\Columns as MagentoColumns;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class Columns extends MagentoColumns
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $bookmarkConfig = null;

    public function __construct(
        ContextInterface $context,
        private readonly AttributeRepository $attributeRepository,
        private readonly ColumnFactory $columnFactory,
        private readonly BookmarkManagementInterface $bookmarkManagement,
        private readonly ColumnConfigRepository $columnConfigRepository,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    /** @var list<string> names of columns this class added — we must prepare() them. */
    private array $addedColumnCodes = [];

    public function prepare(): void
    {
        $sortOrder = 200;
        // Pass 1: every attribute flagged is_used_in_grid (standard
        // Magento auto-add path).
        foreach ($this->attributeRepository->getEditableAttributes() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            if ($this->hasChild($code)) {
                continue;
            }
            $column = $this->columnFactory->create($attribute, $this->getContext(), $sortOrder++);
            $this->addComponent($code, $column);
            $this->addedColumnCodes[] = $code;
        }
        // Pass 2: any attribute the admin has explicitly enabled via the
        // Manage Columns panel — even when its EAV flag is_used_in_grid=0.
        // Without this, toggling visibility for an attribute like
        // `material` does nothing because the column never existed.
        $this->addUserEnabledColumns($sortOrder);

        // Pass 3: any attribute the admin marked visible in the standard
        // Magento Columns dropdown (bookmark only — no panth column_config
        // row). Without this, brand-new EAV attributes the user just made
        // visible would render as data-less cells with no filter / options
        // because no column descriptor exists. Walking the bookmark covers
        // every store and every newly-added EAV attribute generically.
        $this->addBookmarkEnabledColumns($sortOrder);

        $this->applyColumnConfigOverrides();
        $this->applyBookmarkOverrides();
        $this->normalizePriceEditors();
        $this->annotateColumns();

        // Render::prepareComponent walks children depth-first BEFORE
        // preparing the parent — so the columns we just appended here
        // never had prepare() called on them by the controller. That
        // matters because Column::prepare() is what fires the
        // `processor->notify('column')` event that the Filters
        // container listens to in order to spawn a filter component
        // for each filterable column. Without that, filtering by
        // any auto-discovered EAV attribute silently fails (request
        // sends filter params; server creates no filter component;
        // data-provider->addFilter is never called; row count never
        // drops). Prepare them explicitly before our own parent
        // prepare so the cascade works.
        foreach ($this->getChildComponents() as $code => $child) {
            if (in_array((string)$code, $this->addedColumnCodes, true)) {
                $child->prepare();
            }
        }

        parent::prepare();
    }

    /**
     * Magento's core product_listing.xml declares price/cost/special_price
     * columns with `editor.editorType="price"` which renders the inline
     * input prefixed with the store currency symbol. Admins editing in
     * the grid expect a plain numeric input — we leave the cell DISPLAY
     * formatting intact (bodyTmpl="ui/grid/cells/price") and only swap
     * the editor element to plain text.
     */
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
            }
        }
    }

    /**
     * Walk the current user's product_listing bookmark and add a real
     * column for every visible entry that maps to an EAV attribute but
     * has no corresponding child component yet. This is what lets a
     * brand-new attribute (is_used_in_grid=0, no panth column_config
     * row) work end-to-end — values render, options resolve, filter
     * appears in the toolbar — purely from the admin toggling
     * visibility in the standard Columns dropdown.
     */
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

    /**
     * Pull every column code marked visible:true in the user's current
     * product_listing bookmark. We walk the JSON tree to handle nested
     * `views.<name>.data.columns.<code>` layouts.
     *
     * @return list<string>
     */
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
            // bookmark table absent on fresh install — no-op
        }
        return array_keys($out);
    }

    /**
     * Look at every panth_product_grid_column_config row with is_visible=1
     * or is_editable=1, and pull the matching attribute into the grid if
     * it isn't already a column. Lets the admin opt in to ANY product
     * attribute via the Column Manager panel.
     */
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
            // Skip virtual panth_* columns: they're handled elsewhere.
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

    /**
     * Decorate every child column with a `panthGridMeta` block the smart
     * Columns panel JS reads to render type badges, group buckets, sort
     * order inputs, and the custom-label inline editor.
     */
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
                'originalLabel' => (string)($cfg['label'] ?? $code),
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

    /**
     * Map a column config block to a human-friendly type label used by
     * the panel's badges (text / select / date / price …).
     *
     * @param array<string, mixed> $cfg
     */
    private function resolveType(array $cfg): string
    {
        $editor = (string)($cfg['editor']['editorType'] ?? '');
        if ($editor !== '') {
            return $editor;
        }
        $dataType = (string)($cfg['dataType'] ?? 'text');
        return $dataType;
    }

    /**
     * Pick a filter type for a column based on its data type. Used when
     * the admin toggles Filter ON on a column that had no filter declared
     * in XML — we still want a usable filter to appear in the toolbar.
     *
     * @param array<string, mixed> $cfg
     */
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

    /**
     * Pick an editor type for a column based on its code + data type.
     * Returns the literal 'popup' for cell types that should open a
     * modal instead of an inline input (multiselect, tier_price, image,
     * textarea content fields).
     *
     * @param array<string, mixed> $cfg
     */
    private function pickEditorTypeFor(string $code, array $cfg): string
    {
        // Popup-only fields — too rich for inline.
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
            // No `price` editor in Magento — fall back to plain text.
            'price', 'weight'     => 'text',
            'select', 'boolean'   => 'select',
            'multiselect'         => 'popup',
            'thumbnail', 'image'  => 'popup',
            'textarea'            => 'popup',
            default               => 'text',
        };
    }

    /**
     * Bucket each column into one of five tabs the panel renders.
     */
    private function resolveGroup(string $code, string $type): string
    {
        if (str_starts_with($code, 'panth_')) {
            return 'extras';
        }
        if (in_array($code, ['qty', 'manage_stock', 'panth_availability', 'panth_backorders', 'panth_low_stock', 'salable_quantity', 'quantity_per_source'], true)) {
            return 'inventory';
        }
        if (in_array($code, ['price', 'special_price', 'special_from_date', 'special_to_date', 'cost', 'msrp', 'tier_price', 'panth_tier_price_label'], true)) {
            return 'pricing';
        }
        if (in_array($code, ['meta_title', 'meta_keyword', 'meta_description', 'url_key', 'include_in_sitemap', 'meta_robots'], true)) {
            return 'seo';
        }
        if (in_array($code, ['entity_id', 'name', 'sku', 'thumbnail', 'type', 'attribute_set', 'attribute_set_id', 'visibility', 'status', 'websites', 'created_at', 'updated_at', 'action', 'panth_storefront_url'], true)) {
            return 'standard';
        }
        return 'attributes';
    }

    /**
     * Layer DB-persisted Column Manager overrides on top of every column
     * so admin choices (custom label, visible, editable, filterable,
     * sort order, marker) survive every page load.
     */
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
                        // Non-trivial cell types (textarea, multiselect,
                        // tier_price, image) get a click-to-open modal
                        // editor handled by JS — leave the column's
                        // standard editor unset so Magento doesn't try
                        // to render an inline form, and flag the cell.
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
                        // addField (camelCase) is required for Magento's
                        // inline editor to render an input in the bulk
                        // edit form — without it the editor config is
                        // silently dropped.
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

    /**
     * Pull the current user's bookmark for product_listing and translate
     * its panthGridColumn entries into a per-column override map.
     *
     * @return array<string, array<string, mixed>>
     */
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

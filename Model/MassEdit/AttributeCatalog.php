<?php
/**
 * Builds the catalogue of attributes the Mass Edit modal can present.
 *
 * Returns a structured list per attribute:
 *   {
 *     code, label, group, frontend_input,
 *     options:[{value,label}],
 *     allow_use_default: bool,
 *     editor_type: 'text'|'textarea'|'select'|'multiselect'|'date'|'price'|'boolean',
 *     placeholder?: string
 *   }
 *
 * Groups: "Pricing", "Inventory", "Visibility", "Content", "SEO", "Other"
 * — used by the modal to render attributes in friendly sections instead
 * of a single 80-item flat list.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\MassEdit;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class AttributeCatalog
{
    private const GROUP_PRICING = 'Pricing';
    private const GROUP_INVENTORY = 'Inventory';
    private const GROUP_VISIBILITY = 'Visibility';
    private const GROUP_CONTENT = 'Content';
    private const GROUP_SEO = 'SEO';
    private const GROUP_OTHER = 'Other';

    private const GROUP_MAP = [
        'price' => self::GROUP_PRICING,
        'special_price' => self::GROUP_PRICING,
        'special_from_date' => self::GROUP_PRICING,
        'special_to_date' => self::GROUP_PRICING,
        'cost' => self::GROUP_PRICING,
        'msrp' => self::GROUP_PRICING,
        'tier_price' => self::GROUP_PRICING,
        'qty' => self::GROUP_INVENTORY,
        'panth_availability' => self::GROUP_INVENTORY,
        'panth_backorders' => self::GROUP_INVENTORY,
        'weight' => self::GROUP_INVENTORY,
        'status' => self::GROUP_VISIBILITY,
        'visibility' => self::GROUP_VISIBILITY,
        'tax_class_id' => self::GROUP_VISIBILITY,
        'category_ids' => self::GROUP_VISIBILITY,
        'name' => self::GROUP_CONTENT,
        'short_description' => self::GROUP_CONTENT,
        'description' => self::GROUP_CONTENT,
        'meta_title' => self::GROUP_SEO,
        'meta_keyword' => self::GROUP_SEO,
        'meta_description' => self::GROUP_SEO,
        'url_key' => self::GROUP_SEO,
    ];

    private const SKIP = [
        'has_options', 'required_options', 'options_container',
        'custom_design', 'custom_design_from', 'custom_design_to',
        'custom_layout', 'custom_layout_update', 'page_layout',
        'sku',
        'image', 'small_image', 'thumbnail', 'swatch_image',
        'image_label', 'small_image_label', 'thumbnail_label',
    ];

    public function __construct(
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @return array<string, list<array<string, mixed>>>  group => entries
     */
    public function getGroupedAttributes(): array
    {
        $entries = [];
        foreach ($this->fetchAttributes() as $attribute) {
            $entry = $this->describeAttribute($attribute);
            if ($entry === null) {
                continue;
            }
            $entries[] = $entry;
        }
        // Append virtual cells.
        $entries[] = $this->virtualEntry('panth_availability', 'Availability', 'select', self::GROUP_INVENTORY, [
            ['value' => '1', 'label' => __('In Stock')],
            ['value' => '0', 'label' => __('Out of Stock')],
            ['value' => '2', 'label' => __('Manage Stock Disabled')],
        ]);
        $entries[] = $this->virtualEntry('panth_backorders', 'Backorders', 'select', self::GROUP_INVENTORY, [
            ['value' => '0', 'label' => __('No Backorders')],
            ['value' => '1', 'label' => __('Allow Qty Below 0')],
            ['value' => '2', 'label' => __('Allow Qty Below 0 and Notify Customer')],
        ]);
        $entries[] = $this->virtualEntry('category_ids', 'Categories', 'multiselect', self::GROUP_VISIBILITY, [], 'Comma-separated category IDs');

        usort($entries, static fn ($a, $b) => strcmp((string)$a['label'], (string)$b['label']));

        $grouped = [];
        foreach ([self::GROUP_PRICING, self::GROUP_INVENTORY, self::GROUP_VISIBILITY, self::GROUP_CONTENT, self::GROUP_SEO, self::GROUP_OTHER] as $group) {
            $grouped[$group] = [];
        }
        foreach ($entries as $entry) {
            $grouped[$entry['group']][] = $entry;
        }
        // Drop empty groups.
        return array_filter($grouped, static fn ($v) => !empty($v));
    }

    /**
     * @return list<AttributeInterface>
     */
    private function fetchAttributes(): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('is_user_defined_or_static', 1)
            ->create();
        try {
            $items = $this->attributeRepository->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $criteria)->getItems();
        } catch (\Throwable) {
            $criteria = $this->searchCriteriaBuilder->create();
            $items = $this->attributeRepository->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $criteria)->getItems();
        }
        return array_values($items);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeAttribute(AttributeInterface $attribute): ?array
    {
        $code = (string)$attribute->getAttributeCode();
        if (in_array($code, self::SKIP, true)) {
            return null;
        }
        $input = (string)$attribute->getFrontendInput();
        $editorType = $this->resolveEditorType($input);
        if ($editorType === null) {
            return null;
        }
        $options = [];
        if (in_array($input, ['select', 'multiselect', 'boolean'], true)) {
            foreach ((array)$attribute->getOptions() as $opt) {
                $value = $opt->getValue();
                if ($value === null || $value === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => (string)$opt->getLabel()];
            }
        }
        $label = (string)$attribute->getDefaultFrontendLabel() ?: $code;
        return [
            'code' => $code,
            'label' => $label,
            'group' => self::GROUP_MAP[$code] ?? self::GROUP_OTHER,
            'frontend_input' => $input,
            'editor_type' => $editorType,
            'options' => $options,
            'allow_use_default' => (int)$attribute->getIsRequired() !== 1,
            'is_required' => (int)$attribute->getIsRequired() === 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function virtualEntry(string $code, string $label, string $editor, string $group, array $options, string $placeholder = ''): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'group' => $group,
            'frontend_input' => $editor,
            'editor_type' => $editor,
            'options' => $options,
            'allow_use_default' => true,
            'is_required' => false,
            'placeholder' => $placeholder,
        ];
    }

    private function resolveEditorType(string $input): ?string
    {
        return match ($input) {
            'text' => 'text',
            'textarea' => 'textarea',
            'price', 'weight' => 'price',
            'date', 'datetime' => 'date',
            'select', 'boolean' => 'select',
            'multiselect' => 'multiselect',
            default => null,
        };
    }
}

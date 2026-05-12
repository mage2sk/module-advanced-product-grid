<?php
/**
 * Builds a UI Component column from an EAV attribute, picking the
 * right JS component (column/select/multiselect/date/thumbnail) and
 * the right editor type by frontend_input.
 *
 * Designed to extend Magento's CatalogUI ColumnFactory contract without
 * inheriting from it — composition keeps this class testable in isolation.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing;

use Magento\Catalog\Api\Data\EavAttributeInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\ColumnInterface;

class ColumnFactory
{
    /**
     * Component overrides are intentionally minimal — we use a global
     * requirejs mixin on `Magento_Ui/js/grid/columns/select` so every
     * select column (including ones auto-injected by Magento for
     * is_used_in_grid attrs) gets the comma-safe getLabel patch.
     */
    private const COMPONENT_BY_INPUT = [
        'select'      => 'Magento_Ui/js/grid/columns/select',
        'boolean'     => 'Magento_Ui/js/grid/columns/select',
        'multiselect' => 'Magento_Ui/js/grid/columns/select',
        'date'        => 'Magento_Ui/js/grid/columns/date',
        'datetime'    => 'Magento_Ui/js/grid/columns/date',
        'media_image' => 'Magento_Ui/js/grid/columns/thumbnail',
    ];

    /**
     * Filter component picked per frontend_input so the toolbar
     * renders the right UI: select dropdown for enumerated types,
     * range pickers for date/numeric, plain text for free-form.
     */
    private const FILTER_TYPE_BY_INPUT = [
        'select'      => 'select',
        'boolean'     => 'select',
        'multiselect' => 'select',
        'date'        => 'dateRange',
        'datetime'    => 'dateRange',
        'price'       => 'textRange',
        'weight'      => 'textRange',
        'text'        => 'text',
        'textarea'    => 'text',
        'media_image' => 'text',
    ];

    private const EDITOR_TYPE_BY_INPUT = [
        'select' => 'select',
        'boolean' => 'select',
        'multiselect' => 'multiselect',
        'date' => 'date',
        'datetime' => 'date',
        // textarea fields use a single-line text editor inline so the
        // bulk-edit row stays compact. For full multi-line editing the
        // cell-click popup detects textarea dataType and opens a proper
        // textarea modal (see Columns::pickEditorTypeFor).
        'textarea' => 'text',
        // Magento's UI editor factory has no `price` editor — using
        // `text` so an inline input actually renders. dataType=price
        // keeps the displayed formatting; the input itself accepts
        // plain numbers.
        'price' => 'text',
        'weight' => 'text',
        'media_image' => 'image',
    ];

    public function __construct(
        private readonly UiComponentFactory $componentFactory
    ) {
    }

    public function create(AttributeInterface $attribute, ContextInterface $context, int $sortOrder): ColumnInterface
    {
        $code = (string)$attribute->getAttributeCode();
        $input = (string)$attribute->getFrontendInput();

        $config = [
            'label' => (string)$attribute->getDefaultFrontendLabel(),
            'dataType' => $this->resolveDataType($input),
            // Magento's inline editor only renders an input for columns
            // that have `addField` (camelCase!) — `add_field` is silently
            // ignored and the cell stays read-only despite having an editor.
            'addField' => true,
            'visible' => false,
            'sortOrder' => $sortOrder,
            'editor' => [
                'editorType' => self::EDITOR_TYPE_BY_INPUT[$input] ?? 'text',
                'validation' => $this->buildValidation($attribute),
            ],
            'panthGridColumn' => [
                'attribute_code' => $code,
                'frontend_input' => $input,
                'is_attribute' => true,
                'editable' => true,
                'filterable' => true,
                'visible' => false,
                'marker' => '',
            ],
        ];

        if (isset(self::COMPONENT_BY_INPUT[$input])) {
            $config['component'] = self::COMPONENT_BY_INPUT[$input];
        }
        if (isset(self::FILTER_TYPE_BY_INPUT[$input])) {
            $config['filter'] = self::FILTER_TYPE_BY_INPUT[$input];
        } else {
            $config['filter'] = 'text';
        }
        if ($input === 'select' || $input === 'multiselect' || $input === 'boolean') {
            $options = $attribute->getOptions() ?? [];
            $optionList = [];
            foreach ($options as $option) {
                $value = $option->getValue();
                if ($value === null || $value === '') {
                    continue;
                }
                $optionList[] = ['value' => $value, 'label' => (string)$option->getLabel()];
            }
            $config['options'] = $optionList;
        }
        if ($input === 'media_image') {
            $config['has_preview'] = 1;
        }

        $arguments = [
            'data' => ['config' => $config],
            'context' => $context,
        ];
        return $this->componentFactory->create($code, 'column', $arguments);
    }

    /**
     * Map EAV input -> grid dataType. We deliberately keep `price` and
     * `weight` as plain `text` (instead of `price` / `number`) so the
     * inline editor input renders without a currency prefix — the user
     * types raw numbers, the grid stores them, and any display
     * formatting is handled by a downstream renderer (not the editor).
     */
    private function resolveDataType(string $input): string
    {
        return match ($input) {
            'date', 'datetime'  => 'date',
            'select', 'boolean' => 'select',
            'multiselect'       => 'multiselect',
            'media_image'       => 'thumbnail',
            default             => 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValidation(AttributeInterface $attribute): array
    {
        $rules = [];
        $frontendClass = '';
        if ($attribute instanceof EavAttributeInterface) {
            $frontendClass = (string)$attribute->getFrontendClass();
        } elseif (method_exists($attribute, 'getFrontendClass')) {
            $frontendClass = (string)$attribute->getFrontendClass();
        }
        if ($frontendClass !== '') {
            foreach (preg_split('/\s+/', $frontendClass) ?: [] as $class) {
                if ($class === '') {
                    continue;
                }
                $rules[$class] = true;
            }
        }
        if ((int)$attribute->getIsRequired() === 1) {
            $rules['required-entry'] = true;
        }
        return $rules;
    }
}

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing;

use Magento\Catalog\Api\Data\EavAttributeInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\ColumnInterface;

class ColumnFactory
{
    private const COMPONENT_BY_INPUT = [
        'select'      => 'Magento_Ui/js/grid/columns/select',
        'boolean'     => 'Magento_Ui/js/grid/columns/select',
        'multiselect' => 'Magento_Ui/js/grid/columns/select',
        'date'        => 'Magento_Ui/js/grid/columns/date',
        'datetime'    => 'Magento_Ui/js/grid/columns/date',
        'media_image' => 'Magento_Ui/js/grid/columns/thumbnail',
    ];

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

        'textarea' => 'text',

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

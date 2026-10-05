<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Locale\FormatInterface;

class ChangeValidator
{
    private const USE_DEFAULT = '__use_default__';
    private const EMPTY_VALUE = '__empty__';

    private const OPTION_CODES = [
        'panth_availability' => ['0', '1', '2'],
        'panth_backorders' => ['0', '1', '2'],
    ];

    private const NUMERIC_CODES = ['qty', 'weight'];

    private const ID_LIST_CODES = ['category_ids'];

    private const STRUCTURED_CODES = ['tier_price'];

    private const IMAGE_ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    private const DENIED_CODES = [
        'entity_id', 'row_id', 'attribute_set_id', 'type_id', 'created_at', 'updated_at',
        'has_options', 'required_options', 'store_id', 'website_ids', 'url_path',
        'custom_layout_update', 'custom_layout_update_file', 'custom_design', 'custom_layout',
        'page_layout', 'options_container', 'media_gallery', 'gallery', 'quantity_and_stock_status',
        'stock_data', 'extension_attributes', 'custom_attributes', 'options', 'product_links',
    ];

    private const EDITABLE_INPUTS = [
        'text', 'textarea', 'select', 'multiselect', 'date', 'datetime', 'weight', 'price', 'boolean',
    ];

    private const VARCHAR_MAX_LENGTH = 255;

    private array $attributes = [];

    public function __construct(
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly ?FormatInterface $localeFormat = null
    ) {
    }

    public function normalize(array $changes): array
    {
        foreach ($changes as $code => $value) {
            if (!is_string($code) || !is_string($value) || !$this->isNumericCode($code)) {
                continue;
            }
            $changes[$code] = $this->normalizeNumber($value);
        }
        return $changes;
    }

    private function isNumericCode(string $code): bool
    {
        if (in_array($code, self::NUMERIC_CODES, true)) {
            return true;
        }
        if ($this->isVirtualCode($code) || in_array($code, self::DENIED_CODES, true)) {
            return false;
        }
        $attribute = $this->findEditableAttribute($code);
        return $attribute !== null
            && in_array((string)$attribute->getFrontendInput(), ['price', 'weight'], true);
    }

    private function normalizeNumber(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || is_numeric($trimmed)) {
            return $value;
        }
        if (!preg_match('/\d/', $trimmed) || preg_match('/[A-Za-z]/', $trimmed)) {
            return $value;
        }
        if ($this->localeFormat !== null) {
            $number = $this->localeFormat->getNumber($trimmed);
            return $number === null ? $value : (string)$number;
        }
        $stripped = (string)preg_replace('/[^\d.,\-]/', '', $trimmed);
        $stripped = str_replace(',', '', $stripped);
        return is_numeric($stripped) ? $stripped : $value;
    }

    public function validate(array $changes): ?string
    {
        foreach ($changes as $code => $value) {
            if (!is_string($code) || $code === '') {
                return (string)__('Invalid attribute code.');
            }
            $error = $this->validateChange($code, $value);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    public function isAllowedCode(string $code): bool
    {
        if (in_array($code, self::DENIED_CODES, true)) {
            return false;
        }
        if ($this->isVirtualCode($code)) {
            return true;
        }
        return $this->findEditableAttribute($code) !== null;
    }

    public function validateChange(string $code, mixed $value): ?string
    {
        if (!$this->isAllowedCode($code)) {
            return (string)__('Attribute "%1" cannot be edited from the product grid.', $code);
        }

        $valid = $this->isVirtualCode($code)
            ? $this->isValidVirtualValue($code, $value)
            : $this->isValidAttributeValue($this->findEditableAttribute($code), $value);

        return $valid ? null : (string)__('Invalid value for attribute "%1".', $code);
    }

    private function isVirtualCode(string $code): bool
    {
        return isset(self::OPTION_CODES[$code])
            || in_array($code, self::NUMERIC_CODES, true)
            || in_array($code, self::ID_LIST_CODES, true)
            || in_array($code, self::STRUCTURED_CODES, true)
            || in_array($code, self::IMAGE_ROLE_CODES, true);
    }

    private function isValidVirtualValue(string $code, mixed $value): bool
    {
        if (isset(self::OPTION_CODES[$code])) {
            return is_scalar($value) && in_array((string)$value, self::OPTION_CODES[$code], true);
        }
        if (in_array($code, self::NUMERIC_CODES, true)) {
            if ($code === 'weight' && $this->isEmptyValue($value)) {
                return true;
            }
            return is_scalar($value) && is_numeric(trim((string)$value)) && (float)$value >= 0;
        }
        if (in_array($code, self::ID_LIST_CODES, true)) {
            return $this->isValidIdList($value);
        }
        if (in_array($code, self::STRUCTURED_CODES, true)) {
            if (is_array($value)) {
                return true;
            }
            if (!is_string($value)) {
                return false;
            }
            return $value === '' || is_array(json_decode($value, true));
        }
        return is_string($value) && strlen($value) <= self::VARCHAR_MAX_LENGTH;
    }

    private function isValidIdList(mixed $value): bool
    {
        if ($value === self::EMPTY_VALUE || $value === '' || $value === [] || $value === '0' || $value === null) {
            return true;
        }
        $ids = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);
        if ($ids === null) {
            return false;
        }
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit(trim((string)$id))) {
                return false;
            }
        }
        return true;
    }

    private function findEditableAttribute(string $code): ?AttributeInterface
    {
        if (array_key_exists($code, $this->attributes)) {
            return $this->attributes[$code];
        }
        $this->attributes[$code] = null;
        try {
            $attribute = $this->attributeRepository->get(ProductAttributeInterface::ENTITY_TYPE_CODE, $code);
        } catch (\Throwable) {
            return null;
        }
        if (!in_array((string)$attribute->getFrontendInput(), self::EDITABLE_INPUTS, true)) {
            return null;
        }
        if (method_exists($attribute, 'getData')) {
            $visible = $attribute->getData('is_visible');
            if ($visible !== null && (int)$visible === 0) {
                return null;
            }
        }
        return $this->attributes[$code] = $attribute;
    }

    private function isValidAttributeValue(?AttributeInterface $attribute, mixed $value): bool
    {
        if ($attribute === null) {
            return false;
        }
        $input = (string)$attribute->getFrontendInput();
        $backendType = (string)$attribute->getBackendType();

        if ($this->isEmptyValue($value)) {
            return !$attribute->getIsRequired();
        }

        switch ($input) {
            case 'price':
            case 'weight':
                return is_scalar($value) && is_numeric(trim((string)$value)) && (float)$value >= 0;
            case 'date':
            case 'datetime':
                return is_string($value) && strlen($value) <= 32 && strtotime($value) !== false;
            case 'boolean':
                return is_scalar($value) && in_array((string)$value, ['0', '1'], true);
            case 'select':
                return is_scalar($value) && $this->isKnownOption($attribute, [(string)$value]);
            case 'multiselect':
                $values = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);
                if ($values === null) {
                    return false;
                }
                foreach ($values as $item) {
                    if (!is_scalar($item)) {
                        return false;
                    }
                }
                return $this->isKnownOption($attribute, array_map(
                    static fn ($item) => trim((string)$item),
                    $values
                ));
        }

        if (!is_scalar($value)) {
            return false;
        }
        if ($backendType === 'decimal') {
            return is_numeric(trim((string)$value));
        }
        if ($backendType === 'int') {
            return (bool)preg_match('/^-?\d+$/', trim((string)$value));
        }
        if ($backendType === 'varchar' || ($backendType === 'static' && $input === 'text')) {
            return mb_strlen((string)$value) <= self::VARCHAR_MAX_LENGTH;
        }
        return true;
    }

    private function isKnownOption(AttributeInterface $attribute, array $values): bool
    {
        $known = [];
        try {
            if (method_exists($attribute, 'usesSource') && $attribute->usesSource()) {
                foreach ((array)$attribute->getSource()->getAllOptions(false) as $option) {
                    if (is_array($option) && isset($option['value']) && !is_array($option['value'])) {
                        $known[(string)$option['value']] = true;
                    }
                }
            } else {
                foreach ((array)$attribute->getOptions() as $option) {
                    $optionValue = $option->getValue();
                    if ($optionValue !== null && $optionValue !== '') {
                        $known[(string)$optionValue] = true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            if (!isset($known[$value])) {
                return false;
            }
        }
        return true;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || $value === []
            || $value === self::EMPTY_VALUE
            || $value === self::USE_DEFAULT;
    }
}

<?php
/**
 * Rewrites the addFilter() call from the grid UI so every attribute
 * type filters correctly against its EAV storage shape.
 *
 *   - multiselect : DB stores `12,34,56` so an `eq` lookup never
 *                   matches a single option_id. Use `finset` instead.
 *   - boolean     : value comes through as a string "1"/"0" — fine
 *                   with `eq`, but normalize to int for predictable
 *                   plan caching.
 *   - text/textarea : leave Magento's default substring matcher in
 *                   place (it uses LIKE %value%).
 *   - select / date / price / weight : pass through (the standard
 *                   conditionType the UI sends already works).
 *
 * We sit BEFORE Magento's built-in addFilterStrategies dispatch so
 * named virtual columns (panth_pg_categories etc.) keep their custom
 * strategies. We only rewrite condition+value; the strategy registry
 * still owns the field, so a strategy match still takes precedence.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Filter;

class ProductDataProviderFilterPlugin
{
    /** @var array<string, string> attribute_code => frontend_input cache */
    private array $inputCache = [];

    public function __construct(
        private readonly EavConfig $eavConfig
    ) {
    }

    public function beforeAddFilter(ProductDataProvider $subject, Filter $filter): array
    {
        $field = (string)$filter->getField();
        if ($field === '' || str_starts_with($field, 'panth_')) {
            return [$filter];
        }

        $input = $this->resolveInput($field);
        if ($input === null) {
            return [$filter];
        }

        $value = $filter->getValue();
        $condition = (string)$filter->getConditionType();

        switch ($input) {
            case 'multiselect':
                if ($value === null || $value === '' || $value === []) {
                    return [$filter];
                }
                $values = is_array($value) ? $value : [$value];
                $values = array_values(array_filter(array_map(
                    static fn ($v) => (string)$v,
                    $values
                ), static fn ($v) => $v !== ''));
                if ($values === []) {
                    return [$filter];
                }
                $filter->setConditionType('finset');
                $filter->setValue(count($values) === 1 ? $values[0] : $values);
                break;

            case 'boolean':
                if ($value !== null && $value !== '' && !is_array($value)) {
                    $filter->setValue((int)$value);
                }
                if ($condition === '' || $condition === 'like') {
                    $filter->setConditionType('eq');
                }
                break;

            case 'select':
                if ($condition === '' || $condition === 'like') {
                    $filter->setConditionType('eq');
                }
                break;

            case 'textarea':
            case 'text':
                if ($condition === '' || $condition === 'eq') {
                    $filter->setConditionType('like');
                    if (is_string($value) && $value !== '' && strpos($value, '%') === false) {
                        $filter->setValue('%' . $value . '%');
                    }
                }
                break;

            case 'date':
            case 'datetime':
            case 'price':
            case 'weight':
                // dateRange / textRange filters supply from/to as
                // gteq/lteq — both already work on EAV columns.
                break;

            default:
                break;
        }

        return [$filter];
    }

    private function resolveInput(string $code): ?string
    {
        if (array_key_exists($code, $this->inputCache)) {
            return $this->inputCache[$code] ?: null;
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Throwable) {
            return $this->inputCache[$code] = '' ?: null;
        }
        if (!$attribute || !$attribute->getId()) {
            $this->inputCache[$code] = '';
            return null;
        }
        $input = (string)$attribute->getFrontendInput();
        $this->inputCache[$code] = $input;
        return $input !== '' ? $input : null;
    }
}

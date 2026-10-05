<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Plugin;

use Magento\Catalog\Ui\DataProvider\Product\ProductDataProvider;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\Filter;
use Panth\AdvancedProductGrid\Plugin\Catalog\Ui\DataProvider\ProductDataProviderFilterPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductDataProviderFilterPluginTest extends TestCase
{
    private array $lookups = [];
    private array $inputs = [
        'color' => 'multiselect',
        'is_featured' => 'boolean',
        'status' => 'select',
        'name' => 'text',
        'description' => 'textarea',
        'price' => 'price',
        'blank' => '',
    ];

    private function plugin(): ProductDataProviderFilterPlugin
    {
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($entity, $code) {
            $this->lookups[] = $code;
            if ($code === 'explodes') {
                throw new \RuntimeException('eav');
            }
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getId')->willReturn(array_key_exists($code, $this->inputs) ? 10 : null);
            $attribute->method('getFrontendInput')->willReturn($this->inputs[$code] ?? null);
            return $attribute;
        });
        return new ProductDataProviderFilterPlugin($eav);
    }

    private function filter(string $field, mixed $value, string $condition = ''): Filter
    {
        $filter = new Filter();
        $filter->setField($field);
        $filter->setValue($value);
        if ($condition !== '') {
            $filter->setConditionType($condition);
        }
        return $filter;
    }

    private function apply(Filter $filter, ?ProductDataProviderFilterPlugin $plugin = null): Filter
    {
        $plugin ??= $this->plugin();
        $result = $plugin->beforeAddFilter($this->createStub(ProductDataProvider::class), $filter);
        $this->assertSame([$filter], $result);
        return $filter;
    }

    public function testVirtualAndEmptyFieldsAreNotInspected(): void
    {
        $this->apply($this->filter('panth_qty_sold', '5'));
        $this->apply($this->filter('', '5'));

        $this->assertSame([], $this->lookups);
    }

    public function testMultiselectUsesFindInSet(): void
    {
        $single = $this->apply($this->filter('color', '49', 'eq'));
        $many = $this->apply($this->filter('color', ['49', '', 50], 'in'));

        $this->assertSame('finset', $single->getConditionType());
        $this->assertSame('49', $single->getValue());
        $this->assertSame('finset', $many->getConditionType());
        $this->assertSame(['49', '50'], $many->getValue());
    }

    public function testEmptyMultiselectValuesAreLeftUntouched(): void
    {
        foreach ([null, '', [], ['', '']] as $value) {
            $filter = $this->apply($this->filter('color', $value, 'eq'));
            $this->assertSame('eq', $filter->getConditionType());
        }
    }

    public function testBooleanIsCastAndCompareByEquality(): void
    {
        $filter = $this->apply($this->filter('is_featured', '1', 'like'));

        $this->assertSame(1, $filter->getValue());
        $this->assertSame('eq', $filter->getConditionType());

        $kept = $this->apply($this->filter('is_featured', ['1'], 'in'));
        $this->assertSame(['1'], $kept->getValue());
        $this->assertSame('in', $kept->getConditionType());
    }

    public static function equalityConditions(): array
    {
        return [['like', 'eq'], ['', 'eq'], ['in', 'in']];
    }

    #[DataProvider('equalityConditions')]
    public function testSelectSwitchesLikeToEquality(string $given, string $expected): void
    {
        $filter = $this->apply($this->filter('status', '1', $given));

        $this->assertSame($expected, $filter->getConditionType() ?? '');
    }

    public function testTextBecomesWildcardLike(): void
    {
        $plain = $this->apply($this->filter('name', 'shirt', 'eq'));
        $pattern = $this->apply($this->filter('description', 'sh%rt'));
        $other = $this->apply($this->filter('name', 'shirt', 'neq'));

        $this->assertSame('like', $plain->getConditionType());
        $this->assertSame('%shirt%', $plain->getValue());
        $this->assertSame('like', $pattern->getConditionType());
        $this->assertSame('sh%rt', $pattern->getValue());
        $this->assertSame('neq', $other->getConditionType());
        $this->assertSame('shirt', $other->getValue());
    }

    public function testRangeTypesAndUnknownAttributesPassThrough(): void
    {
        $price = $this->apply($this->filter('price', ['from' => 1], 'gteq'));
        $unknown = $this->apply($this->filter('nope', 'x', 'eq'));
        $blank = $this->apply($this->filter('blank', 'x', 'eq'));
        $broken = $this->apply($this->filter('explodes', 'x', 'eq'));

        $this->assertSame('gteq', $price->getConditionType());
        $this->assertSame('eq', $unknown->getConditionType());
        $this->assertSame('eq', $blank->getConditionType());
        $this->assertSame('eq', $broken->getConditionType());
    }

    public function testAttributeLookupsAreCached(): void
    {
        $plugin = $this->plugin();
        $this->apply($this->filter('name', 'a', 'eq'), $plugin);
        $this->apply($this->filter('name', 'b', 'eq'), $plugin);
        $this->apply($this->filter('nope', 'b', 'eq'), $plugin);
        $this->apply($this->filter('nope', 'b', 'eq'), $plugin);
        $this->apply($this->filter('explodes', 'b', 'eq'), $plugin);
        $this->apply($this->filter('explodes', 'b', 'eq'), $plugin);

        $this->assertSame(['name', 'nope', 'explodes'], $this->lookups);
    }
}

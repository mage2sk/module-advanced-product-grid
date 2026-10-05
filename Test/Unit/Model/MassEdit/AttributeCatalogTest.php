<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\MassEdit;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\AdvancedProductGrid\Model\MassEdit\AttributeCatalog;
use PHPUnit\Framework\TestCase;

class AttributeCatalogTest extends TestCase
{
    private array $filters = [];
    private int $listCalls = 0;
    private bool $firstListThrows = false;

    private function attribute(string $code, string $input, string $label = '', int $required = 0, array $options = []): AttributeInterface
    {
        $opts = [];
        foreach ($options as $value => $text) {
            $opt = $this->createStub(AttributeOptionInterface::class);
            $opt->method('getValue')->willReturn($value === '' ? '' : (string)$value);
            $opt->method('getLabel')->willReturn($text);
            $opts[] = $opt;
        }
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getDefaultFrontendLabel')->willReturn($label);
        $attribute->method('getIsRequired')->willReturn($required);
        $attribute->method('getOptions')->willReturn($opts);
        return $attribute;
    }

    private function catalog(array $attributes): AttributeCatalog
    {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value) use ($builder) {
            $this->filters[] = [$field, $value];
            return $builder;
        });
        $builder->method('create')->willReturn(new SearchCriteria());

        $results = $this->createStub(AttributeSearchResultsInterface::class);
        $results->method('getItems')->willReturn($attributes);
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function ($entityType) use ($results) {
            $this->listCalls++;
            if ($this->firstListThrows && $this->listCalls === 1) {
                throw new \InvalidArgumentException('unknown filter');
            }
            return $results;
        });

        return new AttributeCatalog($repository, $builder);
    }

    private function find(array $groups, string $code): ?array
    {
        foreach ($groups as $entries) {
            foreach ($entries as $entry) {
                if ($entry['code'] === $code) {
                    return $entry;
                }
            }
        }
        return null;
    }

    public function testAttributesAreDescribedAndGrouped(): void
    {
        $groups = $this->catalog([
            $this->attribute('price', 'price', 'Price', 1),
            $this->attribute('meta_title', 'text', 'Meta Title'),
            $this->attribute('color', 'select', 'Color', 0, ['' => 'None', '49' => 'Red', '50' => 'Blue']),
            $this->attribute('news_from_date', 'date', 'New From'),
        ])->getGroupedAttributes();

        $this->assertSame(['Pricing', 'Inventory', 'Visibility', 'SEO', 'Other'], array_keys($groups));
        $price = $this->find($groups, 'price');
        $this->assertSame('Pricing', $price['group']);
        $this->assertSame('price', $price['editor_type']);
        $this->assertTrue($price['is_required']);
        $this->assertFalse($price['allow_use_default']);

        $color = $this->find($groups, 'color');
        $this->assertSame('Other', $color['group']);
        $this->assertSame(
            [['value' => '49', 'label' => 'Red'], ['value' => '50', 'label' => 'Blue']],
            $color['options']
        );
        $this->assertTrue($color['allow_use_default']);
        $this->assertSame('date', $this->find($groups, 'news_from_date')['editor_type']);
        $this->assertSame('SEO', $this->find($groups, 'meta_title')['group']);
        $this->assertSame([['is_user_defined_or_static', 1]], $this->filters);
    }

    public function testSkippedAndUnsupportedAttributesAreLeftOut(): void
    {
        $groups = $this->catalog([
            $this->attribute('sku', 'text', 'SKU'),
            $this->attribute('image', 'media_image', 'Base'),
            $this->attribute('gallery', 'gallery', 'Gallery'),
            $this->attribute('custom_layout', 'select', 'Layout'),
        ])->getGroupedAttributes();

        $this->assertNull($this->find($groups, 'sku'));
        $this->assertNull($this->find($groups, 'image'));
        $this->assertNull($this->find($groups, 'gallery'));
        $this->assertNull($this->find($groups, 'custom_layout'));
        $this->assertSame(['Inventory', 'Visibility'], array_keys($groups));
    }

    public function testCategoryAttributeIsListedOnlyOnceAsTheVirtualEntry(): void
    {
        $groups = $this->catalog([
            $this->attribute('category_ids', 'text', 'Categories'),
        ])->getGroupedAttributes();

        $codes = array_column($groups['Visibility'], 'code');
        $this->assertSame(['category_ids'], $codes);
        $this->assertSame('multiselect', $groups['Visibility'][0]['editor_type']);
    }

    public function testVirtualEntriesAreAlwaysPresent(): void
    {
        $groups = $this->catalog([])->getGroupedAttributes();

        $availability = $this->find($groups, 'panth_availability');
        $this->assertSame('Inventory', $availability['group']);
        $this->assertSame(['1', '0', '2'], array_column($availability['options'], 'value'));
        $this->assertSame(['0', '1', '2'], array_column($this->find($groups, 'panth_backorders')['options'], 'value'));
        $categories = $this->find($groups, 'category_ids');
        $this->assertSame('multiselect', $categories['editor_type']);
        $this->assertSame('Comma-separated category IDs', $categories['placeholder']);
    }

    public function testEntriesAreSortedByLabelAndLabelFallsBackToCode(): void
    {
        $groups = $this->catalog([
            $this->attribute('zeta', 'text', 'Zeta'),
            $this->attribute('alpha_code', 'textarea', ''),
            $this->attribute('flag', 'boolean', 'Mid', 0, ['0' => 'No', '1' => 'Yes']),
        ])->getGroupedAttributes();

        // strcmp ordering: capitalised labels sort before a lowercase code fallback.
        $this->assertSame(['Mid', 'Zeta', 'alpha_code'], array_column($groups['Other'], 'label'));
        $this->assertSame('textarea', $this->find($groups, 'alpha_code')['editor_type']);
        $flag = $this->find($groups, 'flag');
        $this->assertSame('select', $flag['editor_type']);
        $this->assertCount(2, $flag['options']);
    }

    public function testUnsupportedFilterFallsBackToUnfilteredList(): void
    {
        $this->firstListThrows = true;

        $groups = $this->catalog([$this->attribute('name', 'text', 'Product Name')])->getGroupedAttributes();

        $this->assertSame(2, $this->listCalls);
        $this->assertSame('Content', $this->find($groups, 'name')['group']);
    }
}

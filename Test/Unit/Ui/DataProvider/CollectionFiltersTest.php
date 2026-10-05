<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\DataProvider;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddAvailabilityFilterToCollection;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddCategoryFilterToCollection;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddQtySoldFilterToCollection;
use Panth\AdvancedProductGrid\Ui\DataProvider\Product\AddThumbnailFilterToCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CollectionFiltersTest extends TestCase
{
    use DbDoublesTrait;

    private array $attributeFilters = [];

    private function collection(array $fromParts = []): Collection
    {
        $select = $this->select($fromParts);
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getTable')->willReturnCallback(static fn ($t) => 'pfx_' . $t);
        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getResource')->willReturn($resource);
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null, $join = 'inner') use ($collection) {
                $this->attributeFilters[] = [$attribute, $condition, $join];
                return $collection;
            }
        );
        return $collection;
    }

    private function availabilityFilter(string $manageStock = '1'): AddAvailabilityFilterToCollection
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn ($path) => $path === 'cataloginventory/item_options/manage_stock' ? $manageStock : null
        );
        return new AddAvailabilityFilterToCollection($scope);
    }

    private function apiFilter(mixed $value): Filter
    {
        $filter = new Filter();
        $filter->setValue($value);
        return $filter;
    }

    public function testFiltersIgnoreNonProductCollections(): void
    {
        $collection = $this->createStub(DataCollection::class);
        $this->availabilityFilter()->addFilter($collection, 'f', ['eq' => '1']);
        (new AddCategoryFilterToCollection())->addFilter($collection, 'f', ['eq' => '1']);
        (new AddQtySoldFilterToCollection())->addFilter($collection, 'f', ['eq' => '1']);
        (new AddThumbnailFilterToCollection())->addFilter($collection, 'f', ['eq' => '1']);

        $this->assertSame([], $this->wheres);
        $this->assertSame([], $this->attributeFilters);
    }

    public static function availabilityCases(): array
    {
        return [
            'in stock' => [['eq' => '1'], '= 1 AND panth_stock.is_in_stock = 1'],
            'out of stock via filter object' => ['filter:0', '= 1 AND panth_stock.is_in_stock = 0'],
            'manage disabled scalar' => ['2', 'END) = 0'],
        ];
    }

    #[DataProvider('availabilityCases')]
    public function testAvailabilityJoinsStockOnceAndFilters(mixed $condition, string $expectedFragment): void
    {
        if (is_string($condition) && str_starts_with($condition, 'filter:')) {
            $condition = $this->apiFilter(substr($condition, 7));
        }

        $this->availabilityFilter()->addFilter($this->collection(), 'panth_availability', $condition);

        $this->assertCount(1, $this->joins);
        $this->assertSame(['panth_stock' => 'pfx_cataloginventory_stock_item'], $this->joins[0][1]);
        $this->assertStringContainsString($expectedFragment, $this->wheres[0][0]);
    }

    public function testAvailabilityHonoursTheConfiguredManageStockSetting(): void
    {
        $this->availabilityFilter('0')->addFilter($this->collection(), 'panth_availability', ['eq' => '2']);
        $this->assertStringContainsString('use_config_manage_stock = 1 THEN 0 ELSE', $this->wheres[0][0]);

        $this->wheres = [];
        $this->availabilityFilter('1')->addFilter($this->collection(), 'panth_availability', ['eq' => '2']);
        $this->assertStringContainsString('use_config_manage_stock = 1 THEN 1 ELSE', $this->wheres[0][0]);
    }

    public function testAvailabilityReusesExistingJoinAndIgnoresUnknownValues(): void
    {
        $this->availabilityFilter()->addFilter(
            $this->collection(['panth_stock' => []]),
            'panth_availability',
            ['eq' => '1']
        );
        $this->assertSame([], $this->joins);
        $this->assertCount(1, $this->wheres);

        $this->wheres = [];
        $this->availabilityFilter()->addFilter($this->collection(), 'panth_availability', ['eq' => '9']);
        $this->assertSame([], $this->wheres);
    }

    public function testCategoryFilterJoinsAndGroupsForIds(): void
    {
        (new AddCategoryFilterToCollection())->addFilter($this->collection(), 'panth_categories', ['in' => ['3', '0', 'x', 7]]);

        $this->assertSame('joinInner', $this->joins[0][0]);
        $this->assertSame(['panth_cat' => 'pfx_catalog_category_product'], $this->joins[0][1]);
        $this->assertSame([['panth_cat.category_id IN (?)', [0 => 3, 3 => 7]]], $this->wheres);
        $this->assertSame(['e.entity_id'], $this->groups);
    }

    public function testCategoryFilterFindsProductsWithoutCategory(): void
    {
        (new AddCategoryFilterToCollection())->addFilter(
            $this->collection(),
            'panth_categories',
            $this->apiFilter(AddCategoryFilterToCollection::NO_CATEGORY)
        );

        $this->assertSame('joinLeft', $this->joins[0][0]);
        $this->assertSame([['panth_cat.category_id IS NULL', null]], $this->wheres);
        $this->assertSame([], $this->groups);
    }

    public function testCategoryFilterSkipsInvalidOrRepeatedFilters(): void
    {
        (new AddCategoryFilterToCollection())->addFilter($this->collection(), 'panth_categories', 'abc');
        (new AddCategoryFilterToCollection())->addFilter($this->collection(['panth_cat' => []]), 'panth_categories', '5');

        $this->assertSame([], $this->joins);
        $this->assertSame([], $this->wheres);
    }

    public function testQtySoldRangeUsesCoalescedComparisons(): void
    {
        (new AddQtySoldFilterToCollection())->addFilter(
            $this->collection(),
            'panth_qty_sold',
            ['from' => '5', 'to' => '10.7', 'lteq' => '', 'eq' => 'abc']
        );

        $this->assertSame(['panth_qs' => 'pfx_panth_product_grid_qty_sold'], $this->joins[0][1]);
        $this->assertSame([
            ['COALESCE(panth_qs.qty_sold, 0) >= ?', 5],
            ['COALESCE(panth_qs.qty_sold, 0) <= ?', 10],
        ], $this->wheres);
    }

    public function testQtySoldScalarAndFilterObjectMeanEquality(): void
    {
        (new AddQtySoldFilterToCollection())->addFilter($this->collection(['panth_qs' => []]), 'panth_qty_sold', '0');
        (new AddQtySoldFilterToCollection())->addFilter($this->collection(['panth_qs' => []]), 'panth_qty_sold', $this->apiFilter('3'));

        $this->assertSame([], $this->joins);
        $this->assertSame([
            ['COALESCE(panth_qs.qty_sold, 0) = ?', 0],
            ['COALESCE(panth_qs.qty_sold, 0) = ?', 3],
        ], $this->wheres);
    }

    public function testThumbnailFilterSplitsAddedAndMissingImages(): void
    {
        (new AddThumbnailFilterToCollection())->addFilter($this->collection(), 'panth_thumbnail', ['like' => '%1%']);
        (new AddThumbnailFilterToCollection())->addFilter($this->collection(), 'panth_thumbnail', $this->apiFilter('0'));
        (new AddThumbnailFilterToCollection())->addFilter($this->collection(), 'panth_thumbnail', ['eq' => 'maybe']);
        (new AddThumbnailFilterToCollection())->addFilter($this->collection(), 'panth_thumbnail', ['eq' => ['1']]);

        $this->assertSame([
            ['thumbnail', ['nin' => ['no_selection', '']], 'inner'],
            ['thumbnail', [['null' => true], ['in' => ['no_selection', '']]], 'left'],
        ], $this->attributeFilters);
    }
}

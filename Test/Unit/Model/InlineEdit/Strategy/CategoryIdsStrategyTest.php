<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\CategoryIdsStrategy;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategoryIdsStrategyTest extends TestCase
{
    use DbDoublesTrait;

    private array $assigned = [];
    private array $deleted = [];
    private bool $assignThrows = false;

    private function strategy(): CategoryIdsStrategy
    {
        $links = $this->createStub(CategoryLinkManagementInterface::class);
        $links->method('assignProductToCategories')->willReturnCallback(function ($sku, $ids) {
            if ($this->assignThrows) {
                throw new \RuntimeException('link failure');
            }
            $this->assigned[] = [$sku, $ids];
            return true;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deleted[] = [$table, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($t) => 'pfx_' . $t);

        return new CategoryIdsStrategy($links, $resource);
    }

    public function testProductWithoutSkuIsIgnored(): void
    {
        $product = $this->product(['entity_id' => 5, 'sku' => '']);

        $this->strategy()->apply($product, 'category_ids', '3,4');

        $this->assertSame([], $this->assigned);
        $this->assertSame([], $this->deleted);
        $this->assertNull($product->getData('category_ids'));
    }

    public static function emptyValues(): array
    {
        return [
            'marker' => ['__empty__'],
            'empty array' => [[]],
            'null' => [null],
            'empty string' => [''],
            'zero string' => ['0'],
            'only invalid ids' => ['0,-3,abc'],
            'array of invalid ids' => [['0', 'x']],
        ];
    }

    #[DataProvider('emptyValues')]
    public function testEmptyValuesClearCategoryLinks(mixed $value): void
    {
        $product = $this->product(['entity_id' => 9, 'sku' => 'SKU-9']);

        $this->strategy()->apply($product, 'category_ids', $value);

        $this->assertSame([['pfx_catalog_category_product', ['product_id = ?' => 9]]], $this->deleted);
        $this->assertSame([], $this->assigned);
        $this->assertSame([], $product->getData('category_ids'));
    }

    public function testClearingANewProductSkipsTheDelete(): void
    {
        $product = $this->product(['entity_id' => 0, 'sku' => 'NEW']);

        $this->strategy()->apply($product, 'category_ids', '__empty__');

        $this->assertSame([], $this->deleted);
        $this->assertSame([], $product->getData('category_ids'));
    }

    public function testCommaSeparatedIdsAreTrimmedDedupedAndAssigned(): void
    {
        $product = $this->product(['entity_id' => 3, 'sku' => 'SKU-3']);

        $this->strategy()->apply($product, 'category_ids', ' 4, 7 ,4,0,x');

        $this->assertSame([['SKU-3', [4, 7]]], $this->assigned);
        $this->assertSame([4, 7], $product->getData('category_ids'));
    }

    public function testArrayIdsAreNormalisedAndAssigned(): void
    {
        $product = $this->product(['entity_id' => 3, 'sku' => 'SKU-3']);

        $this->strategy()->apply($product, 'category_ids', ['12', 12, '5', -1]);

        $this->assertSame([['SKU-3', [12, 5]]], $this->assigned);
        $this->assertSame([12, 5], $product->getData('category_ids'));
    }

    public function testLinkFailureStillSetsIdsOnProduct(): void
    {
        $this->assignThrows = true;
        $product = $this->product(['entity_id' => 3, 'sku' => 'SKU-3']);

        $this->strategy()->apply($product, 'category_ids', '8');

        $this->assertSame([8], $product->getData('category_ids'));
        $this->assertSame([], $this->deleted);
    }
}

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit\Strategy;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Exception\LocalizedException;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\ImageRoleStrategy;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageRoleStrategyTest extends TestCase
{
    use DbDoublesTrait;

    private array $inserts = [];
    private array $upserts = [];
    /** @var array<int,mixed> queue of fetchOne results */
    private array $fetchQueue = [];
    private array $fetchSql = [];
    private array $attributeIds = ['media_gallery' => 90, 'thumbnail' => 31, 'image' => 32, 'small_image' => 33];
    private bool $linkFieldThrows = false;
    private string $linkField = 'row_id';

    private function strategy(): ImageRoleStrategy
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) {
            $this->fetchSql[] = [is_string($sql) ? $sql : 'select', $bind];
            return array_shift($this->fetchQueue);
        });
        $connection->method('insert')->willReturnCallback(function ($table, $data) {
            $this->inserts[] = [$table, $data];
            return 1;
        });
        $connection->method('lastInsertId')->willReturn('555');
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, $data, $fields = []) {
            $this->upserts[] = [$table, $data, $fields];
            return 1;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($t) => $t);

        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($entity, $code) {
            $backend = $this->createStub(AbstractBackend::class);
            $backend->method('getTable')->willReturn('catalog_product_entity_varchar');
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn($this->attributeIds[$code] ?? null);
            $attribute->method('getBackend')->willReturn($backend);
            return $attribute;
        });
        $eav->method('getEntityType')->willReturnCallback(function () {
            if ($this->linkFieldThrows) {
                throw new \RuntimeException('no entity type');
            }
            $type = $this->createStub(Type::class);
            $type->method('__call')->willReturnCallback(
                fn ($name) => $name === 'getLinkField' ? $this->linkField : null
            );
            return $type;
        });

        return new ImageRoleStrategy($resource, $eav);
    }

    public function testProductWithoutIdIsIgnored(): void
    {
        $product = $this->product(['entity_id' => 0]);

        $this->strategy()->apply($product, 'thumbnail', '/a/b.jpg');

        $this->assertSame([], $this->upserts);
        $this->assertSame([], $this->inserts);
        $this->assertNull($product->getData('thumbnail'));
    }

    public function testEmptyPathWritesNoSelectionToTheRole(): void
    {
        $this->fetchQueue = [77];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'panth_thumbnail', '  ');

        $this->assertSame('no_selection', $product->getData('thumbnail'));
        $this->assertCount(1, $this->upserts);
        [$table, $data, $fields] = $this->upserts[0];
        $this->assertSame('catalog_product_entity_varchar', $table);
        $this->assertSame(['attribute_id' => 31, 'store_id' => 0, 'row_id' => 77, 'value' => 'no_selection'], $data);
        $this->assertSame(['value'], $fields);
        $this->assertSame([4], $this->fetchSql[0][1]);
        $this->assertStringContainsString('`row_id`', $this->fetchSql[0][0]);
    }

    public static function invalidPaths(): array
    {
        return [
            'parent traversal' => ['../etc/passwd'],
            'nested traversal' => ['/a/../b.jpg'],
            'current dir segment' => ['/a/./b.jpg'],
            'scheme' => ['http://host/x.jpg'],
            'backslash' => ['a\\b.jpg'],
            'control char' => ["a\nb.jpg"],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $product = $this->product(['entity_id' => 4]);

        $this->expectException(LocalizedException::class);
        $this->strategy()->apply($product, 'image', $path);
    }

    public function testExistingGalleryEntryIsReusedAndRoleWritten(): void
    {
        $this->fetchQueue = [321, 77];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'image', 'a/b/c.jpg');

        $this->assertSame('/a/b/c.jpg', $product->getData('image'));
        $this->assertSame([], $this->inserts);
        $this->assertCount(1, $this->upserts);
        $this->assertSame('/a/b/c.jpg', $this->upserts[0][1]['value']);
        $this->assertSame(32, $this->upserts[0][1]['attribute_id']);
    }

    public function testMissingGalleryEntryIsCreatedAndLinked(): void
    {
        $this->fetchQueue = [0, 0, 77];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'small_image', '/x/y.png');

        $this->assertSame([[
            'catalog_product_entity_media_gallery',
            ['attribute_id' => 90, 'value' => '/x/y.png', 'media_type' => 'image', 'disabled' => 0],
        ]], $this->inserts);
        $tables = array_map(static fn ($u) => $u[0], $this->upserts);
        $this->assertSame([
            'catalog_product_entity_media_gallery_value_to_entity',
            'catalog_product_entity_media_gallery_value',
            'catalog_product_entity_varchar',
        ], $tables);
        $this->assertSame(['value_id' => 555, 'entity_id' => 4], $this->upserts[0][1]);
        $this->assertSame(555, $this->upserts[1][1]['value_id']);
        $this->assertSame('/x/y.png', $product->getData('small_image'));
    }

    public function testGlobalGalleryValueIsLinkedWithoutNewInsert(): void
    {
        $this->fetchQueue = [0, 444, 77];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'image', '/x/y.png');

        $this->assertSame([], $this->inserts);
        $this->assertSame(444, $this->upserts[0][1]['value_id']);
    }

    public function testRoleWithoutAttributeIsNotWrittenButProductUpdated(): void
    {
        $this->fetchQueue = [321];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'swatch_image', '/s.png');

        $this->assertSame([], $this->upserts);
        $this->assertSame('/s.png', $product->getData('swatch_image'));
    }

    public function testMissingCatalogRowSkipsRoleWrite(): void
    {
        $this->fetchQueue = [0];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'thumbnail', 'no_selection');

        $this->assertSame([], $this->upserts);
        $this->assertSame('no_selection', $product->getData('thumbnail'));
    }

    public function testLinkFieldFallsBackToEntityId(): void
    {
        $this->linkFieldThrows = true;
        $this->fetchQueue = [12];
        $product = $this->product(['entity_id' => 4]);

        $this->strategy()->apply($product, 'thumbnail', '');

        $this->assertArrayHasKey('entity_id', $this->upserts[0][1]);
        $this->assertSame(12, $this->upserts[0][1]['entity_id']);
    }
}

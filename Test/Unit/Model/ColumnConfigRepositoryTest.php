<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AdvancedProductGrid\Model\ColumnConfig\Entity;
use Panth\AdvancedProductGrid\Model\ColumnConfig\EntityFactory;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig as Resource;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig\Collection;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig\CollectionFactory;
use PHPUnit\Framework\TestCase;

class ColumnConfigRepositoryTest extends TestCase
{
    /** @var array<string,array> rows keyed by attribute code */
    private array $rows = [];
    private int $collectionsCreated = 0;
    private array $filters = [];
    private array $savedEntities = [];
    private array $deletedEntities = [];
    private array $tableDeletes = [];
    private array $created = [];

    private function entity(array $data = []): Entity
    {
        $entity = self::getStubBuilder(Entity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getStub();
        $entity->method('getId')->willReturnCallback(static fn () => $entity->getData('entity_id'));
        $entity->setData($data);
        return $entity;
    }

    private function repository(): ColumnConfigRepository
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $this->collectionsCreated++;
            $filter = null;
            $collection = $this->createStub(Collection::class);
            $collection->method('getIterator')->willReturnCallback(
                fn () => new \ArrayIterator(array_map(static fn ($r) => new DataObject($r), array_values($this->rows)))
            );
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $value) use ($collection, &$filter) {
                    $this->filters[] = [$field, $value];
                    $filter = $value;
                    return $collection;
                }
            );
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getFirstItem')->willReturnCallback(function () use (&$filter) {
                return $this->entity($this->rows[$filter] ?? []);
            });
            return $collection;
        });

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('create')->willReturnCallback(function () {
            $entity = $this->entity();
            $this->created[] = $entity;
            return $entity;
        });

        $resource = $this->createStub(Resource::class);
        $resource->method('save')->willReturnCallback(function ($entity) use ($resource) {
            $this->savedEntities[] = $entity->getData();
            return $resource;
        });
        $resource->method('delete')->willReturnCallback(function ($entity) use ($resource) {
            $this->deletedEntities[] = $entity->getData('attribute_code');
            return $resource;
        });
        $resource->method('getMainTable')->willReturn('panth_product_grid_column_config');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function ($table) {
            $this->tableDeletes[] = $table;
            return 3;
        });
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        return new ColumnConfigRepository($factory, $entityFactory, $resource, $resourceConnection);
    }

    public function testGetAllIndexesRowsByCodeAndCachesTheResult(): void
    {
        $this->rows = [
            'name' => ['entity_id' => 1, 'attribute_code' => 'name', 'custom_label' => 'Title'],
            'price' => ['entity_id' => 2, 'attribute_code' => 'price', 'is_visible' => 0],
        ];
        $repository = $this->repository();

        $all = $repository->getAll();
        $repository->getAll();

        $this->assertSame(['name', 'price'], array_keys($all));
        $this->assertSame('Title', $all['name']['custom_label']);
        $this->assertSame(1, $this->collectionsCreated);
        $this->assertSame(0, $repository->getForAttribute('price')['is_visible']);
        $this->assertNull($repository->getForAttribute('sku'));
    }

    public function testSaveCreatesNewEntityAndIgnoresAttributeCodeInPayload(): void
    {
        $repository = $this->repository();

        $repository->save('color', ['attribute_code' => 'hijack', 'is_visible' => 1, 'custom_label' => 'Colour']);

        $this->assertSame(
            [['attribute_code' => 'color', 'is_visible' => 1, 'custom_label' => 'Colour']],
            $this->savedEntities
        );
        $this->assertCount(1, $this->created);
        $this->assertSame([['attribute_code', 'color']], $this->filters);
    }

    public function testSaveUpdatesExistingEntityAndInvalidatesCache(): void
    {
        $this->rows = ['color' => ['entity_id' => 9, 'attribute_code' => 'color', 'is_visible' => 0]];
        $repository = $this->repository();
        $repository->getAll();

        $repository->save('color', ['is_visible' => 1]);
        $repository->getAll();

        $this->assertSame([], $this->created);
        $this->assertSame(
            [['entity_id' => 9, 'attribute_code' => 'color', 'is_visible' => 1]],
            $this->savedEntities
        );
        $this->assertSame(3, $this->collectionsCreated, 'getAll, loadByCode, getAll after invalidation');
    }

    public function testDeleteRemovesExistingEntityOnly(): void
    {
        $this->rows = ['color' => ['entity_id' => 9, 'attribute_code' => 'color']];
        $repository = $this->repository();

        $repository->delete('missing');
        $repository->delete('color');

        $this->assertSame(['color'], $this->deletedEntities);
    }

    public function testResetAllTruncatesTheMainTableAndClearsCache(): void
    {
        $this->rows = ['color' => ['entity_id' => 9, 'attribute_code' => 'color']];
        $repository = $this->repository();
        $repository->getAll();

        $repository->resetAll();
        $repository->getAll();

        $this->assertSame(['panth_product_grid_column_config'], $this->tableDeletes);
        $this->assertSame(2, $this->collectionsCreated);
    }
}

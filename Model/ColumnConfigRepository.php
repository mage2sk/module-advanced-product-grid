<?php
/**
 * Read/write API for per-attribute grid column overrides.
 *
 * Caches the loaded set per-request — admin grid renders touch this
 * dozens of times during a single Page render, so we avoid one query
 * per column.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model;

use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedProductGrid\Model\ColumnConfig\Entity;
use Panth\AdvancedProductGrid\Model\ColumnConfig\EntityFactory;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig as Resource;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig\CollectionFactory;

class ColumnConfigRepository
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly EntityFactory $entityFactory,
        private readonly Resource $resource,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach ($this->collectionFactory->create() as $row) {
            $out[(string)$row->getData(Entity::ATTRIBUTE_CODE)] = $row->getData();
        }
        return $this->cache = $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getForAttribute(string $attributeCode): ?array
    {
        $all = $this->getAll();
        return $all[$attributeCode] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $attributeCode, array $payload): void
    {
        $entity = $this->loadByCode($attributeCode);
        if ($entity === null) {
            $entity = $this->entityFactory->create();
            $entity->setData(Entity::ATTRIBUTE_CODE, $attributeCode);
        }
        foreach ($payload as $key => $value) {
            if ($key === Entity::ATTRIBUTE_CODE) {
                continue;
            }
            $entity->setData($key, $value);
        }
        $this->resource->save($entity);
        $this->cache = null;
    }

    public function delete(string $attributeCode): void
    {
        $entity = $this->loadByCode($attributeCode);
        if ($entity === null) {
            return;
        }
        $this->resource->delete($entity);
        $this->cache = null;
    }

    public function resetAll(): void
    {
        $conn = $this->resourceConnection->getConnection();
        $conn->delete($this->resource->getMainTable());
        $this->cache = null;
    }

    private function loadByCode(string $code): ?Entity
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter(Entity::ATTRIBUTE_CODE, $code)
            ->setPageSize(1);
        $entity = $collection->getFirstItem();
        return $entity->getId() ? $entity : null;
    }
}

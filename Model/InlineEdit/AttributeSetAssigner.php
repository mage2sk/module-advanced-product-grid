<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

class AttributeSetAssigner
{
    private array $alreadyAssigned = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function ensure(int $attributeSetId, string $attributeCode): void
    {
        if ($attributeSetId <= 0 || $attributeCode === '') {
            return;
        }

        $attribute = $this->loadAttribute($attributeCode);
        if ($attribute === null) {
            return;
        }
        $attributeId = (int)$attribute->getId();
        $cacheKey = $attributeSetId . ':' . $attributeId;
        if (isset($this->alreadyAssigned[$cacheKey])) {
            return;
        }

        $conn = $this->resource->getConnection();
        $eaTable = $this->resource->getTableName('eav_entity_attribute');

        $existing = (int)$conn->fetchOne(
            "SELECT entity_attribute_id FROM {$eaTable} WHERE attribute_set_id = ? AND attribute_id = ?",
            [$attributeSetId, $attributeId]
        );
        if ($existing > 0) {
            $this->alreadyAssigned[$cacheKey] = true;
            return;
        }

        $groupId = $this->resolveDefaultGroupId($attributeSetId);
        if ($groupId === 0) {
            return;
        }

        try {
            $conn->insert($eaTable, [
                'entity_type_id'    => (int)$attribute->getEntityTypeId(),
                'attribute_set_id'  => $attributeSetId,
                'attribute_group_id'=> $groupId,
                'attribute_id'      => $attributeId,
                'sort_order'        => 100,
            ]);
            $this->alreadyAssigned[$cacheKey] = true;
        } catch (\Throwable) {
            $this->alreadyAssigned[$cacheKey] = true;
        }
    }

    private function loadAttribute(string $code): ?\Magento\Eav\Model\Entity\Attribute\AbstractAttribute
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Throwable) {
            return null;
        }
        return ($attribute && $attribute->getId()) ? $attribute : null;
    }

    private function resolveDefaultGroupId(int $attributeSetId): int
    {
        $conn = $this->resource->getConnection();
        $eagTable = $this->resource->getTableName('eav_attribute_group');

        $defaultId = (int)$conn->fetchOne(
            "SELECT attribute_group_id
             FROM {$eagTable}
             WHERE attribute_set_id = ? AND default_id = 1
             LIMIT 1",
            [$attributeSetId]
        );
        if ($defaultId > 0) {
            return $defaultId;
        }
        return (int)$conn->fetchOne(
            "SELECT attribute_group_id
             FROM {$eagTable}
             WHERE attribute_set_id = ?
             ORDER BY sort_order, attribute_group_id
             LIMIT 1",
            [$attributeSetId]
        );
    }
}

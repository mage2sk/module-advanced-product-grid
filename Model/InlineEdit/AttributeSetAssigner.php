<?php
/**
 * Ensures an attribute exists in a product's attribute set before
 * inline-edit attempts to write a value to it.
 *
 * Problem:
 *   When admin creates a new custom EAV attribute and only assigns
 *   it to ONE attribute set (e.g. "Downloadable"), and then opens
 *   the grid showing products from OTHER sets (e.g. "Bag"), the
 *   column renders empty. Saving from the grid silently no-ops at
 *   the EAV layer because Magento refuses to persist a value for
 *   an attribute that isn't assigned to that product's set.
 *
 * Solution:
 *   On every inline-edit write, check whether the attribute is in
 *   the product's set. If not, lazily assign it to the set's first
 *   group so the subsequent setData()/save() actually persists.
 *   Idempotent and per-set memoized so a 500-row mass edit doesn't
 *   thrash the EAV tables.
 *
 *   This is bounded to user-driven inline edits — bulk imports,
 *   API calls, and the standard product form still go through
 *   Magento's normal validation.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

class AttributeSetAssigner
{
    /** @var array<string, true> "<setId>:<attrId>" cache of completed assignments. */
    private array $alreadyAssigned = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Ensure $attributeCode is wired into the attribute set referenced
     * by $attributeSetId. Returns silently if already present, or if
     * the attribute itself doesn't exist (the strategy will then fail
     * cleanly downstream).
     */
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

        // Fast existence check before any writes.
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
            // Concurrent insert / duplicate. Treat as success — the row
            // is there either way.
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

    /**
     * Resolve a sensible attribute group inside the set to drop the
     * attribute into. We prefer the set's default_id, falling back to
     * whichever group has the lowest sort_order so the attribute
     * surfaces near the top of the product form for visibility.
     */
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

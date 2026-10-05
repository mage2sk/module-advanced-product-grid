<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class ImageRoleStrategy implements StrategyInterface
{
    private const ROLE_ATTRIBUTES = [
        'panth_thumbnail' => 'thumbnail',
        'thumbnail' => 'thumbnail',
        'image' => 'image',
        'small_image' => 'small_image',
        'swatch_image' => 'swatch_image',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $role = self::ROLE_ATTRIBUTES[$attributeCode] ?? $attributeCode;
        $path = is_string($value) ? trim($value) : '';
        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return;
        }

        if ($path === '' || $path === 'no_selection') {
            $this->writeRoleAttribute($productId, $role, 'no_selection', (int)$product->getStoreId());
            $product->setData($role, 'no_selection');
            return;
        }

        $path = '/' . ltrim($path, '/');
        if (preg_match('#[\\\\\x00-\x1F]|:/|(^|/)\.\.?(/|$)#', $path)) {
            throw new LocalizedException(__('The image path "%1" is not valid.', $path));
        }
        $valueId = $this->ensureGalleryEntry($productId, $path);
        $this->writeRoleAttribute($productId, $role, $path, (int)$product->getStoreId());
        $product->setData($role, $path);
    }

    private function ensureGalleryEntry(int $productId, string $file): int
    {
        $conn = $this->resource->getConnection();
        $gallery = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $valueToEntity = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $valuePerStore = $this->resource->getTableName('catalog_product_entity_media_gallery_value');

        $attributeId = (int)$this->eavConfig
            ->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'media_gallery')
            ->getAttributeId();

        $select = $conn->select()
            ->from(['g' => $gallery], ['value_id'])
            ->join(['vte' => $valueToEntity], 'vte.value_id = g.value_id', [])
            ->where('g.attribute_id = ?', $attributeId)
            ->where('g.value = ?', $file)
            ->where('vte.entity_id = ?', $productId);
        $existing = (int)$conn->fetchOne($select);
        if ($existing > 0) {
            return $existing;
        }

        $globalSelect = $conn->select()
            ->from($gallery, ['value_id'])
            ->where('attribute_id = ?', $attributeId)
            ->where('value = ?', $file);
        $valueId = (int)$conn->fetchOne($globalSelect);
        if ($valueId === 0) {
            $conn->insert($gallery, [
                'attribute_id' => $attributeId,
                'value' => $file,
                'media_type' => 'image',
                'disabled' => 0,
            ]);
            $valueId = (int)$conn->lastInsertId($gallery);
        }

        $conn->insertOnDuplicate($valueToEntity, [
            'value_id' => $valueId,
            'entity_id' => $productId,
        ]);
        $conn->insertOnDuplicate($valuePerStore, [
            'value_id' => $valueId,
            'store_id' => 0,
            'entity_id' => $productId,
            'label' => null,
            'position' => 1,
            'disabled' => 0,
        ], ['label', 'position', 'disabled']);

        return $valueId;
    }

    private function writeRoleAttribute(int $productId, string $role, string $file, int $storeId): void
    {
        $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $role);
        if (!$attribute || !$attribute->getAttributeId()) {
            return;
        }
        $attributeId = (int)$attribute->getAttributeId();
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName($attribute->getBackend()->getTable());

        $linkField = '';
        try {
            $linkField = (string)$this->eavConfig
                ->getEntityType(\Magento\Catalog\Model\Product::ENTITY)
                ->getLinkField();
        } catch (\Throwable) {
        }
        if ($linkField === '') {
            $linkField = 'entity_id';
        }

        $cpeTable = $this->resource->getTableName('catalog_product_entity');
        $linkId = (int)$conn->fetchOne(
            sprintf('SELECT `%s` FROM %s WHERE entity_id = ? LIMIT 1', $linkField, $cpeTable),
            [$productId]
        );
        if ($linkId === 0) {
            return;
        }
        $conn->insertOnDuplicate($table, [
            'attribute_id' => $attributeId,
            'store_id' => 0,
            $linkField => $linkId,
            'value' => $file,
        ], ['value']);
    }
}

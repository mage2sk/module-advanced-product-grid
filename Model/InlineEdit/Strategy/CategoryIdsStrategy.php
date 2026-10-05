<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;

class CategoryIdsStrategy implements StrategyInterface
{
    public function __construct(
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly ResourceConnection $resource
    ) {
    }

    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $productId = (int)$product->getId();
        $sku = (string)$product->getSku();

        if ($sku === '') {
            return;
        }

        $isEmpty = $value === '__empty__'
            || $value === []
            || $value === null
            || $value === ''
            || $value === '0';

        if (!$isEmpty) {
            $ids = $this->normalizeIds($value);
            if ($ids === []) {
                $isEmpty = true;
            }
        }

        if ($isEmpty) {
            $this->clearProductCategories($productId);
            $product->setCategoryIds([]);
            return;
        }

        try {
            $this->categoryLinkManagement->assignProductToCategories($sku, $ids);
            $product->setCategoryIds($ids);
        } catch (\Throwable) {
            $product->setCategoryIds($ids);
        }
    }

    private function normalizeIds(mixed $value): array
    {
        $ids = [];
        if (is_array($value)) {
            foreach ($value as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } elseif (is_string($value) && $value !== '') {
            foreach (explode(',', $value) as $id) {
                $id = (int)trim($id);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function clearProductCategories(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        $conn = $this->resource->getConnection();
        $conn->delete(
            $this->resource->getTableName('catalog_category_product'),
            ['product_id = ?' => $productId]
        );
    }
}

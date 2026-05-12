<?php
/**
 * Category assignment via Magento's CategoryLinkManagementInterface — the
 * proper service-contract API for add/remove. Empty-array assignments
 * delete the catalog_category_product rows. Direct DB delete is used as
 * a fallback when the API short-circuits on empty input.
 *
 * Inputs accepted:
 *   - array of category ID strings/ints
 *   - CSV string ("3,5,8")
 *   - "__empty__" sentinel (admin cleared every checkbox)
 *   - [] / null
 *
 * Diagnostic logging writes to var/log/panth_category_inline.log so we
 * can verify what arrives at the strategy on each save.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit\Strategy;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;

class CategoryIdsStrategy implements StrategyInterface
{
    public function __construct(
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem
    ) {
    }

    public function apply(ProductInterface $product, string $attributeCode, mixed $value): void
    {
        $productId = (int)$product->getId();
        $sku = (string)$product->getSku();
        $this->log(sprintf(
            'apply called: attr=%s pid=%d sku=%s value=%s',
            $attributeCode,
            $productId,
            $sku,
            json_encode($value)
        ));

        if ($sku === '') {
            $this->log('skip: empty sku');
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
            $this->log("clearing all categories for product $productId");
            $this->clearProductCategories($productId);
            $product->setCategoryIds([]);
            $this->log('clear done');
            return;
        }

        $this->log('assigning ' . count($ids) . ' categories: ' . json_encode($ids));
        try {
            $this->categoryLinkManagement->assignProductToCategories($sku, $ids);
            $product->setCategoryIds($ids);
            $this->log('assign success');
        } catch (\Throwable $e) {
            $this->log('assignProductToCategories failed: ' . $e->getMessage());
            $product->setCategoryIds($ids);
        }
    }

    /**
     * @return list<int>
     */
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
        $deleted = $conn->delete(
            $this->resource->getTableName('catalog_category_product'),
            ['product_id = ?' => $productId]
        );
        $this->log("DB delete catalog_category_product where product_id=$productId rows=$deleted");
    }

    private function log(string $message): void
    {
        try {
            $dir = $this->filesystem->getDirectoryWrite(DirectoryList::LOG);
            $dir->writeFile(
                'panth_category_inline.log',
                '[' . date('c') . '] ' . $message . PHP_EOL,
                'a'
            );
        } catch (\Throwable) {
            // swallow log failures
        }
    }
}

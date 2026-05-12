<?php
/**
 * Coordinates a batch of inline edits: loads each product once, applies
 * each (attributeCode, value) via the strategy registry, validates,
 * saves and runs the optional URL-rewrite refresh.
 *
 * Returns a structured result so the controller doesn't need to know
 * about products at all.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\InlineEdit;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogUrlRewrite\Observer\ProductProcessUrlRewriteSavingObserver;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;

class Processor
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StrategyResolver $strategyResolver,
        private readonly EventManager $eventManager,
        private readonly AttributeSetAssigner $attributeSetAssigner
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success: list<int>, errors: array<int, string>}
     */
    public function process(array $items, int $storeId): array
    {
        $success = [];
        $errors = [];

        foreach ($items as $productId => $changes) {
            $productId = (int)$productId;
            if ($productId <= 0 || !is_array($changes)) {
                continue;
            }
            try {
                $product = $this->loadProduct($productId, $storeId);
                // Ensure every attribute being written lives in this
                // product's set BEFORE the save runs, otherwise EAV
                // drops the value silently. See AttributeSetAssigner.
                $attributeSetId = (int)$product->getAttributeSetId();
                foreach (array_keys($changes) as $code) {
                    if (is_string($code) && $code !== '' && !str_starts_with($code, 'panth_')) {
                        $this->attributeSetAssigner->ensure($attributeSetId, $code);
                    }
                }
                foreach ($changes as $attributeCode => $value) {
                    if ($attributeCode === '' || !is_string($attributeCode)) {
                        continue;
                    }
                    $strategy = $this->strategyResolver->resolve($attributeCode);
                    $strategy->apply($product, $attributeCode, $value);
                }
                $this->productRepository->save($product);
                if ($product->getData('panth_request_url_rewrite_refresh')) {
                    $this->refreshUrlRewrite($product);
                }
                $success[] = $productId;
            } catch (NoSuchEntityException $e) {
                $errors[$productId] = (string)__('Product %1 does not exist.', $productId);
            } catch (LocalizedException $e) {
                $errors[$productId] = $e->getMessage();
            } catch (\Throwable $e) {
                $errors[$productId] = $e->getMessage();
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * Always load with editMode=true AND forceReload=true.
     *
     * Without forceReload, ProductRepository returns a cached product
     * instance that may have been loaded earlier (e.g. by the grid's
     * data provider) without every EAV attribute the user is now
     * editing. setData() on a not-loaded attribute mutates the model
     * in memory, but Magento's resource save() compares against the
     * `_origData` snapshot — if the field never appeared there, the
     * change is filtered out and the save is a silent no-op. Forcing
     * a fresh, edit-mode load makes every assigned attribute part of
     * `_origData`, so writes always persist.
     */
    private function loadProduct(int $productId, int $storeId): ProductInterface
    {
        $product = $this->productRepository->getById($productId, true, $storeId, true);
        $product->setStoreId($storeId === 0 ? Store::DEFAULT_STORE_ID : $storeId);
        return $product;
    }

    private function refreshUrlRewrite(ProductInterface $product): void
    {
        $this->eventManager->dispatch('panth_product_grid_url_rewrite_refresh', [
            'product' => $product,
        ]);
    }
}

<?php
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
        private readonly AttributeSetAssigner $attributeSetAssigner,
        private readonly ChangeValidator $changeValidator
    ) {
    }

    public function process(array $items, int $storeId): array
    {
        $success = [];
        $errors = [];

        foreach ($items as $productId => $changes) {
            $productId = (int)$productId;
            if ($productId <= 0 || !is_array($changes)) {
                continue;
            }
            if (array_key_exists('entity_id', $changes) && is_scalar($changes['entity_id'])
                && (int)$changes['entity_id'] === $productId
            ) {
                unset($changes['entity_id']);
            }
            $changes = $this->changeValidator->normalize($changes);
            $validationError = $this->changeValidator->validate($changes);
            if ($validationError !== null) {
                $errors[$productId] = $validationError;
                continue;
            }
            try {
                $product = $this->loadProduct($productId, $storeId);

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

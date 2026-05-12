<?php
/**
 * GET endpoint that returns the current value + option list for a single
 * (productId, attributeCode) cell, so the popup-cell editor can render
 * itself with up-to-date data.
 *
 * Used by: textarea / multiselect / tier_price / categories / image
 * cells in the grid. Each click-to-open triggers a fresh GET so the
 * modal always reflects the latest stored value (rather than stale
 * rendering data).
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\CellEdit;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;

class Form extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::inline_edit';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $productId = (int)$this->getRequest()->getParam('product_id');
        $code = trim((string)$this->getRequest()->getParam('attribute_code'));
        if ($productId <= 0 || $code === '') {
            return $result->setData(['success' => false, 'error' => (string)__('Missing product_id or attribute_code.')]);
        }
        try {
            $product = $this->productRepository->getById($productId, false);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => (string)__('Product not found.')]);
        }

        $value = null;
        $options = [];
        $editorType = 'text';
        $label = $code;

        if ($code === 'category_ids' || $code === 'panth_categories') {
            $value = $product->getCategoryIds();
            $editorType = 'multiselect';
            $label = (string)__('Categories');
            $options = $this->collectCategoryOptions();
        } elseif ($code === 'tier_price' || $code === 'panth_tier_price_label') {
            $rows = [];
            foreach ((array)$product->getTierPrices() as $tier) {
                $rows[] = [
                    'website_id' => (int)($tier->getExtensionAttributes()?->getWebsiteId() ?? 0),
                    'cust_group' => (int)$tier->getCustomerGroupId(),
                    'qty' => (float)$tier->getQty(),
                    'value' => (float)$tier->getValue(),
                    'value_type' => $tier->getExtensionAttributes()?->getPercentageValue() !== null ? 'percent' : 'fixed',
                ];
            }
            $value = $rows;
            $editorType = 'tier_price';
            $label = (string)__('Tier Prices');
            $options = [
                'websites' => $this->collectWebsiteOptions(),
                'customer_groups' => $this->collectCustomerGroupOptions(),
            ];
        } else {
            $value = $product->getData($code);
            try {
                $attribute = $this->attributeRepository->get('catalog_product', $code);
                $label = (string)$attribute->getDefaultFrontendLabel() ?: $code;
                $input = (string)$attribute->getFrontendInput();
                $editorType = match ($input) {
                    'textarea' => 'textarea',
                    'multiselect' => 'multiselect',
                    'select', 'boolean' => 'select',
                    'media_image' => 'image',
                    default => 'text',
                };
                if (in_array($input, ['select', 'multiselect', 'boolean'], true)) {
                    foreach ((array)$attribute->getOptions() as $opt) {
                        if ($opt->getValue() === null || $opt->getValue() === '') {
                            continue;
                        }
                        $options[] = ['value' => $opt->getValue(), 'label' => (string)$opt->getLabel()];
                    }
                }
            } catch (\Throwable) {
                // Attribute lookup failed — fall through to text editor.
            }
        }

        if (is_array($value)) {
            $valueOut = $value;
        } elseif ($value === null) {
            $valueOut = '';
        } else {
            $valueOut = (string)$value;
        }

        return $result->setData([
            'success' => true,
            'product_id' => $productId,
            'attribute_code' => $code,
            'label' => $label,
            'editor_type' => $editorType,
            'value' => $valueOut,
            'options' => $options,
        ]);
    }

    /**
     * @return list<array{value:int, label:string}>
     */
    private function collectWebsiteOptions(): array
    {
        $out = [['value' => 0, 'label' => (string)__('All Websites')]];
        try {
            foreach ($this->storeManager->getWebsites() as $website) {
                $out[] = ['value' => (int)$website->getId(), 'label' => (string)$website->getName()];
            }
        } catch (\Throwable) {
            // Fall through with just the default option.
        }
        return $out;
    }

    /**
     * Flattened category tree with full breadcrumb labels so admins
     * can locate categories by typing any segment in the popup filter.
     *
     * @return list<array{value:int, label:string}>
     */
    private function collectCategoryOptions(): array
    {
        try {
            $collection = $this->categoryCollectionFactory->create()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('path')
                ->addFieldToFilter('level', ['gt' => 1])
                ->setOrder('path', 'ASC');

            $nameById = [];
            foreach ($collection as $cat) {
                $nameById[(int)$cat->getId()] = (string)$cat->getName();
            }
            $out = [];
            foreach ($collection as $cat) {
                $labels = [];
                foreach (explode('/', (string)$cat->getPath()) as $segId) {
                    if ((int)$segId === 1) {
                        continue;
                    }
                    if (isset($nameById[(int)$segId])) {
                        $labels[] = $nameById[(int)$segId];
                    }
                }
                $out[] = [
                    'value' => (int)$cat->getId(),
                    'label' => implode(' > ', $labels) ?: (string)$cat->getName(),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{value:int, label:string}>
     */
    private function collectCustomerGroupOptions(): array
    {
        // 32000 is Magento's reserved id for "All Groups" / "Not Logged In" alias
        // used in tier-price storage when targeting every group.
        $out = [['value' => 32000, 'label' => (string)__('All Groups')]];
        try {
            $criteria = $this->searchCriteriaBuilder->create();
            foreach ($this->groupRepository->getList($criteria)->getItems() as $group) {
                $out[] = ['value' => (int)$group->getId(), 'label' => (string)$group->getCode()];
            }
        } catch (\Throwable) {
            // Fall through with just All Groups.
        }
        return $out;
    }
}

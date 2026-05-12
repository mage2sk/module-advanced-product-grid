<?php
/**
 * Block backing the tier-price modal. Exposes:
 *   - the websites available for the current product
 *   - the customer groups
 *   - the existing tier-price rows in a JSON-friendly shape
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Block\Adminhtml\Product\Grid\TierPrice;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;

class Content extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setProduct(ProductInterface $product): self
    {
        $this->setData('product', $product);
        return $this;
    }

    public function getProduct(): ?ProductInterface
    {
        $product = $this->getData('product');
        return $product instanceof ProductInterface ? $product : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWebsiteOptions(): array
    {
        $options = [['value' => 0, 'label' => (string)__('All Websites')]];
        foreach ($this->storeManager->getWebsites() as $website) {
            $options[] = ['value' => (int)$website->getId(), 'label' => (string)$website->getName()];
        }
        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCustomerGroupOptions(): array
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $options = [['value' => 32000, 'label' => (string)__('All Groups')]];
        foreach ($this->groupRepository->getList($criteria)->getItems() as $group) {
            $options[] = ['value' => (int)$group->getId(), 'label' => (string)$group->getCode()];
        }
        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTierPriceRows(): array
    {
        $product = $this->getProduct();
        if ($product === null) {
            return [];
        }
        $rows = [];
        foreach ((array)$product->getTierPrices() as $tier) {
            $rows[] = [
                'website_id' => (int)$tier->getExtensionAttributes()?->getWebsiteId() ?: 0,
                'cust_group' => (int)$tier->getCustomerGroupId(),
                'qty' => (float)$tier->getQty(),
                'value' => (float)$tier->getValue(),
                'value_type' => (string)($tier->getExtensionAttributes()?->getPercentageValue() !== null ? 'percent' : 'fixed'),
                'percentage_value' => (float)($tier->getExtensionAttributes()?->getPercentageValue() ?? 0.0),
            ];
        }
        return $rows;
    }
}

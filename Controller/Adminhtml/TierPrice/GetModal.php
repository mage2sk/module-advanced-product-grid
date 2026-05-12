<?php
/**
 * Returns the tier price modal block HTML for a given product.
 * Always responds with JSON so the JS modal can swap the body cleanly.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\TierPrice;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\LayoutFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;

class GetModal extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::tier_price';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly ProductRepositoryInterface $productRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $productId = (int)$this->getRequest()->getParam('product_id');
        if ($productId <= 0) {
            return $result->setData(['success' => false, 'error' => (string)__('Missing product_id.')]);
        }
        try {
            $product = $this->productRepository->getById($productId, false);
        } catch (NoSuchEntityException) {
            return $result->setData(['success' => false, 'error' => (string)__('Product not found.')]);
        }

        $layout = $this->layoutFactory->create();
        $block = $layout->createBlock(
            \Panth\AdvancedProductGrid\Block\Adminhtml\Product\Grid\TierPrice\Content::class,
            'panth_pg_tier_price_modal'
        );
        $block->setProduct($product);
        $block->setTemplate('Panth_AdvancedProductGrid::tier_prices.phtml');

        return $result->setData([
            'success' => true,
            'product_id' => $productId,
            'html' => $block->toHtml(),
            'tier_prices' => $block->getTierPriceRows(),
        ]);
    }
}

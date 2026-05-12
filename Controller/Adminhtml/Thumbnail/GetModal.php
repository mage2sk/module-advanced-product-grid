<?php
/**
 * Returns the image gallery modal HTML for a product. Reuses Magento's
 * standard ProductGallery + ProductVideo admin blocks so admins get the
 * same UX they're already used to from the product edit page.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\Thumbnail;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;

class GetModal extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::manage_gallery';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Registry $registry
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
            $product = $this->productRepository->getById($productId, true);
        } catch (NoSuchEntityException) {
            return $result->setData(['success' => false, 'error' => (string)__('Product not found.')]);
        }

        if (!$this->registry->registry('current_product')) {
            $this->registry->register('current_product', $product);
        }
        if (!$this->registry->registry('product')) {
            $this->registry->register('product', $product);
        }

        $layout = $this->layoutFactory->create();
        $images = [];
        foreach ((array)$product->getMediaGalleryEntries() as $entry) {
            $images[] = [
                'id' => $entry->getId(),
                'file' => $entry->getFile(),
                'label' => (string)$entry->getLabel(),
                'position' => (int)$entry->getPosition(),
                'disabled' => (int)$entry->isDisabled(),
                'types' => $entry->getTypes() ?? [],
                'url' => $entry->getFile() ? ($product->getMediaConfig()->getMediaUrl($entry->getFile())) : null,
            ];
        }

        return $result->setData([
            'success' => true,
            'product_id' => $productId,
            'images' => $images,
        ]);
    }
}

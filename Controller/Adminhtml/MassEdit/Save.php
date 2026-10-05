<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\MassEdit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\InlineEdit\Processor;

class Save extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Processor $processor,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly Filter $filter
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();
        $rawChanges = $request->getParam('changes', []);
        $storeId = (int)$request->getParam('store_id', 0);

        if (!is_array($rawChanges) || $rawChanges === []) {
            return $result->setData([
                'success' => false,
                'error' => (string)__('No attribute changes were provided.'),
            ]);
        }

        $denied = $this->findDeniedCodes(array_keys($rawChanges));
        if ($denied !== []) {
            return $result->setData([
                'success' => false,
                'error' => (string)__('You are not allowed to edit: %1', implode(', ', $denied)),
            ]);
        }

        try {
            $ids = $this->resolveSelectedIds();
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
        if ($ids === []) {
            return $result->setData([
                'success' => false,
                'error' => (string)__('No products were selected.'),
            ]);
        }

        $items = [];
        foreach ($ids as $id) {
            $items[(int)$id] = $rawChanges;
        }

        $outcome = $this->processor->process($items, $storeId);
        $successCount = count($outcome['success']);
        $errorCount = count($outcome['errors']);

        $messages = [];
        if ($successCount > 0) {
            $messages[] = (string)__('%1 product(s) updated.', $successCount);
        }
        foreach ($outcome['errors'] as $productId => $msg) {
            $messages[] = (string)__('Product %1: %2', $productId, $msg);
        }

        return $result->setData([
            'success' => $errorCount === 0,
            'updated' => $successCount,
            'errors' => $errorCount,
            'messages' => $messages,
        ]);
    }

    private function resolveSelectedIds(): array
    {
        $request = $this->getRequest();
        $explicit = $request->getParam('selected');
        if (is_array($explicit) && $explicit !== []) {
            return array_values(array_map('intval', $explicit));
        }
        if (is_string($explicit) && $explicit !== '') {
            return array_map('intval', explode(',', $explicit));
        }

        $collection = $this->productCollectionFactory->create();
        $this->filter->getCollection($collection);
        $ids = [];
        foreach ($collection->getAllIds() as $id) {
            $ids[] = (int)$id;
        }
        return $ids;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null;
    }
}

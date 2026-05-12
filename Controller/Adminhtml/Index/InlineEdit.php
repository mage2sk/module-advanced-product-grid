<?php
/**
 * AJAX endpoint that the JS editor posts to.
 *
 * Payload shape:
 *   items[<productId>][<attributeCode>] = value
 *   store_id = <int>
 *
 * Returns:
 *   { success: bool, messages: [], errors: { <productId>: msg, ... }, saved: [<productId>] }
 *
 * Errors are per-product so a bad cell on one row doesn't block the rest.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\Index;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\InlineEdit\Processor;

class InlineEdit extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Processor $processor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();
        $items = $request->getParam('items', []);
        $storeId = (int)$request->getParam('store_id', 0);

        if (!is_array($items) || $items === [] || !$request->getParam('isAjax')) {
            return $result->setData([
                'messages' => [(string)__('Please correct the data sent.')],
                'error' => true,
            ]);
        }

        $outcome = $this->processor->process($items, $storeId);
        $successCount = count($outcome['success']);
        $errorCount = count($outcome['errors']);
        $messages = [];

        if ($successCount > 0) {
            $messages[] = $successCount === 1
                ? (string)__('1 product updated successfully.')
                : (string)__('%1 products updated successfully.', $successCount);
        }
        foreach ($outcome['errors'] as $productId => $err) {
            $messages[] = (string)__('Product #%1: %2', $productId, $err);
        }
        if ($successCount === 0 && $errorCount === 0) {
            // Save reported success but nothing changed — usually
            // means EAV silently rejected the write. Surface it
            // instead of leaving the admin staring at a silent grid.
            $messages[] = (string)__('No changes were persisted. Check the attribute set assignment and try again.');
        }

        return $result->setData([
            'messages' => $messages,
            'error'    => $errorCount > 0,
            'success'  => $successCount,
            'failed'   => $errorCount,
        ]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}

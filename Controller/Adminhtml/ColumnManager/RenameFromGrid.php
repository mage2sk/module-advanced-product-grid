<?php
/**
 * Endpoint hit by the double-click-rename JS on grid column headers.
 *
 * Single attribute_code + custom_label POST → upsert in
 * panth_product_grid_column_config. Cheaper than going through the
 * full Column Manager InlineEdit controller for a single field.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class RenameFromGrid extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::column_manager';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ColumnConfigRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $code = trim((string)$this->getRequest()->getParam('attribute_code', ''));
        $label = trim((string)$this->getRequest()->getParam('custom_label', ''));
        if ($code === '' || $label === '') {
            return $result->setData(['success' => false, 'error' => (string)__('Attribute code and label are required.')]);
        }
        try {
            $this->repository->save($code, ['custom_label' => $label]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
        return $result->setData(['success' => true]);
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

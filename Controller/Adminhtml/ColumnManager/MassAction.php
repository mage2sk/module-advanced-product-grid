<?php
/**
 * Bulk operations for Column Manager: enable/disable visible, enable/disable
 * editable, enable/disable filterable, enable/disable in_export.
 *
 * Accepts `selected[]` of attribute codes + an `op` string identifying
 * the bulk operation.
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

class MassAction extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::column_manager';

    private const OPS = [
        'enable_visible' => ['is_visible' => 1],
        'disable_visible' => ['is_visible' => 0],
        'enable_editable' => ['is_editable' => 1],
        'disable_editable' => ['is_editable' => 0],
        'enable_filterable' => ['is_filterable' => 1],
        'disable_filterable' => ['is_filterable' => 0],
        'enable_export' => ['in_export' => 1],
        'disable_export' => ['in_export' => 0],
    ];

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
        $op = (string)$this->getRequest()->getParam('op', '');
        $selected = $this->getRequest()->getParam('selected', []);
        if (!isset(self::OPS[$op])) {
            return $result->setData(['success' => false, 'error' => (string)__('Unknown operation: %1', $op)]);
        }
        if (!is_array($selected) || $selected === []) {
            return $result->setData(['success' => false, 'error' => (string)__('No attributes were selected.')]);
        }
        $payload = self::OPS[$op];
        $errors = [];
        foreach ($selected as $code) {
            try {
                $this->repository->save((string)$code, $payload);
            } catch (\Throwable $e) {
                $errors[(string)$code] = $e->getMessage();
            }
        }
        return $result->setData([
            'success' => $errors === [],
            'updated' => count($selected) - count($errors),
            'errors' => $errors,
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

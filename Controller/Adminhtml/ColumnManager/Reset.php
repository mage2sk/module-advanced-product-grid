<?php
/**
 * Wipes every column config override and deletes the current user's
 * product_listing bookmarks so the grid restores to vanilla Magento
 * defaults. Useful when toggle state gets confused by a partial save.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\ColumnManager;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class Reset extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::column_manager';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ColumnConfigRepository $repository,
        private readonly ResourceConnection $resource,
        private readonly AuthSession $authSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $this->repository->resetAll();
            $this->clearBookmarks();
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
        return $result->setData(['success' => true, 'message' => (string)__('All column overrides cleared.')]);
    }

    /**
     * Drop the current admin's product_listing bookmarks so visibility +
     * sort + width all revert to vanilla XML defaults on next load.
     */
    private function clearBookmarks(): void
    {
        $userId = (int)($this->authSession->getUser()?->getId() ?? 0);
        if ($userId <= 0) {
            return;
        }
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('ui_bookmark');
        if (!$conn->isTableExists($table)) {
            return;
        }
        $conn->delete($table, [
            'namespace = ?' => 'product_listing',
            'user_id = ?' => $userId,
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

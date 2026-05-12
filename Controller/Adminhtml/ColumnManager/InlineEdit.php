<?php
/**
 * Saves Column Manager inline edits — per-attribute toggles for
 * visible/editable/filterable/in_export + custom_label + sort_order +
 * marker_color + editor_type_override.
 *
 * For is_visible changes, also syncs the admin user's bookmark so the
 * grid actually shows/hides the column on next render (otherwise the DB
 * row alone has no effect because bookmark visibility is the source of
 * truth for the rendered grid).
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
use Panth\AdvancedProductGrid\Model\BookmarkVisibilityUpdater;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class InlineEdit extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::column_manager';

    private const ALLOWED_FIELDS = [
        'is_visible', 'is_editable', 'is_filterable', 'in_export',
        'custom_label', 'sort_order', 'default_width', 'marker_color',
        'editor_type_override',
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ColumnConfigRepository $repository,
        private readonly BookmarkVisibilityUpdater $bookmarkUpdater
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $items = $this->getRequest()->getParam('items', []);
        if (!is_array($items) || $items === [] || !$this->getRequest()->getParam('isAjax')) {
            return $result->setData([
                'messages' => [(string)__('Please correct the data sent.')],
                'error' => true,
            ]);
        }

        $errors = [];
        $visibilityChanges = [];
        $positionChanges = [];
        foreach ($items as $attributeCode => $changes) {
            if (!is_array($changes)) {
                continue;
            }
            $payload = [];
            foreach ($changes as $field => $value) {
                if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                    continue;
                }
                if (in_array($field, ['is_visible', 'is_editable', 'is_filterable', 'in_export'], true)) {
                    $payload[$field] = (int)(bool)$value;
                } elseif ($field === 'sort_order' || $field === 'default_width') {
                    $payload[$field] = $value === '' || $value === null ? null : (int)$value;
                } else {
                    $payload[$field] = (string)$value;
                }
            }
            if ($payload === []) {
                continue;
            }
            if (array_key_exists('is_visible', $payload)) {
                $visibilityChanges[(string)$attributeCode] = $payload['is_visible'] === 1;
            }
            if (array_key_exists('sort_order', $payload) && $payload['sort_order'] !== null) {
                $positionChanges[(string)$attributeCode] = (int)$payload['sort_order'];
            }
            try {
                $this->repository->save((string)$attributeCode, $payload);
            } catch (\Throwable $e) {
                $errors[(string)$attributeCode] = $e->getMessage();
            }
        }

        if ($visibilityChanges !== [] || $positionChanges !== []) {
            try {
                $this->bookmarkUpdater->apply($visibilityChanges, $positionChanges);
            } catch (\Throwable $e) {
                $errors['__bookmark'] = $e->getMessage();
            }
        }

        $messages = [];
        if ($errors === []) {
            $messages[] = (string)__('Column configuration saved.');
        } else {
            foreach ($errors as $code => $msg) {
                $messages[] = (string)__('%1: %2', $code, $msg);
            }
        }
        return $result->setData([
            'messages' => $messages,
            'error' => $errors !== [],
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

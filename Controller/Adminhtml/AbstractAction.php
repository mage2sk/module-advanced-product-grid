<?php
/**
 * Common adminhtml ACL gate for every controller in this module.
 *
 * Every action either edits products or reads/writes product metadata,
 * so we gate on the inline-edit ACL by default and let subclasses tighten
 * via $_isAllowed override if needed.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedProductGrid::inline_edit';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}

<?php
/**
 * GET endpoint that the Mass Edit modal calls to fetch the grouped
 * attribute catalogue. Returns a JSON payload the KO modal renders into
 * its accordion of editable attributes.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Controller\Adminhtml\MassEdit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedProductGrid\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedProductGrid\Model\MassEdit\AttributeCatalog;

class Form extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AttributeCatalog $catalog
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $groups = $this->catalog->getGroupedAttributes();
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
        return $result->setData([
            'success' => true,
            'groups' => $groups,
        ]);
    }
}

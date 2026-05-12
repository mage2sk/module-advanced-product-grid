<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedProductGrid\Model\ColumnConfig\Entity;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig as Resource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'config_id';

    protected function _construct(): void
    {
        $this->_init(Entity::class, Resource::class);
    }
}

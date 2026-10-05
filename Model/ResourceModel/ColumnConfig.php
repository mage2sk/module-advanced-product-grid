<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ColumnConfig extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_product_grid_column_config', 'config_id');
    }
}

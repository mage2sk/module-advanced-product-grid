<?php
/**
 * Active-record model for a single row in panth_product_grid_column_config.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\ColumnConfig;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedProductGrid\Model\ResourceModel\ColumnConfig as Resource;

class Entity extends AbstractModel
{
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const IS_VISIBLE = 'is_visible';
    public const IS_EDITABLE = 'is_editable';
    public const IS_FILTERABLE = 'is_filterable';
    public const IN_EXPORT = 'in_export';
    public const IS_REQUIRED = 'is_required';
    public const CUSTOM_LABEL = 'custom_label';
    public const SORT_ORDER = 'sort_order';
    public const DEFAULT_WIDTH = 'default_width';
    public const MARKER_COLOR = 'marker_color';
    public const EDITOR_TYPE_OVERRIDE = 'editor_type_override';

    protected function _construct(): void
    {
        $this->_init(Resource::class);
    }
}

<?php
/**
 * System Config backend model — when qty-sold-affecting settings change
 * (statuses, include-refunded), mark the panth_product_grid_qty_sold
 * indexer as invalid so the next reindex picks the new rules up.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class QtySoldInvalidate extends Value
{
    public const INDEXER_ID = 'panth_product_grid_qty_sold';

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly IndexerRegistry $indexerRegistry,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave(): self
    {
        parent::afterSave();
        if ($this->isValueChanged()) {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);
            $indexer->invalidate();
        }
        return $this;
    }
}

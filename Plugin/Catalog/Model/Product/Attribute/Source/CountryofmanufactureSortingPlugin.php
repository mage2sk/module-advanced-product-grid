<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Model\Product\Attribute\Source;

use Magento\Catalog\Model\Product\Attribute\Source\Countryofmanufacture;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

class CountryofmanufactureSortingPlugin
{
    public function aroundAddValueSortToCollection(
        Countryofmanufacture $subject,
        \Closure $proceed,
        $collection,
        $dir = 'asc'
    ) {
        if (!$collection instanceof Collection) {
            return $proceed($collection, $dir);
        }
        $attribute = $subject->getAttribute();
        $attributeId = (int)$attribute->getId();
        $storeId = (int)$collection->getStoreId();
        $dir = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';

        $valueTable = $attribute->getBackend()->getTable();
        $select = $collection->getSelect();

        $select->joinLeft(
            ['panth_com_default' => $valueTable],
            "panth_com_default.entity_id = e.entity_id AND panth_com_default.attribute_id = $attributeId AND panth_com_default.store_id = 0",
            []
        );
        $select->joinLeft(
            ['panth_com_store' => $valueTable],
            "panth_com_store.entity_id = e.entity_id AND panth_com_store.attribute_id = $attributeId AND panth_com_store.store_id = $storeId",
            []
        );
        $select->order(
            new \Zend_Db_Expr("COALESCE(panth_com_store.value, panth_com_default.value) $dir")
        );
        return $collection;
    }
}

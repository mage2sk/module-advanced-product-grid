<?php
/**
 * Patches the Country of Manufacture sort so that products without a
 * store-scope value still sort against their default-scope value.
 * Otherwise sorting on country leaves a giant NULL block at the top.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Catalog\Model\Product\Attribute\Source;

use Magento\Catalog\Model\Product\Attribute\Source\Countryofmanufacture;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

class CountryofmanufactureSortingPlugin
{
    /**
     * @return Collection
     */
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

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Fixture;

use Magento\Catalog\Model\Product;
use Magento\Framework\DB\Select;

/**
 * Builds fluent Select stubs that record where/join/group/order calls,
 * plus partial Product stubs backed by real DataObject storage.
 */
trait DbDoublesTrait
{
    /** @var array<int,array{0:string,1:mixed}> */
    protected array $wheres = [];

    /** @var array<int,array{0:string,1:mixed,2:string}> */
    protected array $joins = [];

    protected array $groups = [];

    protected array $orders = [];

    protected function select(array $fromParts = []): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'limit', 'columns', 'reset'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        foreach (['joinLeft', 'joinInner'] as $method) {
            $select->method($method)->willReturnCallback(
                function ($name, $cond, $cols = '*') use ($select, $method) {
                    $this->joins[] = [$method, $name, $cond];
                    return $select;
                }
            );
        }
        $select->method('group')->willReturnCallback(function ($spec) use ($select) {
            $this->groups[] = $spec;
            return $select;
        });
        $select->method('order')->willReturnCallback(function ($spec) use ($select) {
            $this->orders[] = (string)$spec;
            return $select;
        });
        $select->method('getPart')->willReturn($fromParts);
        return $select;
    }

    protected function whereConditions(): array
    {
        return array_map(static fn (array $w) => $w[0], $this->wheres);
    }

    protected function product(array $data = []): Product
    {
        $product = self::getStubBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getId', 'getStoreId', 'getAttributeSetId', 'isObjectNew'])
            ->getStub();
        $product->method('getSku')->willReturnCallback(static fn () => $product->getData('sku'));
        $product->method('getId')->willReturnCallback(static fn () => $product->getData('entity_id'));
        $product->method('getStoreId')->willReturnCallback(static fn () => (int)$product->getData('store_id'));
        $product->method('getAttributeSetId')->willReturnCallback(
            static fn () => $product->getData('attribute_set_id')
        );
        $product->method('isObjectNew')->willReturnCallback(static fn () => (bool)$product->getData('_is_new'));
        $product->setData($data);
        return $product;
    }
}

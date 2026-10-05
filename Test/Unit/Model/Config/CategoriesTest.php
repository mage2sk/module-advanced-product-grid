<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\Config;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Model\Config\Source\Categories;
use PHPUnit\Framework\TestCase;

class CategoriesTest extends TestCase
{
    private array $cache = [];
    private array $saved = [];
    private int $collections = 0;
    private array $collectionCalls = [];

    private function source(array $categories, int $storeId = 2): Categories
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($categories, $storeId) {
            $this->collections++;
            $collection = $this->createStub(Collection::class);
            foreach (['addAttributeToSelect', 'setStore', 'addFieldToFilter', 'setOrder'] as $method) {
                $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                    $this->collectionCalls[] = [$method, $args];
                    return $collection;
                });
            }
            $collection->method('getIterator')->willReturnCallback(
                static fn () => new \ArrayIterator(array_map(static fn ($c) => new DataObject($c), $categories))
            );
            return $collection;
        });

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $key, $tags, $ttl) {
            $this->saved[] = [$key, json_decode($data, true), $tags, $ttl];
            return true;
        });

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Categories($factory, $cache, $storeManager);
    }

    public function testBuildsBreadcrumbLabelsAndCachesPerStore(): void
    {
        $options = $this->source([
            ['id' => 2, 'name' => 'Default Category', 'path' => '1/2'],
            ['id' => 3, 'name' => 'Men', 'path' => '1/2/3'],
            ['id' => 4, 'name' => 'Shirts', 'path' => '1/2/3/4'],
            ['id' => 9, 'name' => 'Orphan', 'path' => '1/2/77/9'],
        ])->toOptionArray();

        $this->assertSame('_none_', $options[0]['value']);
        $this->assertSame([
            ['value' => 2, 'label' => 'Default Category'],
            ['value' => 3, 'label' => 'Default Category > Men'],
            ['value' => 4, 'label' => 'Default Category > Men > Shirts'],
            ['value' => 9, 'label' => 'Default Category > Orphan'],
        ], array_slice($options, 1));
        $this->assertSame('panth_product_grid_categories_2', $this->saved[0][0]);
        $this->assertSame(['catalog_category', 'config_scopes'], $this->saved[0][2]);
        $this->assertSame(86400, $this->saved[0][3]);
        $this->assertContains(['setStore', [2]], $this->collectionCalls);
        $this->assertContains(['addFieldToFilter', ['level', ['gt' => 1]]], $this->collectionCalls);
    }

    public function testCachedOptionsAreReturnedWithoutQuerying(): void
    {
        $this->cache['panth_product_grid_categories_5'] = json_encode([['value' => 1, 'label' => 'Cached']]);

        $options = $this->source([], 5)->toOptionArray();

        $this->assertSame([['value' => 1, 'label' => 'Cached']], $options);
        $this->assertSame(0, $this->collections);
        $this->assertSame([], $this->saved);
    }

    public function testCorruptCacheIsRebuilt(): void
    {
        $this->cache['panth_product_grid_categories_2'] = 'garbage';

        $options = $this->source([['id' => 3, 'name' => 'Men', 'path' => '1/3']])->toOptionArray();

        $this->assertSame(1, $this->collections);
        $this->assertSame(['value' => 3, 'label' => 'Men'], $options[1]);
    }
}

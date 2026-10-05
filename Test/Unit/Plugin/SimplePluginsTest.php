<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Plugin;

use Magento\Catalog\Model\Product\Attribute\Source\Countryofmanufacture;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\ViewInterface;
use Panth\AdvancedProductGrid\Plugin\Catalog\Model\Product\Attribute\Source\CountryofmanufactureSortingPlugin;
use Panth\AdvancedProductGrid\Plugin\Mview\SalesConnectionPlugin;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\TestCase;

class SimplePluginsTest extends TestCase
{
    use DbDoublesTrait;

    private int $connectionLookups = 0;

    private function salesPlugin(): SalesConnectionPlugin
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturnCallback(function () {
            $this->connectionLookups++;
            return $this->createStub(AdapterInterface::class);
        });
        return new SalesConnectionPlugin($resource);
    }

    private function subscription(mixed $viewId, bool $throws = false): Subscription
    {
        $subscription = self::getStubBuilder(Subscription::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getView'])
            ->getStub();
        if ($throws) {
            $subscription->method('getView')->willThrowException(new \RuntimeException('no view'));
        } else {
            $view = $this->createStub(ViewInterface::class);
            $view->method('getId')->willReturn($viewId);
            $subscription->method('getView')->willReturn($view);
        }
        return $subscription;
    }

    public function testOtherViewsAreNotTouched(): void
    {
        $this->assertNull($this->salesPlugin()->beforeCreate($this->subscription('catalog_product_price')));
        $this->assertSame(0, $this->connectionLookups);
    }

    public function testBrokenViewIsTolerated(): void
    {
        $this->assertNull($this->salesPlugin()->beforeCreate($this->subscription(null, true)));
        $this->assertSame(0, $this->connectionLookups);
    }

    public function testQtySoldViewLooksUpTheSalesConnectionWithoutFailing(): void
    {
        $this->assertNull($this->salesPlugin()->beforeCreate($this->subscription('panth_product_grid_qty_sold')));
        $this->assertSame(1, $this->connectionLookups);
    }

    private function countrySource(): Countryofmanufacture
    {
        $backend = $this->createStub(AbstractBackend::class);
        $backend->method('getTable')->willReturn('catalog_product_entity_varchar');
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getId')->willReturn('83');
        $attribute->method('getBackend')->willReturn($backend);
        $source = $this->createStub(Countryofmanufacture::class);
        $source->method('getAttribute')->willReturn($attribute);
        return $source;
    }

    public function testNonProductCollectionsUseTheOriginalSorter(): void
    {
        $collection = $this->createStub(DataCollection::class);
        $calls = [];
        $proceed = static function ($c, $dir) use (&$calls) {
            $calls[] = $dir;
            return 'original';
        };

        $result = (new CountryofmanufactureSortingPlugin())->aroundAddValueSortToCollection(
            $this->countrySource(),
            \Closure::fromCallable($proceed),
            $collection,
            'desc'
        );

        $this->assertSame('original', $result);
        $this->assertSame(['desc'], $calls);
    }

    public function testProductCollectionIsSortedByStoreValueWithDefaultFallback(): void
    {
        $select = $this->select();
        $collection = $this->createStub(Collection::class);
        $collection->method('getStoreId')->willReturn(3);
        $collection->method('getSelect')->willReturn($select);

        $result = (new CountryofmanufactureSortingPlugin())->aroundAddValueSortToCollection(
            $this->countrySource(),
            static fn () => 'never',
            $collection,
            'desc'
        );

        $this->assertSame($collection, $result);
        $this->assertCount(2, $this->joins);
        $this->assertSame(['panth_com_default' => 'catalog_product_entity_varchar'], $this->joins[0][1]);
        $this->assertStringContainsString('attribute_id = 83 AND panth_com_default.store_id = 0', $this->joins[0][2]);
        $this->assertStringContainsString('panth_com_store.store_id = 3', $this->joins[1][2]);
        $this->assertSame(['COALESCE(panth_com_store.value, panth_com_default.value) DESC'], $this->orders);
    }

    public function testUnknownDirectionFallsBackToAscending(): void
    {
        $select = $this->select();
        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);

        (new CountryofmanufactureSortingPlugin())->aroundAddValueSortToCollection(
            $this->countrySource(),
            static fn () => 'never',
            $collection,
            'sideways; DROP'
        );

        $this->assertSame(['COALESCE(panth_com_store.value, panth_com_default.value) ASC'], $this->orders);
    }
}

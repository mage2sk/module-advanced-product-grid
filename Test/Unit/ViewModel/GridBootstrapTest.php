<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\ViewModel;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory;
use Magento\Framework\DataObject;
use Panth\AdvancedProductGrid\ViewModel\GridBootstrap;
use PHPUnit\Framework\TestCase;

class GridBootstrapTest extends TestCase
{
    private array $calls = [];

    private function viewModel(array $codes, bool $throws = false): GridBootstrap
    {
        $media = $this->createStub(MediaConfig::class);
        $media->method('getMediaUrl')->willReturnCallback(static fn ($file) => 'https://shop.test/media/catalog/product/' . $file);

        $type = $this->createStub(Type::class);
        $type->method('getId')->willReturn('4');
        $eav = $this->createStub(EavConfig::class);
        if ($throws) {
            $eav->method('getEntityType')->willThrowException(new \RuntimeException('eav'));
        } else {
            $eav->method('getEntityType')->willReturn($type);
        }

        $collection = $this->createStub(Collection::class);
        $collection->method('setEntityTypeFilter')->willReturnCallback(function ($id) use ($collection) {
            $this->calls[] = ['type', $id];
            return $collection;
        });
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->calls[] = [$field, $cond];
            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator(
            array_map(static fn ($c) => new DataObject(['attribute_code' => $c]), $codes)
        ));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new GridBootstrap($media, $eav, $factory);
    }

    public function testMediaUrlIsTheCatalogMediaBase(): void
    {
        $this->assertSame('https://shop.test/media/catalog/product/', $this->viewModel([])->getMediaUrl());
    }

    public function testPopupCodesIncludeTextareaAndMultiselectAttributesWithoutDuplicates(): void
    {
        $codes = $this->viewModel(['description', 'material', 'activity'])->getPopupCodes();

        $this->assertContains('tier_price', $codes);
        $this->assertContains('material', $codes);
        $this->assertContains('activity', $codes);
        $this->assertSame(count($codes), count(array_unique($codes)));
        $this->assertSame(range(0, count($codes) - 1), array_keys($codes));
        $this->assertSame([['type', 4], ['frontend_input', ['in' => ['multiselect', 'textarea']]]], $this->calls);
    }

    public function testBaseCodesAreReturnedWhenEavFails(): void
    {
        $codes = $this->viewModel(['material'], true)->getPopupCodes();

        $this->assertNotContains('material', $codes);
        $this->assertContains('panth_thumbnail', $codes);
        $this->assertCount(14, $codes);
    }
}

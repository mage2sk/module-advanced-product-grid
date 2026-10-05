<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\Component;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\PriceColumn;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Column\Thumbnail;
use PHPUnit\Framework\TestCase;

class ListingColumnsTest extends TestCase
{
    use DbDoublesTrait;

    private ?ObjectManagerInterface $previousObjectManager = null;

    protected function setUp(): void
    {
        try {
            $this->previousObjectManager = ObjectManager::getInstance();
        } catch (\Throwable) {
            $this->previousObjectManager = null;
        }
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    private function thumbnail(): Thumbnail
    {
        $factory = $this->createStub(ProductFactory::class);
        $factory->method('create')->willReturnCallback(fn () => $this->product());

        $helper = $this->createStub(ImageHelper::class);
        $current = null;
        $helper->method('init')->willReturnCallback(function (Product $product, $imageId) use ($helper, &$current) {
            $current = [$product->getData('sku'), $imageId];
            return $helper;
        });
        $helper->method('getUrl')->willReturnCallback(static function () use (&$current) {
            return 'https://media.test/' . $current[1] . '/' . $current[0] . '.jpg';
        });

        return new Thumbnail(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $helper,
            $factory,
            [],
            ['name' => 'thumbnail']
        );
    }

    public function testThumbnailAddsListingAndOriginalUrls(): void
    {
        $result = $this->thumbnail()->prepareDataSource(['data' => ['items' => [
            ['sku' => 'tee', 'name' => 'Tee'],
            ['sku' => 'cap'],
        ]]]);

        $first = $result['data']['items'][0];
        $this->assertSame('https://media.test/product_listing_thumbnail/tee.jpg', $first['thumbnail_src']);
        $this->assertSame('https://media.test/product_base_image/tee.jpg', $first['thumbnail_orig_src']);
        $this->assertSame('Tee', $first['thumbnail_alt']);
        $this->assertSame('#', $first['thumbnail_link']);
        $this->assertSame($first['thumbnail_src'], $first[Thumbnail::FIELD_URL]);
        $this->assertSame($first['thumbnail_orig_src'], $first[Thumbnail::FIELD_ORIG]);
        $this->assertSame('', $result['data']['items'][1]['thumbnail_alt']);
    }

    public function testThumbnailLeavesSourcesWithoutItemsUntouched(): void
    {
        $column = $this->thumbnail();

        $this->assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
        $this->assertSame(['data' => ['items' => 'x']], $column->prepareDataSource(['data' => ['items' => 'x']]));
    }

    public function testPriceColumnKeepsRawValueBeforeFormatting(): void
    {
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn ($amount) => '$' . number_format((float)$amount, 2)
        );
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($priceCurrency);
        ObjectManager::setInstance($objectManager);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createStub(StoreInterface::class));
        $context = $this->createStub(ContextInterface::class);
        $context->method('getFilterParam')->willReturn(0);

        $column = new PriceColumn(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(CurrencyInterface::class),
            $storeManager,
            [],
            ['name' => 'price', 'config' => ['editor' => ['editorType' => 'text']]]
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['price' => '12.5000'],
            ['sku' => 'no-price'],
        ]]]);

        $this->assertSame('12.5000', $result['data']['items'][0]['price_raw']);
        $this->assertSame('$12.50', $result['data']['items'][0]['price']);
        $this->assertSame(['sku' => 'no-price'], $result['data']['items'][1]);

        $readOnly = new PriceColumn(
            $context,
            $this->createStub(UiComponentFactory::class),
            $this->createStub(CurrencyInterface::class),
            $storeManager,
            [],
            ['name' => 'price', 'config' => []]
        );
        $plain = $readOnly->prepareDataSource(['data' => ['items' => [['price' => '3.0000']]]]);
        $this->assertSame(['price' => '$3.00'], $plain['data']['items'][0]);
    }

    public function testDisabledThumbnailColumnSkipsImageLookups(): void
    {
        $helper = $this->createMock(ImageHelper::class);
        $helper->expects($this->never())->method('init');
        $column = new Thumbnail(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $helper,
            $this->createStub(ProductFactory::class),
            [],
            ['name' => 'panth_thumbnail', 'config' => ['componentDisabled' => true]]
        );
        $source = ['data' => ['items' => [['sku' => 'tee']]]];

        $this->assertSame($source, $column->prepareDataSource($source));
    }
}

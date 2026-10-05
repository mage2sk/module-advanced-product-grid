<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Controller\Adminhtml;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductTierPriceExtensionInterface;
use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Image\Adapter\AdapterInterface as ImageAdapter;
use Magento\Framework\Image\AdapterFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Block\Adminhtml\Product\Grid\TierPrice\Content;
use Panth\AdvancedProductGrid\Controller\Adminhtml\CellEdit\Form;
use Panth\AdvancedProductGrid\Controller\Adminhtml\CellEdit\Upload;
use Panth\AdvancedProductGrid\Controller\Adminhtml\Thumbnail\GetModal as ThumbnailModal;
use Panth\AdvancedProductGrid\Controller\Adminhtml\TierPrice\GetModal as TierPriceModal;

class CellEditAndModalsTest extends ControllerTestCase
{
    private array $products = [];
    private array $loads = [];
    private array $attributes = [];
    private bool $groupsThrow = false;

    private function productRepository(bool $noSuchEntity = true): ProductRepositoryInterface
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function ($id, ...$rest) use ($noSuchEntity) {
            $this->loads[] = array_merge([$id], $rest);
            if (!isset($this->products[$id])) {
                throw $noSuchEntity ? new NoSuchEntityException(__('missing')) : new \RuntimeException('boom');
            }
            return $this->products[$id];
        });
        return $repository;
    }

    private function tier(int $group, float $qty, float $value, ?int $website, ?float $percent): ProductTierPriceInterface
    {
        $extension = $this->createStub(ProductTierPriceExtensionInterface::class);
        $extension->method('getWebsiteId')->willReturn($website);
        $extension->method('getPercentageValue')->willReturn($percent);
        $tier = $this->createStub(ProductTierPriceInterface::class);
        $tier->method('getCustomerGroupId')->willReturn($group);
        $tier->method('getQty')->willReturn($qty);
        $tier->method('getValue')->willReturn($value);
        $tier->method('getExtensionAttributes')->willReturn($extension);
        return $tier;
    }

    private function form(array $params): Form
    {
        $attributeRepository = $this->createStub(AttributeRepositoryInterface::class);
        $attributeRepository->method('get')->willReturnCallback(function ($entity, $code) {
            if (!isset($this->attributes[$code])) {
                throw new NoSuchEntityException(__('no attribute'));
            }
            return $this->attributes[$code];
        });

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $website->method('getName')->willReturn('Main');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);

        $group = $this->createStub(GroupInterface::class);
        $group->method('getId')->willReturn(2);
        $group->method('getCode')->willReturn('Wholesale');
        $groupResults = $this->createStub(GroupSearchResultsInterface::class);
        $groupResults->method('getItems')->willReturn([$group]);
        $groups = $this->createStub(GroupRepositoryInterface::class);
        $groups->method('getList')->willReturnCallback(function () use ($groupResults) {
            if ($this->groupsThrow) {
                throw new \RuntimeException('groups');
            }
            return $groupResults;
        });
        $criteria = $this->createStub(SearchCriteriaBuilder::class);
        $criteria->method('create')->willReturn(new SearchCriteria());

        $categoryCollection = $this->createStub(CategoryCollection::class);
        foreach (['addAttributeToSelect', 'addFieldToFilter', 'setOrder'] as $method) {
            $categoryCollection->method($method)->willReturnSelf();
        }
        $categoryCollection->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator([
            new DataObject(['id' => 2, 'name' => 'Default', 'path' => '1/2']),
            new DataObject(['id' => 5, 'name' => 'Bags', 'path' => '1/2/5']),
            new DataObject(['id' => 7, 'name' => 'Lost', 'path' => '1/99']),
        ]));
        $categories = $this->createStub(CategoryCollectionFactory::class);
        $categories->method('create')->willReturn($categoryCollection);

        return new Form(
            $this->context($params),
            $this->jsonFactory(),
            $this->productRepository(false),
            $attributeRepository,
            $storeManager,
            $groups,
            $criteria,
            $categories
        );
    }

    private function catalogProduct(array $data = [], array $categoryIds = [], array $tiers = []): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        $product->method('getTierPrices')->willReturn($tiers);
        return $product;
    }

    public function testFormRequiresProductAndAttribute(): void
    {
        $this->form(['product_id' => '0', 'attribute_code' => 'name'])->execute();
        $this->assertSame('Missing product_id or attribute_code.', $this->json['error']);

        $this->form(['product_id' => '3', 'attribute_code' => '  '])->execute();
        $this->assertFalse($this->json['success']);

        $this->form(['product_id' => '3', 'attribute_code' => 'name'])->execute();
        $this->assertSame(['success' => false, 'error' => 'Product not found.'], $this->json);
    }

    public function testFormLoadsProductInRequestedStoreClampedAtZero(): void
    {
        $this->products[3] = $this->catalogProduct(['name' => 'Tee']);

        $this->form(['product_id' => '3', 'attribute_code' => 'name', 'store_id' => '-4'])->execute();

        $this->assertSame([3, false, 0], array_slice($this->loads[0], 0, 3));
        $this->assertSame('Tee', $this->json['value']);
        $this->assertSame('name', $this->json['label'], 'unknown attribute keeps the code as label');
        $this->assertSame('text', $this->json['editor_type']);
    }

    public function testFormDescribesSelectAttributesWithOptions(): void
    {
        $red = $this->createStub(AttributeOptionInterface::class);
        $red->method('getValue')->willReturn('49');
        $red->method('getLabel')->willReturn('Red');
        $blank = $this->createStub(AttributeOptionInterface::class);
        $blank->method('getValue')->willReturn('');
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getDefaultFrontendLabel')->willReturn('Color');
        $attribute->method('getFrontendInput')->willReturn('boolean');
        $attribute->method('getOptions')->willReturn([$blank, $red]);
        $this->attributes['color'] = $attribute;
        $this->products[3] = $this->catalogProduct(['color' => 49]);

        $this->form(['product_id' => 3, 'attribute_code' => 'color'])->execute();

        $this->assertSame([
            'success' => true,
            'product_id' => 3,
            'attribute_code' => 'color',
            'label' => 'Color',
            'editor_type' => 'select',
            'value' => '49',
            'options' => [['value' => '49', 'label' => 'Red']],
        ], $this->json);
    }

    public function testFormMapsTextareaAndImageInputs(): void
    {
        foreach (['textarea' => 'textarea', 'media_image' => 'image', 'multiselect' => 'multiselect', 'date' => 'text'] as $input => $editor) {
            $attribute = $this->createStub(AttributeInterface::class);
            $attribute->method('getDefaultFrontendLabel')->willReturn('');
            $attribute->method('getFrontendInput')->willReturn($input);
            $attribute->method('getOptions')->willReturn([]);
            $this->attributes['attr'] = $attribute;
            $this->products[3] = $this->catalogProduct(['attr' => ['a', 'b']]);

            $this->form(['product_id' => 3, 'attribute_code' => 'attr'])->execute();

            $this->assertSame($editor, $this->json['editor_type'], $input);
            $this->assertSame('attr', $this->json['label']);
            $this->assertSame(['a', 'b'], $this->json['value']);
        }
    }

    public function testFormReturnsCategoriesWithBreadcrumbs(): void
    {
        $this->products[3] = $this->catalogProduct([], [5]);

        $this->form(['product_id' => 3, 'attribute_code' => 'panth_categories'])->execute();

        $this->assertSame('multiselect', $this->json['editor_type']);
        $this->assertSame('Categories', $this->json['label']);
        $this->assertSame([5], $this->json['value']);
        $this->assertSame([
            ['value' => 2, 'label' => 'Default'],
            ['value' => 5, 'label' => 'Default > Bags'],
            ['value' => 7, 'label' => 'Lost'],
        ], $this->json['options']);
    }

    public function testFormReturnsTierPricesWithWebsiteAndGroupOptions(): void
    {
        $this->products[3] = $this->catalogProduct([], [], [
            $this->tier(1, 2, 8.0, null, null),
            $this->tier(2, 10, 0.0, 1, 5.0),
        ]);
        $this->groupsThrow = true;

        $this->form(['product_id' => 3, 'attribute_code' => 'tier_price'])->execute();

        $this->assertSame('tier_price', $this->json['editor_type']);
        $this->assertSame([
            ['website_id' => 0, 'cust_group' => 1, 'qty' => 2.0, 'value' => 8.0, 'value_type' => 'fixed'],
            ['website_id' => 1, 'cust_group' => 2, 'qty' => 10.0, 'value' => 0.0, 'value_type' => 'percent'],
        ], $this->json['value']);
        $this->assertSame([['value' => 0, 'label' => 'All Websites'], ['value' => 1, 'label' => 'Main']], $this->json['options']['websites']);
        $this->assertSame([['value' => 32000, 'label' => 'All Groups']], $this->json['options']['customer_groups']);
    }

    private array $renamed = [];
    private array $uploaderCalls = [];

    private function upload(?array $info, bool $tmpExists = true, ?\Throwable $error = null): Upload
    {
        $uploader = $this->createStub(Uploader::class);
        foreach (['setAllowedExtensions', 'setAllowRenameFiles', 'setFilesDispersion'] as $method) {
            $uploader->method($method)->willReturnCallback(function ($arg) use ($uploader, $method) {
                $this->uploaderCalls[$method] = $arg;
                return $uploader;
            });
        }
        $uploader->method('addValidateCallback')->willReturnCallback(function ($name) use ($uploader) {
            $this->uploaderCalls['validate'] = $name;
            return $uploader;
        });
        $uploader->method('save')->willReturnCallback(function ($dir) use ($info, $error) {
            $this->uploaderCalls['save'] = $dir;
            if ($error) {
                throw $error;
            }
            return $info;
        });
        $factory = $this->createStub(UploaderFactory::class);
        $factory->method('create')->willReturn($uploader);

        $media = $this->createStub(MediaConfig::class);
        $media->method('getBaseTmpMediaPath')->willReturn('tmp/catalog/product');
        $media->method('getTmpMediaPath')->willReturnCallback(static fn ($f) => 'tmp/catalog/product/' . ltrim($f, '/'));
        $media->method('getMediaPath')->willReturnCallback(static fn ($f) => 'catalog/product/' . ltrim($f, '/'));
        $media->method('getMediaUrl')->willReturnCallback(static fn ($f) => 'https://m.test/catalog/product' . $f);

        $read = $this->createStub(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturnCallback(static fn ($p) => '/srv/pub/media/' . $p);
        $write = $this->createStub(WriteInterface::class);
        $write->method('isFile')->willReturn($tmpExists);
        $write->method('renameFile')->willReturnCallback(function ($from, $to) {
            $this->renamed[] = [$from, $to];
            return true;
        });
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($read);
        $filesystem->method('getDirectoryWrite')->willReturn($write);

        $adapters = $this->createStub(AdapterFactory::class);
        $adapters->method('create')->willReturn($this->createStub(ImageAdapter::class));

        return new Upload($this->context(), $this->jsonFactory(), $factory, $media, $filesystem, $adapters);
    }

    public function testUploadMovesFileFromTmpAndReturnsUrl(): void
    {
        $this->upload(['file' => '/a/b/photo.jpg'])->execute();

        $this->assertSame(['success' => true, 'file' => '/a/b/photo.jpg', 'url' => 'https://m.test/catalog/product/a/b/photo.jpg'], $this->json);
        $this->assertSame([['tmp/catalog/product/a/b/photo.jpg', 'catalog/product/a/b/photo.jpg']], $this->renamed);
        $this->assertSame(['jpg', 'jpeg', 'gif', 'png', 'webp'], $this->uploaderCalls['setAllowedExtensions']);
        $this->assertTrue($this->uploaderCalls['setAllowRenameFiles']);
        $this->assertTrue($this->uploaderCalls['setFilesDispersion']);
        $this->assertSame('catalog_product_image', $this->uploaderCalls['validate']);
        $this->assertSame('/srv/pub/media/tmp/catalog/product', $this->uploaderCalls['save']);
    }

    public function testUploadSkipsMoveWhenTmpFileMissing(): void
    {
        $this->upload(['file' => '/x.png'], false)->execute();

        $this->assertTrue($this->json['success']);
        $this->assertSame([], $this->renamed);
    }

    public function testUploadReportsMissingFileAndUploaderErrors(): void
    {
        $this->upload(['file' => ''])->execute();
        $this->assertSame(['success' => false, 'error' => 'No file was uploaded.'], $this->json);

        $this->upload(null, true, new \Exception('Disallowed file type.'))->execute();
        $this->assertSame(['success' => false, 'error' => 'Disallowed file type.'], $this->json);
    }

    public function testUploadRequiresGalleryPermission(): void
    {
        $controller = $this->upload(['file' => '/x.png']);
        $this->denied = ['Panth_AdvancedProductGrid::manage_gallery'];

        $this->assertFalse($this->callProtected($controller, '_isAllowed'));
        $this->assertSame('Panth_AdvancedProductGrid::manage_gallery', Upload::ADMIN_RESOURCE);
    }

    private array $registered = [];

    private function registry(array $existing = []): Registry
    {
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(fn ($key) => $existing[$key] ?? ($this->registered[$key] ?? null));
        $registry->method('register')->willReturnCallback(function ($key, $value) {
            $this->registered[$key] = $value;
        });
        return $registry;
    }

    private function galleryEntry(int $id, ?string $file, bool $disabled, ?array $types): ProductAttributeMediaGalleryEntryInterface
    {
        $entry = $this->createStub(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn($id);
        $entry->method('getFile')->willReturn($file);
        $entry->method('getLabel')->willReturn(null);
        $entry->method('getPosition')->willReturn('2');
        $entry->method('isDisabled')->willReturn($disabled);
        $entry->method('getTypes')->willReturn($types);
        return $entry;
    }

    public function testThumbnailModalListsGalleryAndRegistersProduct(): void
    {
        $media = $this->createStub(MediaConfig::class);
        $media->method('getMediaUrl')->willReturnCallback(static fn ($f) => 'https://m.test' . $f);
        $product = $this->createStub(Product::class);
        $product->method('getMediaGalleryEntries')->willReturn([
            $this->galleryEntry(1, '/a.jpg', false, ['image', 'thumbnail']),
            $this->galleryEntry(2, null, true, null),
        ]);
        $product->method('getMediaConfig')->willReturn($media);
        $this->products[4] = $product;

        $controller = new ThumbnailModal(
            $this->context(['product_id' => '4']),
            $this->jsonFactory(),
            $this->createStub(LayoutFactory::class),
            $this->productRepository(),
            $this->registry()
        );
        $controller->execute();

        $this->assertSame([4, true], array_slice($this->loads[0], 0, 2));
        $this->assertSame($product, $this->registered['current_product']);
        $this->assertSame($product, $this->registered['product']);
        $this->assertSame([
            ['id' => 1, 'file' => '/a.jpg', 'label' => '', 'position' => 2, 'disabled' => 0, 'types' => ['image', 'thumbnail'], 'url' => 'https://m.test/a.jpg'],
            ['id' => 2, 'file' => null, 'label' => '', 'position' => 2, 'disabled' => 1, 'types' => [], 'url' => null],
        ], $this->json['images']);
    }

    public function testThumbnailModalKeepsAlreadyRegisteredProduct(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getMediaGalleryEntries')->willReturn(null);
        $this->products[4] = $product;
        $other = new DataObject();

        (new ThumbnailModal(
            $this->context(['product_id' => 4]),
            $this->jsonFactory(),
            $this->createStub(LayoutFactory::class),
            $this->productRepository(),
            $this->registry(['current_product' => $other, 'product' => $other])
        ))->execute();

        $this->assertSame([], $this->registered);
        $this->assertSame([], $this->json['images']);
    }

    public function testModalsValidateProductId(): void
    {
        foreach ([['product_id' => 0], ['product_id' => 99]] as $i => $params) {
            (new ThumbnailModal($this->context($params), $this->jsonFactory(), $this->createStub(LayoutFactory::class), $this->productRepository(), $this->registry()))->execute();
            $this->assertSame($i === 0 ? 'Missing product_id.' : 'Product not found.', $this->json['error']);

            (new TierPriceModal($this->context($params), $this->jsonFactory(), $this->createStub(LayoutFactory::class), $this->productRepository()))->execute();
            $this->assertSame($i === 0 ? 'Missing product_id.' : 'Product not found.', $this->json['error']);
        }
    }

    public function testTierPriceModalRendersBlockForProduct(): void
    {
        $product = $this->createStub(Product::class);
        $this->products[6] = $product;
        $blockCalls = [];
        $block = $this->createStub(Content::class);
        $block->method('setProduct')->willReturnCallback(function ($p) use ($block, &$blockCalls) {
            $blockCalls['product'] = $p;
            return $block;
        });
        $block->method('setTemplate')->willReturnCallback(function ($t) use ($block, &$blockCalls) {
            $blockCalls['template'] = $t;
            return $block;
        });
        $block->method('toHtml')->willReturn('<table></table>');
        $block->method('getTierPriceRows')->willReturn([['qty' => 2.0]]);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('createBlock')->willReturnCallback(function ($class, $name) use ($block, &$blockCalls) {
            $blockCalls['create'] = [$class, $name];
            return $block;
        });
        $layouts = $this->createStub(LayoutFactory::class);
        $layouts->method('create')->willReturn($layout);

        (new TierPriceModal($this->context(['product_id' => '6']), $this->jsonFactory(), $layouts, $this->productRepository()))->execute();

        $this->assertSame([6, false], array_slice($this->loads[0], 0, 2));
        $this->assertSame([Content::class, 'panth_pg_tier_price_modal'], $blockCalls['create']);
        $this->assertSame($product, $blockCalls['product']);
        $this->assertSame('Panth_AdvancedProductGrid::tier_prices.phtml', $blockCalls['template']);
        $this->assertSame(
            ['success' => true, 'product_id' => 6, 'html' => '<table></table>', 'tier_prices' => [['qty' => 2.0]]],
            $this->json
        );
    }
}

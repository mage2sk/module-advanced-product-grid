<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Block;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductTierPriceExtensionInterface;
use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedProductGrid\Block\Adminhtml\Product\Grid\TierPrice\Content;
use PHPUnit\Framework\TestCase;

/**
 * The block is built without its Template constructor (which needs the
 * framework object manager); its own collaborators are injected directly.
 */
class TierPriceContentTest extends TestCase
{
    private function block(): Content
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn('1');
        $website->method('getName')->willReturn('Main Website');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);

        $group = $this->createStub(GroupInterface::class);
        $group->method('getId')->willReturn(1);
        $group->method('getCode')->willReturn('General');
        $groups = $this->createStub(GroupSearchResultsInterface::class);
        $groups->method('getItems')->willReturn([$group]);
        $groupRepository = $this->createStub(GroupRepositoryInterface::class);
        $groupRepository->method('getList')->willReturn($groups);

        $criteria = $this->createStub(SearchCriteriaBuilder::class);
        $criteria->method('create')->willReturn(new SearchCriteria());

        $block = (new \ReflectionClass(Content::class))->newInstanceWithoutConstructor();
        (function () use ($storeManager, $groupRepository, $criteria) {
            $this->storeManager = $storeManager;
            $this->groupRepository = $groupRepository;
            $this->searchCriteriaBuilder = $criteria;
        })->call($block);
        return $block;
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

    public function testWebsiteAndGroupOptionsStartWithAllEntries(): void
    {
        $block = $this->block();

        $this->assertSame(
            [['value' => 0, 'label' => 'All Websites'], ['value' => 1, 'label' => 'Main Website']],
            $block->getWebsiteOptions()
        );
        $this->assertSame(
            [['value' => 32000, 'label' => 'All Groups'], ['value' => 1, 'label' => 'General']],
            $block->getCustomerGroupOptions()
        );
    }

    public function testRowsAreEmptyWithoutProduct(): void
    {
        $block = $this->block();

        $this->assertNull($block->getProduct());
        $this->assertSame([], $block->getTierPriceRows());
    }

    public function testTierRowsDistinguishFixedAndPercentPrices(): void
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getTierPrices')->willReturn([
            $this->tier(1, 5, 9.5, 2, null),
            $this->tier(32000, 10, 0, null, 15.0),
        ]);
        $block = $this->block();

        $this->assertSame($block, $block->setProduct($product));
        $this->assertSame($product, $block->getProduct());
        $this->assertSame([
            ['website_id' => 2, 'cust_group' => 1, 'qty' => 5.0, 'value' => 9.5, 'value_type' => 'fixed', 'percentage_value' => 0.0],
            ['website_id' => 0, 'cust_group' => 32000, 'qty' => 10.0, 'value' => 0.0, 'value_type' => 'percent', 'percentage_value' => 15.0],
        ], $block->getTierPriceRows());
    }
}

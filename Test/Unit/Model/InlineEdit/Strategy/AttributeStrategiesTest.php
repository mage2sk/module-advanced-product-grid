<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit\Strategy;

use Magento\Catalog\Model\Product\Visibility;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\GenericAttributeStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\TierPriceStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\UrlKeyStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\VisibilityStrategy;
use Panth\AdvancedProductGrid\Model\InlineEdit\Strategy\WeightStrategy;
use Panth\AdvancedProductGrid\Test\Unit\Fixture\DbDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeStrategiesTest extends TestCase
{
    use DbDoublesTrait;

    public static function clearingValues(): array
    {
        return [
            'use default marker' => ['__use_default__'],
            'empty marker' => ['__empty__'],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('clearingValues')]
    public function testGenericStrategyClearsValueForMarkers(mixed $value): void
    {
        $product = $this->product(['color' => '49']);

        (new GenericAttributeStrategy())->apply($product, 'color', $value);

        $this->assertNull($product->getData('color'));
        $this->assertTrue($product->hasData('color'));
    }

    public function testGenericStrategyStoresValueAsIs(): void
    {
        $product = $this->product();

        (new GenericAttributeStrategy())->apply($product, 'color', ['49', '50']);
        (new GenericAttributeStrategy())->apply($product, 'name', 'Blue Shirt');

        $this->assertSame(['49', '50'], $product->getData('color'));
        $this->assertSame('Blue Shirt', $product->getData('name'));
    }

    public function testUrlKeyIsSetAndRewriteRefreshRequested(): void
    {
        $product = $this->product();

        (new UrlKeyStrategy())->apply($product, 'url_key', 'blue-shirt');

        $this->assertSame('blue-shirt', $product->getData('url_key'));
        $this->assertTrue($product->getData('panth_request_url_rewrite_refresh'));
    }

    public function testNullUrlKeyBecomesEmptyString(): void
    {
        $product = $this->product(['url_key' => 'old']);

        (new UrlKeyStrategy())->apply($product, 'url_key', null);

        $this->assertSame('', $product->getData('url_key'));
    }

    public static function visibilityProvider(): array
    {
        return [
            'not visible' => ['1', Visibility::VISIBILITY_NOT_VISIBLE],
            'catalog' => [2, Visibility::VISIBILITY_IN_CATALOG],
            'search' => ['3', Visibility::VISIBILITY_IN_SEARCH],
            'both' => ['4', Visibility::VISIBILITY_BOTH],
            'out of range falls back to both' => ['9', Visibility::VISIBILITY_BOTH],
            'garbage falls back to both' => ['abc', Visibility::VISIBILITY_BOTH],
        ];
    }

    #[DataProvider('visibilityProvider')]
    public function testVisibilityIsValidatedAndRequestsRewriteRefresh(mixed $value, int $expected): void
    {
        $product = $this->product();

        (new VisibilityStrategy())->apply($product, 'visibility', $value);

        $this->assertSame($expected, (int)$product->getData('visibility'));
        $this->assertTrue($product->getData('panth_request_url_rewrite_refresh'));
    }

    public function testWeightIsCastToFloatAndFlagsProductAsHavingWeight(): void
    {
        $product = $this->product();

        (new WeightStrategy())->apply($product, 'weight', '1.25');

        $this->assertSame(1.25, $product->getData('weight'));
        $this->assertSame(1, $product->getData('product_has_weight'));
    }

    public function testEmptyWeightClearsValueAndMarksProductWeightless(): void
    {
        foreach (['', null] as $empty) {
            $product = $this->product(['weight' => 5]);

            (new WeightStrategy())->apply($product, 'weight', $empty);

            $this->assertNull($product->getData('weight'));
            $this->assertSame(0, $product->getData('product_has_weight'));
        }
    }

    public function testZeroWeightIsKeptAsRealWeight(): void
    {
        $product = $this->product();

        (new WeightStrategy())->apply($product, 'weight', '0');

        $this->assertSame(0.0, $product->getData('weight'));
        $this->assertSame(1, $product->getData('product_has_weight'));
    }

    public function testTierPricesAreNormalisedFromArrayRows(): void
    {
        $product = $this->product();

        (new TierPriceStrategy())->apply($product, 'tier_price', [
            ['website_id' => '1', 'cust_group' => '2', 'qty' => '5', 'price' => '9.5'],
            'not-a-row',
            ['customer_group_id' => 3, 'price_qty' => 10, 'value' => 8, 'value_type' => 'percent'],
        ]);

        $rows = $product->getData('tier_price');
        $this->assertCount(2, $rows);
        $this->assertSame([
            'website_id' => 1,
            'cust_group' => 2,
            'customer_group_id' => 2,
            'price_qty' => 5.0,
            'qty' => 5.0,
            'value_type' => 'fixed',
            'price' => 9.5,
            'value' => 9.5,
            'percentage_value' => null,
        ], $rows[0]);
        $this->assertSame(3, $rows[1]['cust_group']);
        $this->assertSame(10.0, $rows[1]['qty']);
        $this->assertSame('percent', $rows[1]['value_type']);
        $this->assertSame(8.0, $rows[1]['price']);
    }

    public function testPercentRowPrefersExplicitPercentageValue(): void
    {
        $product = $this->product();

        (new TierPriceStrategy())->apply($product, 'tier_price', [
            ['value_type' => 'percent', 'percentage_value' => '15', 'price' => '99'],
        ]);

        $row = $product->getData('tier_price')[0];
        $this->assertSame(15.0, $row['percentage_value']);
        $this->assertSame(0, $row['website_id']);
        $this->assertSame(1.0, $row['qty']);
    }

    public function testTierPricesAcceptJsonString(): void
    {
        $product = $this->product();

        (new TierPriceStrategy())->apply($product, 'tier_price', '[{"qty":2,"price":4}]');

        $rows = $product->getData('tier_price');
        $this->assertCount(1, $rows);
        $this->assertSame(2.0, $rows[0]['qty']);
        $this->assertSame(4.0, $rows[0]['price']);
    }

    public function testInvalidTierPayloadClearsAllTiers(): void
    {
        foreach (['not json', 42, null] as $payload) {
            $product = $this->product(['tier_price' => [['qty' => 1]]]);

            (new TierPriceStrategy())->apply($product, 'tier_price', $payload);

            $this->assertSame([], $product->getData('tier_price'));
        }
    }
}

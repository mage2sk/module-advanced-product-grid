<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\FormatInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\ChangeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChangeValidatorNormalizeTest extends TestCase
{
    private array $parsed = [];

    private function repository(): AttributeRepositoryInterface
    {
        $attributes = [
            'price' => $this->attribute('price', 'decimal'),
            'special_price' => $this->attribute('price', 'decimal'),
            'name' => $this->attribute('text', 'varchar'),
        ];
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static function ($entity, $code) use ($attributes) {
            if (!isset($attributes[$code])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $attributes[$code];
        });
        return $repository;
    }

    private function attribute(string $input, string $backend): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backend);
        $attribute->method('getIsRequired')->willReturn(false);
        $attribute->method('getData')->willReturn(null);
        return $attribute;
    }

    private function localeFormat(): FormatInterface
    {
        $format = $this->createStub(FormatInterface::class);
        $format->method('getNumber')->willReturnCallback(function ($value) {
            $this->parsed[] = $value;
            $clean = (string)preg_replace('/[^\d,.\-]/', '', (string)$value);
            if (preg_match('/,\d{2}$/', $clean)) {
                $clean = str_replace(['.', ','], ['', '.'], $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
            return (float)$clean;
        });
        return $format;
    }

    public static function formattedPrices(): array
    {
        return [
            'dollar symbol' => ['$24.00', '24'],
            'thousands separator' => ['$1,234.50', '1234.5'],
            'euro after value with comma decimals' => ["24,00 \u{20AC}", '24'],
            'non breaking space' => ["1\u{00A0}299,90 \u{20AC}", '1299.9'],
        ];
    }

    #[DataProvider('formattedPrices')]
    public function testFormattedPricesAreParsedWithTheLocaleFormat(string $input, string $expected): void
    {
        $validator = new ChangeValidator($this->repository(), $this->localeFormat());

        $changes = $validator->normalize(['price' => $input, 'name' => 'Tee $5']);

        $this->assertSame($expected, $changes['price']);
        $this->assertSame('Tee $5', $changes['name']);
        $this->assertNull($validator->validate($changes));
    }

    public function testPlainNumbersEmptyValuesAndWordsAreLeftUntouched(): void
    {
        $validator = new ChangeValidator($this->repository(), $this->localeFormat());

        $changes = $validator->normalize([
            'price' => '19.99',
            'special_price' => '',
            'qty' => 'abc',
            'weight' => 'USD 4',
            'panth_availability' => '1',
        ]);

        $this->assertSame('19.99', $changes['price']);
        $this->assertSame('', $changes['special_price']);
        $this->assertSame('abc', $changes['qty']);
        $this->assertSame('USD 4', $changes['weight']);
        $this->assertSame([], $this->parsed);
        $this->assertSame('Invalid value for attribute "qty".', $validator->validate(['qty' => $changes['qty']]));
    }

    public function testQtyAndWeightUseTheVirtualNumericRules(): void
    {
        $validator = new ChangeValidator($this->repository(), $this->localeFormat());

        $changes = $validator->normalize(['qty' => '1,250', 'weight' => '2.5 ', 'unknown_code' => '$3']);

        $this->assertSame('1250', $changes['qty']);
        $this->assertSame('2.5 ', $changes['weight']);
        $this->assertSame('$3', $changes['unknown_code']);
    }

    public function testFallbackParserIsUsedWithoutALocaleFormat(): void
    {
        $validator = new ChangeValidator($this->repository());

        $changes = $validator->normalize(['price' => '$1,024.75', 'special_price' => '--']);

        $this->assertSame('1024.75', $changes['price']);
        $this->assertSame('--', $changes['special_price']);
    }

    public function testNonStringValuesAndCodesAreSkipped(): void
    {
        $validator = new ChangeValidator($this->repository(), $this->localeFormat());

        $changes = $validator->normalize([0 => '$5', 'price' => 12.5, 'tier_price' => '$9']);

        $this->assertSame([0 => '$5', 'price' => 12.5, 'tier_price' => '$9'], $changes);
        $this->assertSame([], $this->parsed);
    }
}

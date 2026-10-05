<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\AdvancedProductGrid\Model\InlineEdit\ChangeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChangeValidatorEdgeCasesTest extends TestCase
{
    private array $lookups = [];
    private ChangeValidator $validator;

    protected function setUp(): void
    {
        $attributes = [
            'material' => $this->sourceAttribute('multiselect', 'varchar', false, ['10', '11', '12']),
            'is_new' => $this->sourceAttribute('boolean', 'int', false, ['0', '1']),
            'ratio' => $this->sourceAttribute('text', 'decimal', false),
            'rank' => $this->sourceAttribute('text', 'int', false),
            'notes' => $this->sourceAttribute('textarea', 'text', true),
            'code' => $this->sourceAttribute('text', 'static', false),
            'brand' => $this->optionAttribute(['', '7', '8']),
            'broken_source' => $this->brokenSourceAttribute(),
        ];
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($entity, $code) use ($attributes) {
            $this->lookups[] = $code;
            if (!isset($attributes[$code])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $attributes[$code];
        });
        $this->validator = new ChangeValidator($repository);
    }

    private function sourceAttribute(string $input, string $backend, bool $required, array $options = []): Attribute
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(array_merge(
            [['value' => ['nested'], 'label' => 'Group']],
            array_map(static fn ($v) => ['value' => $v, 'label' => 'L' . $v], $options)
        ));
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backend);
        $attribute->method('getIsRequired')->willReturn($required);
        $attribute->method('usesSource')->willReturn($options !== []);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getData')->willReturn(null);
        return $attribute;
    }

    private function optionAttribute(array $values): AttributeInterface
    {
        $options = [];
        foreach ($values as $value) {
            $option = $this->createStub(AttributeOptionInterface::class);
            $option->method('getValue')->willReturn($value);
            $options[] = $option;
        }
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getIsRequired')->willReturn(false);
        $attribute->method('getOptions')->willReturn($options);
        return $attribute;
    }

    private function brokenSourceAttribute(): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willThrowException(new \RuntimeException('source class missing'));
        return $attribute;
    }

    public static function acceptedChanges(): array
    {
        return [
            'multiselect array' => ['material', ['10', '12']],
            'multiselect csv with spaces' => ['material', '10, 11'],
            'multiselect trailing comma' => ['material', '10,'],
            'boolean' => ['is_new', '1'],
            'decimal text' => ['ratio', '-0.25'],
            'int text' => ['rank', '-12'],
            'static text up to limit' => ['code', str_repeat('x', 255)],
            'option list select' => ['brand', '8'],
            'weight cleared' => ['weight', ''],
            'weight use default' => ['weight', '__use_default__'],
            'qty with spaces' => ['qty', ' 3 '],
            'image role path' => ['thumbnail', '/a/b.jpg'],
            'image role cleared' => ['image', ''],
            'tier array' => ['tier_price', [['qty' => 1]]],
            'tier empty string' => ['tier_price', ''],
            'category csv' => ['category_ids', ' 3,4 '],
            'category marker' => ['category_ids', '__empty__'],
            'category null' => ['category_ids', null],
            'optional empty' => ['ratio', '__empty__'],
        ];
    }

    #[DataProvider('acceptedChanges')]
    public function testAcceptedChanges(string $code, mixed $value): void
    {
        $this->assertNull($this->validator->validateChange($code, $value));
    }

    public static function rejectedChanges(): array
    {
        return [
            'unknown multiselect option' => ['material', '10,99'],
            'multiselect object' => ['material', 42],
            'multiselect nested array' => ['material', [['10']]],
            'boolean out of range' => ['is_new', '2'],
            'decimal garbage' => ['ratio', '1,5'],
            'int with fraction' => ['rank', '1.5'],
            'int array' => ['rank', ['1']],
            'static text too long' => ['code', str_repeat('x', 256)],
            'unknown option list value' => ['brand', '9'],
            'required textarea emptied' => ['notes', '__use_default__'],
            'negative weight' => ['weight', '-1'],
            'qty array' => ['qty', [1]],
            'image role too long' => ['small_image', str_repeat('a', 256)],
            'image role non string' => ['swatch_image', 5],
            'tier number' => ['tier_price', 5],
            'category negative' => ['category_ids', '-3'],
            'category object' => ['category_ids', 12],
            'backorders array' => ['panth_backorders', ['1']],
            'source failure' => ['broken_source', '1'],
            'panth thumbnail must be sent as thumbnail' => ['panth_thumbnail', '/a.jpg'],
        ];
    }

    #[DataProvider('rejectedChanges')]
    public function testRejectedChanges(string $code, mixed $value): void
    {
        $this->assertNotNull($this->validator->validateChange($code, $value));
    }

    public function testErrorMessagesNameTheAttribute(): void
    {
        $this->assertSame(
            'Attribute "row_id" cannot be edited from the product grid.',
            $this->validator->validateChange('row_id', '1')
        );
        $this->assertSame('Invalid value for attribute "rank".', $this->validator->validateChange('rank', 'x'));
        $this->assertSame('Invalid attribute code.', $this->validator->validate(['' => 'x']));
    }

    public function testValidateStopsAtFirstError(): void
    {
        $error = $this->validator->validate(['rank' => 'bad', 'ratio' => 'worse']);

        $this->assertSame('Invalid value for attribute "rank".', $error);
        $this->assertSame(['rank'], $this->lookups);
    }

    public function testAttributeLookupsAreCachedIncludingMisses(): void
    {
        $this->assertTrue($this->validator->isAllowedCode('rank'));
        $this->assertTrue($this->validator->isAllowedCode('rank'));
        $this->assertFalse($this->validator->isAllowedCode('ghost'));
        $this->assertFalse($this->validator->isAllowedCode('ghost'));
        $this->assertTrue($this->validator->isAllowedCode('qty'));
        $this->assertFalse($this->validator->isAllowedCode('media_gallery'));

        $this->assertSame(['rank', 'ghost'], $this->lookups);
    }
}

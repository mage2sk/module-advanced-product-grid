<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\AdvancedProductGrid\Model\InlineEdit\ChangeValidator;
use PHPUnit\Framework\TestCase;

class ChangeValidatorTest extends TestCase
{
    private ChangeValidator $validator;

    protected function setUp(): void
    {
        $attributes = [
            'name' => $this->attribute('text', 'varchar', true),
            'price' => $this->attribute('price', 'decimal', true),
            'status' => $this->attribute('select', 'int', true, ['1', '2']),
            'color' => $this->attribute('select', 'int', false, ['49', '50']),
            'news_from_date' => $this->attribute('date', 'datetime', false),
            'hidden_attr' => $this->attribute('text', 'varchar', false, [], 0),
            'custom_layout_update_file' => $this->attribute('select', 'varchar', false, ['a']),
            'media_attr' => $this->attribute('media_image', 'varchar', false),
        ];
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            static function ($entityType, $code) use ($attributes) {
                if (!isset($attributes[$code])) {
                    throw new NoSuchEntityException(__('missing'));
                }
                return $attributes[$code];
            }
        );
        $this->validator = new ChangeValidator($repository);
    }

    private function attribute(
        string $input,
        string $backendType,
        bool $required,
        array $options = [],
        int $visible = 1
    ): Attribute {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(
            array_map(static fn ($value) => ['value' => $value, 'label' => 'L' . $value], $options)
        );
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backendType);
        $attribute->method('getIsRequired')->willReturn($required);
        $attribute->method('usesSource')->willReturn($options !== []);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'is_visible' ? $visible : null
        );
        return $attribute;
    }

    public function testUnknownAndSystemKeysAreRejected(): void
    {
        $this->assertNotNull($this->validator->validate(['entity_id' => 5]));
        $this->assertNotNull($this->validator->validate(['type_id' => 'virtual']));
        $this->assertNotNull($this->validator->validate(['some_random_key' => 'x']));
        $this->assertNotNull($this->validator->validate(['hidden_attr' => 'x']));
        $this->assertNotNull($this->validator->validate(['custom_layout_update_file' => 'a']));
        $this->assertNotNull($this->validator->validate(['media_attr' => 'x.jpg']));
        $this->assertNotNull($this->validator->validate([0 => 'x']));
    }

    public function testEditableAttributesAreAccepted(): void
    {
        $this->assertNull($this->validator->validate([
            'name' => 'New name',
            'price' => '19.99',
            'status' => '2',
            'color' => '',
            'news_from_date' => '2026-10-01',
            'qty' => '5',
            'panth_availability' => '1',
            'panth_backorders' => '0',
            'category_ids' => ['3', '4'],
            'tier_price' => '[]',
        ]));
    }

    public function testValuesAreValidatedByType(): void
    {
        $this->assertNotNull($this->validator->validate(['price' => 'abc']));
        $this->assertNotNull($this->validator->validate(['price' => '-1']));
        $this->assertNotNull($this->validator->validate(['status' => '9']));
        $this->assertNotNull($this->validator->validate(['status' => '']));
        $this->assertNotNull($this->validator->validate(['name' => ['array']]));
        $this->assertNotNull($this->validator->validate(['name' => str_repeat('a', 256)]));
        $this->assertNotNull($this->validator->validate(['news_from_date' => 'not a date']));
        $this->assertNotNull($this->validator->validate(['qty' => 'many']));
        $this->assertNotNull($this->validator->validate(['panth_availability' => '7']));
        $this->assertNotNull($this->validator->validate(['category_ids' => ['1', 'x']]));
        $this->assertNotNull($this->validator->validate(['tier_price' => '{bad']));
    }
}

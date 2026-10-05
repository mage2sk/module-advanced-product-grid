<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\Component;

use Magento\Catalog\Api\Data\EavAttributeInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\ColumnInterface;
use Panth\AdvancedProductGrid\Ui\Component\Listing\ColumnFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColumnFactoryTest extends TestCase
{
    private array $created = [];

    private function factory(): ColumnFactory
    {
        $uiFactory = $this->createStub(UiComponentFactory::class);
        $uiFactory->method('create')->willReturnCallback(function ($name, $type, $args) {
            $this->created[] = [$name, $type, $args];
            return $this->createStub(ColumnInterface::class);
        });
        return new ColumnFactory($uiFactory);
    }

    private function attribute(
        string $code,
        string $input,
        int $required = 0,
        string $frontendClass = '',
        array $options = [],
        bool $eav = true
    ): AttributeInterface {
        $opts = [];
        foreach ($options as $value => $label) {
            $opt = $this->createStub(AttributeOptionInterface::class);
            $opt->method('getValue')->willReturn((string)$value);
            $opt->method('getLabel')->willReturn($label);
            $opts[] = $opt;
        }
        $attribute = $this->createStub($eav ? EavAttributeInterface::class : AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getDefaultFrontendLabel')->willReturn(ucfirst($code));
        $attribute->method('getIsRequired')->willReturn($required);
        $attribute->method('getOptions')->willReturn($opts);
        if ($eav) {
            $attribute->method('getFrontendClass')->willReturn($frontendClass);
        }
        return $attribute;
    }

    private function config(): array
    {
        return $this->created[0][2]['data']['config'];
    }

    public static function inputMatrix(): array
    {
        return [
            'text' => ['text', 'text', 'text', 'text', null],
            'textarea' => ['textarea', 'text', 'text', 'text', null],
            'price' => ['price', 'text', 'textRange', 'text', null],
            'weight' => ['weight', 'text', 'textRange', 'text', null],
            'date' => ['date', 'date', 'dateRange', 'date', 'Magento_Ui/js/grid/columns/date'],
            'datetime' => ['datetime', 'date', 'dateRange', 'date', 'Magento_Ui/js/grid/columns/date'],
            'boolean' => ['boolean', 'select', 'select', 'select', 'Magento_Ui/js/grid/columns/select'],
            'multiselect' => ['multiselect', 'multiselect', 'select', 'multiselect', 'Magento_Ui/js/grid/columns/select'],
            'media' => ['media_image', 'thumbnail', 'text', 'image', 'Magento_Ui/js/grid/columns/thumbnail'],
            'unknown' => ['swatch_visual', 'text', 'text', 'text', null],
        ];
    }

    #[DataProvider('inputMatrix')]
    public function testInputTypeDrivesDataTypeFilterEditorAndComponent(
        string $input,
        string $dataType,
        string $filter,
        string $editor,
        ?string $component
    ): void {
        $context = $this->createStub(ContextInterface::class);

        $this->factory()->create($this->attribute('attr', $input), $context, 205);

        [$name, $type, $args] = $this->created[0];
        $config = $args['data']['config'];
        $this->assertSame('attr', $name);
        $this->assertSame('column', $type);
        $this->assertSame($context, $args['context']);
        $this->assertSame($dataType, $config['dataType']);
        $this->assertSame($filter, $config['filter']);
        $this->assertSame($editor, $config['editor']['editorType']);
        $this->assertSame($component, $config['component'] ?? null);
        $this->assertSame(205, $config['sortOrder']);
        $this->assertFalse($config['visible']);
        $this->assertTrue($config['addField']);
        $this->assertSame('attr', $config['panthGridColumn']['attribute_code']);
        $this->assertSame($input === 'media_image' ? 1 : null, $config['has_preview'] ?? null);
    }

    public function testSelectOptionsDropEmptyValues(): void
    {
        $this->factory()->create(
            $this->attribute('color', 'select', 0, '', ['' => ' ', '49' => 'Red', '50' => 'Blue']),
            $this->createStub(ContextInterface::class),
            1
        );

        $this->assertSame(
            [['value' => '49', 'label' => 'Red'], ['value' => '50', 'label' => 'Blue']],
            $this->config()['options']
        );
    }

    public function testValidationRulesComeFromFrontendClassAndRequiredFlag(): void
    {
        $this->factory()->create(
            $this->attribute('sku_code', 'text', 1, ' validate-number  validate-zero-or-greater '),
            $this->createStub(ContextInterface::class),
            1
        );

        $this->assertSame(
            ['validate-number' => true, 'validate-zero-or-greater' => true, 'required-entry' => true],
            $this->config()['editor']['validation']
        );
    }

    public function testPlainAttributesWithoutFrontendClassGetNoRules(): void
    {
        $this->factory()->create(
            $this->attribute('plain', 'text', 0, '', [], false),
            $this->createStub(ContextInterface::class),
            1
        );

        $this->assertSame([], $this->config()['editor']['validation']);
        $this->assertArrayNotHasKey('options', $this->config());
    }
}

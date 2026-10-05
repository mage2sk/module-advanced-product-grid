<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\Component;

use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Api\BookmarkManagementInterface;
use Magento\Ui\Api\Data\BookmarkInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Model\GridConfig;
use Panth\AdvancedProductGrid\Ui\Component\Listing\AttributeRepository;
use Panth\AdvancedProductGrid\Ui\Component\Listing\ColumnFactory;
use Panth\AdvancedProductGrid\Ui\Component\Listing\Columns;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    private ContextInterface $context;
    private array $editable = [];
    private array $findable = [];
    private array $overrides = [];
    private array $bookmarkConfigs = [];
    private mixed $currentBookmarkConfig = null;
    private bool $currentBookmarkThrows = false;
    private array $factoryCalls = [];
    /** @var array<string,int> */
    private array $preparedCounts = [];
    private ?GridConfig $gridConfig = null;

    protected function setUp(): void
    {
        $this->context = $this->createStub(ContextInterface::class);
        $this->context->method('getProcessor')->willReturn($this->createStub(Processor::class));
    }

    private function column(string $name, array $config = []): Column
    {
        $column = self::getStubBuilder(Column::class)
            ->setConstructorArgs([
                $this->context,
                $this->createStub(UiComponentFactory::class),
                [],
                ['name' => $name, 'config' => $config],
            ])
            ->onlyMethods(['prepare'])
            ->getStub();
        $column->method('prepare')->willReturnCallback(function () use ($name) {
            $this->preparedCounts[$name] = ($this->preparedCounts[$name] ?? 0) + 1;
        });
        return $column;
    }

    private function attribute(string $code): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        return $attribute;
    }

    private function columns(array $existing): Columns
    {
        $repository = $this->createStub(AttributeRepository::class);
        $repository->method('getEditableAttributes')->willReturnCallback(fn () => $this->editable);
        $repository->method('findByCode')->willReturnCallback(
            fn ($code) => in_array($code, $this->findable, true) ? $this->attribute($code) : null
        );

        $factory = $this->createStub(ColumnFactory::class);
        $factory->method('create')->willReturnCallback(function ($attribute, $context, $sortOrder) {
            $code = $attribute->getAttributeCode();
            $this->factoryCalls[] = [$code, $sortOrder];
            return $this->column($code, [
                'label' => ucfirst($code),
                'dataType' => 'text',
                'sortOrder' => $sortOrder,
                'editor' => ['editorType' => 'text'],
                'filter' => 'text',
            ]);
        });

        $bookmarks = $this->createStub(BookmarkManagementInterface::class);
        $bookmarks->method('loadByNamespace')->willReturnCallback(function () {
            $items = [];
            foreach ($this->bookmarkConfigs as $config) {
                $bookmark = $this->createStub(BookmarkInterface::class);
                $bookmark->method('getConfig')->willReturn($config);
                $items[] = $bookmark;
            }
            $results = $this->createStub(SearchResultsInterface::class);
            $results->method('getItems')->willReturn($items);
            return $results;
        });
        $bookmarks->method('getByIdentifierNamespace')->willReturnCallback(function () {
            if ($this->currentBookmarkThrows) {
                throw new \RuntimeException('none');
            }
            if ($this->currentBookmarkConfig === null) {
                return null;
            }
            $bookmark = $this->createStub(BookmarkInterface::class);
            $bookmark->method('getConfig')->willReturn($this->currentBookmarkConfig);
            return $bookmark;
        });

        $config = $this->createStub(ColumnConfigRepository::class);
        $config->method('getAll')->willReturnCallback(fn () => $this->overrides);

        return new Columns(
            $this->context,
            $repository,
            $factory,
            $bookmarks,
            $config,
            $existing,
            ['name' => 'product_columns', 'config' => ['editorConfig' => ['enabled' => true, 'indexField' => 'entity_id']]],
            $this->gridConfig
        );
    }

    private function cfg(Columns $columns, string $code): array
    {
        return $columns->getChildComponents()[$code]->getData('config');
    }

    public function testGridAttributesAreAddedOnceAndPrepared(): void
    {
        $this->editable = [$this->attribute('name'), $this->attribute('color'), $this->attribute('size')];
        $columns = $this->columns(['name' => $this->column('name', ['label' => 'Name'])]);

        $columns->prepare();

        $this->assertSame([['color', 200], ['size', 201]], $this->factoryCalls);
        $this->assertSame(['name', 'color', 'size'], array_keys($columns->getChildComponents()));
        $this->assertSame(['color' => 1, 'size' => 1], $this->preparedCounts, 'pre-existing columns are prepared by the framework');
    }

    public function testUserEnabledAndBookmarkedAttributesAreAdded(): void
    {
        $this->findable = ['weight', 'material', 'gift'];
        $this->overrides = [
            'weight' => ['is_visible' => 0, 'is_editable' => 1],
            'hidden' => ['is_visible' => 0, 'is_editable' => 0],
            'panth_qty_sold' => ['is_visible' => 1],
            'ghost' => ['is_visible' => 1],
        ];
        $this->bookmarkConfigs = [[
            'views' => ['a' => ['data' => ['columns' => [
                'material' => ['visible' => true],
                'gift' => ['visible' => false],
                'panth_low_stock' => ['visible' => true],
                'weight' => ['visible' => true],
            ]]]],
        ]];
        $columns = $this->columns([]);

        $columns->prepare();

        $this->assertSame([['weight', 200], ['material', 201]], $this->factoryCalls);
    }

    public function testColumnOverridesRewriteLabelVisibilityFilterAndLayout(): void
    {
        $this->overrides = [
            'sku' => [
                'custom_label' => 'Code',
                'is_visible' => '0',
                'is_filterable' => '1',
                'is_editable' => '1',
                'is_required' => '1',
                'sort_order' => '15',
                'default_width' => '120',
                'marker_color' => 'Red!',
            ],
            'price' => ['is_filterable' => 0, 'is_editable' => 0],
        ];
        $columns = $this->columns([
            'sku' => $this->column('sku', ['label' => 'SKU', 'dataType' => 'text']),
            'price' => $this->column('price', ['label' => 'Price', 'filter' => 'textRange', 'editor' => ['editorType' => 'text']]),
        ]);

        $columns->prepare();

        $sku = $this->cfg($columns, 'sku');
        $this->assertSame('Code', $sku['label']);
        $this->assertFalse($sku['visible']);
        $this->assertSame('text', $sku['filter']);
        $this->assertSame('text', $sku['editor']['editorType']);
        $this->assertTrue($sku['editor']['validation']['required-entry']);
        $this->assertTrue($sku['addField']);
        $this->assertSame(15, $sku['sortOrder']);
        $this->assertSame(120, $sku['width']);
        $this->assertSame('ui/grid/cells/text', $sku['bodyTmpl']);
        $this->assertTrue($sku['additionalClasses']['panth-pg-marker-red']);
        $this->assertSame('Code', $sku['panthGridMeta']['customLabel']);
        $this->assertSame('SKU', $sku['panthGridMeta']['originalLabel']);
        $this->assertSame('standard', $sku['panthGridMeta']['group']);

        $price = $this->cfg($columns, 'price');
        $this->assertFalse($price['filter']);
        $this->assertArrayNotHasKey('editor', $price);
        $this->assertFalse($price['panthGridMeta']['editable']);
        $this->assertFalse($price['panthGridMeta']['filterable']);
        $this->assertSame('pricing', $price['panthGridMeta']['group']);
    }

    public function testPopupFieldsGetPopupEditorInsteadOfInlineEditor(): void
    {
        $this->overrides = [
            'description' => ['is_editable' => 1],
            'color' => ['is_editable' => 1],
            'created_at' => ['is_editable' => 1, 'is_filterable' => 1],
        ];
        $columns = $this->columns([
            'description' => $this->column('description', ['dataType' => 'text', 'editor' => ['editorType' => 'text']]),
            'color' => $this->column('color', ['dataType' => 'multiselect', 'options' => [['value' => 1, 'label' => 'R']]]),
            'created_at' => $this->column('created_at', ['dataType' => 'date']),
        ]);

        $columns->prepare();

        $description = $this->cfg($columns, 'description');
        $this->assertArrayNotHasKey('editor', $description);
        $this->assertSame(['type' => 'text', 'options' => []], $description['panthPopupEditor']);
        $color = $this->cfg($columns, 'color');
        $this->assertSame('multiselect', $color['panthPopupEditor']['type']);
        $this->assertSame([['value' => 1, 'label' => 'R']], $color['panthPopupEditor']['options']);
        $created = $this->cfg($columns, 'created_at');
        $this->assertSame('date', $created['editor']['editorType']);
        $this->assertSame('dateRange', $created['filter']);
        $this->assertSame('date', $created['panthGridMeta']['type']);
    }

    public function testPriceEditorsAreNormalisedToText(): void
    {
        $columns = $this->columns([
            'cost' => $this->column('cost', ['editor' => ['editorType' => 'price']]),
            'ids' => $this->column('ids', ['label' => 'IDs']),
        ]);

        $columns->prepare();

        $this->assertSame('text', $this->cfg($columns, 'cost')['editor']['editorType']);
        $this->assertArrayNotHasKey('panthGridMeta', $this->cfg($columns, 'ids'));
    }

    private function gridConfig(bool $enabled): GridConfig
    {
        $config = $this->createStub(GridConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    public function testDisabledGridKeepsStockColumnsWithoutModuleFeatures(): void
    {
        $this->gridConfig = $this->gridConfig(false);
        $this->editable = [$this->attribute('name'), $this->attribute('color')];
        $this->findable = ['meta_title'];
        $this->overrides = ['meta_title' => ['is_visible' => 1, 'custom_label' => 'Renamed'], 'name' => ['custom_label' => 'Title']];
        $columns = $this->columns([
            'name' => $this->column('name', ['label' => 'Name', 'editor' => ['editorType' => 'text'], 'filter' => 'text']),
            'panth_qty_sold' => $this->column('panth_qty_sold', ['label' => 'Qty Sold', 'visible' => true, 'filter' => 'textRange']),
        ]);

        $columns->prepare();

        $this->assertSame(['name', 'panth_qty_sold', 'color'], array_keys($columns->getChildComponents()));
        $name = $this->cfg($columns, 'name');
        $this->assertSame('Name', $name['label']);
        $this->assertArrayNotHasKey('editor', $name);
        $this->assertArrayNotHasKey('panthGridMeta', $name);
        $this->assertSame('text', $name['filter']);
        $qtySold = $this->cfg($columns, 'panth_qty_sold');
        $this->assertTrue($qtySold['componentDisabled']);
        $this->assertFalse($qtySold['visible']);
        $this->assertFalse($qtySold['filter']);
        $this->assertArrayNotHasKey('editor', $this->cfg($columns, 'color'));
        $this->assertSame(['color' => 1], $this->preparedCounts);
        $this->assertFalse($columns->getData('config')['editorConfig']['enabled']);
        $this->assertSame('entity_id', $columns->getData('config')['editorConfig']['indexField']);
    }

    public function testEnabledGridConfigKeepsTheExtendedBehaviour(): void
    {
        $this->gridConfig = $this->gridConfig(true);
        $columns = $this->columns([
            'name' => $this->column('name', ['label' => 'Name', 'editor' => ['editorType' => 'text']]),
        ]);

        $columns->prepare();

        $this->assertSame('text', $this->cfg($columns, 'name')['editor']['editorType']);
        $this->assertArrayHasKey('panthGridMeta', $this->cfg($columns, 'name'));
        $this->assertTrue($columns->getData('config')['editorConfig']['enabled']);
    }

    public function testSelectEditorsGetAnEmptyCaptionSoBlankValuesStayBlank(): void
    {
        $columns = $this->columns([
            'country_of_manufacture' => $this->column('country_of_manufacture', ['editor' => ['editorType' => 'select']]),
            'status' => $this->column('status', ['editor' => ['editorType' => 'select', 'caption' => 'Pick one']]),
            'name' => $this->column('name', ['editor' => ['editorType' => 'text']]),
        ]);

        $columns->prepare();

        $this->assertSame(' ', $this->cfg($columns, 'country_of_manufacture')['editor']['caption']);
        $this->assertSame('Pick one', $this->cfg($columns, 'status')['editor']['caption']);
        $this->assertArrayNotHasKey('caption', $this->cfg($columns, 'name')['editor']);
    }

    public function testCurrentBookmarkOverridesApplyVisibilityAndTitle(): void
    {
        $this->currentBookmarkConfig = ['current' => ['panthGridColumns' => [
            'name' => ['visible' => 0, 'title' => 'Product', 'marker' => 'blue'],
            'sku' => ['title' => ''],
            'bad' => 'scalar',
        ]]];
        $columns = $this->columns([
            'name' => $this->column('name', ['label' => 'Name', 'panthGridColumn' => ['editable' => true]]),
            'sku' => $this->column('sku', ['label' => 'SKU']),
        ]);

        $columns->prepare();

        $name = $this->cfg($columns, 'name');
        $this->assertFalse($name['visible']);
        $this->assertSame('Product', $name['label']);
        $this->assertSame(['editable' => true, 'visible' => 0, 'title' => 'Product', 'marker' => 'blue'], $name['panthGridColumn']);
        $this->assertSame('SKU', $this->cfg($columns, 'sku')['label']);
    }

    public function testMissingOrBrokenCurrentBookmarkIsIgnored(): void
    {
        foreach ([['throws' => true, 'config' => null], ['throws' => false, 'config' => 'nope'], ['throws' => false, 'config' => []]] as $case) {
            $this->currentBookmarkThrows = $case['throws'];
            $this->currentBookmarkConfig = $case['config'];
            $columns = $this->columns(['name' => $this->column('name', ['label' => 'Name'])]);

            $columns->prepare();

            $this->assertSame('Name', $this->cfg($columns, 'name')['label']);
            $this->assertArrayNotHasKey('panthGridColumn', $this->cfg($columns, 'name'));
        }
    }

    public function testGroupsAreResolvedForAnnotatedColumns(): void
    {
        $columns = $this->columns([
            'qty' => $this->column('qty'),
            'meta_title' => $this->column('meta_title'),
            'panth_storefront_url' => $this->column('panth_storefront_url'),
            'color' => $this->column('color'),
            'type_id' => $this->column('type_id'),
            'actions' => $this->column('actions'),
            'panth_availability' => $this->column('panth_availability'),
            'panth_tier_price_label' => $this->column('panth_tier_price_label'),
        ]);

        $columns->prepare();

        $this->assertSame('standard', $this->cfg($columns, 'type_id')['panthGridMeta']['group']);
        $this->assertSame('standard', $this->cfg($columns, 'actions')['panthGridMeta']['group']);
        $this->assertSame('inventory', $this->cfg($columns, 'panth_availability')['panthGridMeta']['group']);
        $this->assertSame('pricing', $this->cfg($columns, 'panth_tier_price_label')['panthGridMeta']['group']);
        $this->assertSame('inventory', $this->cfg($columns, 'qty')['panthGridMeta']['group']);
        $this->assertSame('seo', $this->cfg($columns, 'meta_title')['panthGridMeta']['group']);
        $meta = $this->cfg($columns, 'panth_storefront_url')['panthGridMeta'];
        $this->assertSame('extras', $meta['group']);
        $this->assertSame(1, $meta['isVirtual']);
        $this->assertSame('attributes', $this->cfg($columns, 'color')['panthGridMeta']['group']);
        $this->assertSame(100, $this->cfg($columns, 'color')['panthGridMeta']['sortOrder']);
    }
}

<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Convert\Excel;
use Magento\Framework\Convert\ExcelFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface as DirectoryWrite;
use Magento\Framework\Filesystem\File\WriteInterface as FileWrite;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\Model\Export\ConvertToCsv;
use Magento\Ui\Model\Export\ConvertToXml;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;
use Panth\AdvancedProductGrid\Model\GridConfig;
use Panth\AdvancedProductGrid\Plugin\Ui\Model\Export\CsvExport;
use Panth\AdvancedProductGrid\Plugin\Ui\Model\Export\XmlExport;
use PHPUnit\Framework\TestCase;

class ExportPluginsTest extends TestCase
{
    private array $config = ['panth_product_grid/export/visible_columns_only' => '1'];
    private string $componentName = 'product_listing';
    private bool $filterThrows = false;
    private bool $factoryThrows = false;
    private array $overrides = [];
    private array $columns = [];
    private array $items = [];
    private array $csvLines = [];
    private array $opened = [];
    private array $streamCalls = [];
    private array $prepared = [];
    private array $excelArgs = [];
    private array $excelHeader = [];

    private function column(string $name, array $config): UiComponentInterface
    {
        $column = $this->createStub(UiComponentInterface::class);
        $column->method('getComponentName')->willReturn('column');
        $column->method('getName')->willReturn($name);
        $column->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'config' ? $config : null);
        $column->method('getChildComponents')->willReturn([]);
        $column->method('prepare')->willReturnCallback(function () use ($name) {
            $this->prepared[] = $name;
        });
        return $column;
    }

    private function listing(): UiComponentInterface
    {
        $provider = $this->createStub(DataProviderInterface::class);
        $provider->method('getData')->willReturnCallback(fn () => ['items' => $this->items]);
        $context = $this->createStub(ContextInterface::class);
        $context->method('getDataProvider')->willReturn($provider);

        $columnsComponent = $this->createStub(UiComponentInterface::class);
        $columnsComponent->method('getComponentName')->willReturn('columns');
        $columnsComponent->method('getChildComponents')->willReturn($this->columns);
        $columnsComponent->method('prepare')->willReturnCallback(function () {
            $this->prepared[] = 'columns';
        });

        $listing = $this->createStub(UiComponentInterface::class);
        $listing->method('getChildComponents')->willReturn(['columns' => $columnsComponent]);
        $listing->method('getContext')->willReturn($context);
        $listing->method('prepare')->willReturnCallback(function () {
            $this->prepared[] = 'listing';
        });
        return $listing;
    }

    private function dependencies(): array
    {
        $factory = $this->createStub(UiComponentFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            if ($this->factoryThrows) {
                throw new \RuntimeException('layout broken');
            }
            return $this->listing();
        });

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->config[$path] ?? null);

        $stream = $this->createStub(FileWrite::class);
        $stream->method('writeCsv')->willReturnCallback(function ($line) {
            $this->csvLines[] = $line;
            return 1;
        });
        foreach (['lock', 'unlock', 'close'] as $method) {
            $stream->method($method)->willReturnCallback(function () use ($method) {
                $this->streamCalls[] = $method;
                return true;
            });
        }
        $directory = $this->createStub(DirectoryWrite::class);
        $directory->method('openFile')->willReturnCallback(function ($name, $mode) use ($stream) {
            $this->opened[] = [$name, $mode];
            return $stream;
        });
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        $component = $this->createStub(UiComponentInterface::class);
        $component->method('getName')->willReturnCallback(fn () => $this->componentName);
        $filter = $this->createStub(Filter::class);
        $filter->method('getComponent')->willReturnCallback(function () use ($component) {
            if ($this->filterThrows) {
                throw new \RuntimeException('no component');
            }
            return $component;
        });

        $repository = $this->createStub(ColumnConfigRepository::class);
        $repository->method('getAll')->willReturnCallback(fn () => $this->overrides);

        return [$factory, $scope, $filesystem, $filter, $repository];
    }

    private function csv(): CsvExport
    {
        return new CsvExport(...$this->dependencies());
    }

    private function xml(): XmlExport
    {
        $excel = $this->createStub(Excel::class);
        $excel->method('setDataHeader')->willReturnCallback(function ($header) {
            $this->excelHeader = $header;
        });
        $excel->method('write')->willReturnCallback(function () {
            $this->streamCalls[] = 'excel-write';
        });
        $excelFactory = $this->createStub(ExcelFactory::class);
        $excelFactory->method('create')->willReturnCallback(function ($args) use ($excel) {
            $this->excelArgs = $args;
            return $excel;
        });
        return new XmlExport(...[...$this->dependencies(), $excelFactory]);
    }

    private function proceed(): \Closure
    {
        return static fn () => ['type' => 'filename', 'value' => 'core.csv'];
    }

    public function testCoreExportRunsWhenOptionDisabledOrOtherListing(): void
    {
        $this->config = [];
        $this->assertSame('core.csv', $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);

        $this->config = ['panth_product_grid/export/visible_columns_only' => '1'];
        $this->componentName = 'cms_page_listing';
        $this->assertSame('core.csv', $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);

        $this->componentName = 'product_listing';
        $this->filterThrows = true;
        $this->assertSame('core.csv', $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);
        $this->assertSame([], $this->opened);
    }

    public function testCoreExportRunsWhenTheExtendedGridIsDisabled(): void
    {
        $this->config = ['panth_product_grid/export/visible_columns_only' => '1'];
        $grid = $this->createStub(GridConfig::class);
        $grid->method('isEnabled')->willReturn(false);

        $csv = new CsvExport(...[...$this->dependencies(), $grid]);
        $xml = new XmlExport(...[...$this->dependencies(), $this->createStub(ExcelFactory::class), $grid]);

        $this->assertSame('core.csv', $csv->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);
        $this->assertSame('core.csv', $xml->aroundGetXmlFile($this->createStub(ConvertToXml::class), $this->proceed())['value']);
        $this->assertSame([], $this->opened);
    }

    public function testCoreExportRunsWhenListingCannotBeBuiltOrHasNoVisibleColumns(): void
    {
        $this->factoryThrows = true;
        $this->assertSame('core.csv', $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);

        $this->factoryThrows = false;
        $this->columns = [$this->column('sku', ['visible' => false])];
        $this->assertSame('core.csv', $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed())['value']);
        $this->assertSame([], $this->opened);
    }

    public function testCsvContainsVisibleExportableColumnsInSortOrderWithRenderedCells(): void
    {
        $this->columns = [
            $this->column('name', ['label' => 'Name', 'sortOrder' => 20]),
            $this->column('status', [
                'label' => 'Status', 'dataType' => 'select', 'sortOrder' => 10,
                'options' => [['value' => '1', 'label' => 'Enabled'], 'junk', ['value' => '2', 'label' => 'Disabled']],
            ]),
            $this->column('color', [
                'label' => 'Color', 'dataType' => 'multiselect', 'sortOrder' => 30,
                'options' => [['value' => '49', 'label' => 'Red'], ['value' => '50', 'label' => 'Blue']],
            ]),
            $this->column('hidden', ['label' => 'Hidden', 'visible' => false]),
            $this->column('cost', ['label' => 'Cost', 'sortOrder' => 40]),
            $this->column('websites', ['sortOrder' => 50]),
        ];
        $this->overrides = ['cost' => ['in_export' => '0'], 'name' => ['in_export' => 1], 'websites' => ['custom_label' => 'x']];
        $this->items = [
            ['name' => 'Tee', 'status' => '1', 'color' => '49,51', 'cost' => '3', 'websites' => [1, 2]],
            ['status' => '7', 'color' => ['50']],
        ];

        $result = $this->csv()->aroundGetCsvFile($this->createStub(ConvertToCsv::class), $this->proceed());

        $this->assertSame('filename', $result['type']);
        $this->assertTrue($result['rm']);
        $this->assertMatchesRegularExpression('#^export/panth_product_grid_\d+\.csv$#', $result['value']);
        $this->assertSame([[$result['value'], 'w+']], $this->opened);
        $this->assertSame([
            ['Status', 'Name', 'Color', 'websites'],
            ['Enabled', 'Tee', 'Red, 51', '1, 2'],
            ['7', '', 'Blue', ''],
        ], $this->csvLines);
        $this->assertSame(['lock', 'unlock', 'close'], $this->streamCalls);
        $this->assertContains('listing', $this->prepared);
        $this->assertContains('name', $this->prepared);
    }

    public function testXmlExportBuildsSpreadsheetFromTheSameColumns(): void
    {
        $this->columns = [
            $this->column('sku', ['label' => 'SKU', 'sortOrder' => 1]),
            $this->column('status', [
                'label' => 'Status', 'dataType' => 'select', 'sortOrder' => 2,
                'options' => [['value' => '1', 'label' => 'Enabled']],
            ]),
        ];
        $this->items = [['sku' => 'A', 'status' => '1'], ['sku' => 'B', 'status' => '2']];

        $result = $this->xml()->aroundGetXmlFile($this->createStub(ConvertToXml::class), static fn () => 'core');

        $this->assertStringEndsWith('.xml', $result['value']);
        $this->assertSame(['SKU', 'Status'], $this->excelHeader);
        $this->assertSame([['A', 'Enabled'], ['B', '2']], iterator_to_array($this->excelArgs['iterator']));
        $this->assertSame(['x'], ($this->excelArgs['rowCallback'])(['x']));
        $this->assertSame(['lock', 'excel-write', 'unlock', 'close'], $this->streamCalls);
    }

    public function testXmlFallsBackToCoreWhenDisabled(): void
    {
        $this->config = [];

        $this->assertSame('core', $this->xml()->aroundGetXmlFile($this->createStub(ConvertToXml::class), static fn () => 'core'));
    }
}

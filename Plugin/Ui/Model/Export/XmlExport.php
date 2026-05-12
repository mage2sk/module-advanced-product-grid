<?php
/**
 * XML twin of CsvExport — same column resolution, Excel writer.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Ui\Model\Export;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Convert\ExcelFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\Model\Export\ConvertToXml;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

class XmlExport extends AbstractExport
{
    public function __construct(
        UiComponentFactory $componentFactory,
        ScopeConfigInterface $scopeConfig,
        Filesystem $filesystem,
        Filter $filter,
        ColumnConfigRepository $columnConfigRepository,
        private readonly ExcelFactory $excelFactory
    ) {
        parent::__construct($componentFactory, $scopeConfig, $filesystem, $filter, $columnConfigRepository);
    }

    /**
     * @return array{type: string, value: string, rm: bool}
     */
    public function aroundGetXmlFile(ConvertToXml $subject, \Closure $proceed)
    {
        if (!$this->exportVisibleColumnsOnly()) {
            return $proceed();
        }
        $listing = $this->describeListing('product_listing');
        if ($listing === null) {
            return $proceed();
        }
        $component = $listing['component'];
        $columns = $this->filterByExportFlag($listing['columns']);

        $file = $this->openExportFile('xml');
        $stream = $file['stream'];

        $items = $component->getContext()->getDataProvider()->getData()['items'] ?? [];
        $rows = [];
        foreach ($items as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->renderCell($row, $column);
            }
            $rows[] = $line;
        }
        $iterator = new \ArrayIterator($rows);
        $excel = $this->excelFactory->create([
            'iterator' => $iterator,
            'rowCallback' => static fn ($row) => $row,
        ]);
        $excel->setDataHeader(array_map(static fn ($c) => $c['label'], $columns));
        $excel->write($stream, $file['name']);

        $stream->unlock();
        $stream->close();

        return [
            'type' => 'filename',
            'value' => $file['name'],
            'rm' => true,
        ];
    }
}

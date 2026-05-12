<?php
/**
 * Around plugin on Magento's CSV export. Produces a visible-columns-only
 * export (respecting the user's grid layout) when the config flag is
 * set; falls through to the parent implementation otherwise.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Ui\Model\Export;

use Magento\Ui\Model\Export\ConvertToCsv;

class CsvExport extends AbstractExport
{
    /**
     * @return array{type: string, value: string, rm: bool}
     */
    public function aroundGetCsvFile(ConvertToCsv $subject, \Closure $proceed)
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

        $file = $this->openExportFile('csv');
        $stream = $file['stream'];
        $stream->writeCsv(array_map(static fn ($c) => $c['label'], $columns));

        $items = $component->getContext()->getDataProvider()->getData()['items'] ?? [];
        foreach ($items as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->renderCell($row, $column);
            }
            $stream->writeCsv($line);
        }
        $stream->unlock();
        $stream->close();

        return [
            'type' => 'filename',
            'value' => $file['name'],
            'rm' => true,
        ];
    }
}

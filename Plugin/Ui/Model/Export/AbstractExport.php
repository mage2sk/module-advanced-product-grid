<?php
/**
 * Shared logic between the CSV and XML export plugins: resolve the
 * UiComponent for product_listing, sort visible columns, and produce
 * per-column label resolution for select/multiselect cells.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Plugin\Ui\Model\Export;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedProductGrid\Model\ColumnConfigRepository;

abstract class AbstractExport
{
    protected const CFG_VISIBLE_COLUMNS_ONLY = 'panth_product_grid/export/visible_columns_only';

    public function __construct(
        protected readonly UiComponentFactory $componentFactory,
        protected readonly ScopeConfigInterface $scopeConfig,
        protected readonly Filesystem $filesystem,
        protected readonly Filter $filter,
        protected readonly ColumnConfigRepository $columnConfigRepository
    ) {
    }

    /**
     * Drop columns the admin flagged as `in_export = 0` in Column Manager.
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    protected function filterByExportFlag(array $columns): array
    {
        $overrides = $this->columnConfigRepository->getAll();
        return array_values(array_filter($columns, static function ($col) use ($overrides) {
            $cfg = $overrides[$col['name']] ?? null;
            if ($cfg === null) {
                return true;
            }
            return !isset($cfg['in_export']) || (int)$cfg['in_export'] === 1;
        }));
    }

    protected function exportVisibleColumnsOnly(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::CFG_VISIBLE_COLUMNS_ONLY);
    }

    protected function openExportFile(string $extension): array
    {
        $name = 'export/panth_product_grid_' . hrtime(true) . '.' . $extension;
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $stream = $directory->openFile($name, 'w+');
        $stream->lock();
        return ['name' => $name, 'stream' => $stream];
    }

    /**
     * @return array{component: UiComponentInterface, columns: list<array{name: string, label: string, type: string, options: array<int|string, string>}>}|null
     */
    protected function describeListing(string $componentName): ?array
    {
        try {
            $component = $this->componentFactory->create($componentName);
            $this->prepareComponent($component);
        } catch (\Throwable) {
            return null;
        }
        $columns = $this->collectVisibleColumns($component);
        if ($columns === []) {
            return null;
        }
        return ['component' => $component, 'columns' => $columns];
    }

    private function prepareComponent(UiComponentInterface $component): void
    {
        foreach ($component->getChildComponents() as $child) {
            $this->prepareComponent($child);
        }
        $component->prepare();
    }

    /**
     * @return list<array{name: string, label: string, type: string, options: array<int|string, string>}>
     */
    private function collectVisibleColumns(UiComponentInterface $component): array
    {
        $columns = [];
        $this->collectColumns($component, $columns);

        $columns = array_filter($columns, static fn ($c) => $c['visible']);
        usort($columns, static fn ($a, $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return array_values(array_map(
            static fn ($c) => [
                'name' => $c['name'],
                'label' => $c['label'],
                'type' => $c['type'],
                'options' => $c['options'],
            ],
            $columns
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    private function collectColumns(UiComponentInterface $component, array &$columns): void
    {
        foreach ($component->getChildComponents() as $child) {
            if ($child->getComponentName() === 'column') {
                $config = $child->getData('config') ?? [];
                $options = [];
                foreach ((array)($config['options'] ?? []) as $opt) {
                    if (!is_array($opt)) { continue; }
                    $options[(string)($opt['value'] ?? '')] = (string)($opt['label'] ?? '');
                }
                $columns[] = [
                    'name' => (string)$child->getName(),
                    'label' => (string)($config['label'] ?? $child->getName()),
                    'type' => (string)($config['dataType'] ?? 'text'),
                    'options' => $options,
                    'visible' => $config['visible'] ?? true,
                    'sortOrder' => (int)($config['sortOrder'] ?? 100),
                ];
            }
            $this->collectColumns($child, $columns);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $column
     */
    protected function renderCell(array $row, array $column): string
    {
        $name = $column['name'];
        $value = $row[$name] ?? '';

        if ($column['type'] === 'select' && isset($column['options'][(string)$value])) {
            return $column['options'][(string)$value];
        }
        if ($column['type'] === 'multiselect') {
            $values = is_array($value) ? $value : explode(',', (string)$value);
            $labels = [];
            foreach ($values as $v) {
                $labels[] = $column['options'][(string)$v] ?? (string)$v;
            }
            return implode(', ', $labels);
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        return (string)$value;
    }
}

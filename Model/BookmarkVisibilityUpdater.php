<?php
/**
 * Syncs per-column visibility + sort positions into the admin user's
 * bookmark for product_listing so the Manage Columns toggles + sort_order
 * inputs actually affect the rendered grid.
 *
 * Magento's grid renders visibility from `data.columns.<code>.visible`
 * and column order from `data.positions[<code>]` — both stored as JSON
 * in `ui_bookmark.config`. A DB row in panth_product_grid_column_config
 * alone has no effect; we have to write through to the bookmark.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\ResourceConnection;

class BookmarkVisibilityUpdater
{
    private const NAMESPACE = 'product_listing';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * @param array<string, bool> $visibility   code => visible
     * @param array<string, int>  $positions    code => 0-based grid position
     */
    public function apply(array $visibility, array $positions = []): void
    {
        if ($visibility === [] && $positions === []) {
            return;
        }
        $userId = (int)($this->authSession->getUser()?->getId() ?? 0);
        if ($userId <= 0) {
            return;
        }

        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('ui_bookmark');
        if (!$conn->isTableExists($table)) {
            return;
        }

        $select = $conn->select()
            ->from($table, ['bookmark_id', 'config'])
            ->where('namespace = ?', self::NAMESPACE)
            ->where('user_id = ?', $userId);

        foreach ($conn->fetchAll($select) as $row) {
            $config = json_decode((string)$row['config'], true);
            if (!is_array($config)) {
                $config = [];
            }
            $config = $this->applyToTree($config, $visibility, $positions);
            $conn->update(
                $table,
                ['config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                ['bookmark_id = ?' => (int)$row['bookmark_id']]
            );
        }
    }

    /**
     * Walk + mutate every `columns` and `positions` node in the bookmark
     * JSON. If none exist, seed them under the canonical
     * `views.current.data.{columns,positions}` path.
     *
     * @param array<string, mixed> $node
     * @param array<string, bool> $visibility
     * @param array<string, int> $positions
     * @return array<string, mixed>
     */
    private function applyToTree(array $node, array $visibility, array $positions): array
    {
        $touchedColumns = false;
        $touchedPositions = false;
        $this->walk($node, $visibility, $positions, $touchedColumns, $touchedPositions);

        if (!$touchedColumns && $visibility !== []) {
            $node['views'] = $node['views'] ?? [];
            $node['views']['current'] = $node['views']['current'] ?? [];
            $node['views']['current']['data'] = $node['views']['current']['data'] ?? [];
            $cols = $node['views']['current']['data']['columns'] ?? [];
            foreach ($visibility as $code => $visible) {
                $cols[$code] = array_merge((array)($cols[$code] ?? []), ['visible' => (bool)$visible]);
            }
            $node['views']['current']['data']['columns'] = $cols;
        }
        if (!$touchedPositions && $positions !== []) {
            $node['views'] = $node['views'] ?? [];
            $node['views']['current'] = $node['views']['current'] ?? [];
            $node['views']['current']['data'] = $node['views']['current']['data'] ?? [];
            $pos = $node['views']['current']['data']['positions'] ?? [];
            foreach ($positions as $code => $idx) {
                $pos[$code] = (int)$idx;
            }
            $node['views']['current']['data']['positions'] = $pos;
        }
        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $visibility
     * @param array<string, int> $positions
     */
    private function walk(array &$node, array $visibility, array $positions, bool &$touchedCols, bool &$touchedPos): void
    {
        foreach ($node as $key => &$value) {
            if ($key === 'columns' && is_array($value) && $visibility !== []) {
                foreach ($visibility as $code => $visible) {
                    $value[$code] = array_merge((array)($value[$code] ?? []), ['visible' => (bool)$visible]);
                }
                $touchedCols = true;
            }
            if ($key === 'positions' && is_array($value) && $positions !== []) {
                $value = $this->rebuildPositions($value, $positions);
                $touchedPos = true;
            }
            if (is_array($value)) {
                $this->walk($value, $visibility, $positions, $touchedCols, $touchedPos);
            }
        }
    }

    /**
     * Overlay user-supplied sort_order values on the existing positions
     * map, then renumber everything ascending so the user's relative
     * order is what the grid renders. Ties break alphabetically by code
     * for deterministic output.
     *
     * Example:
     *   existing = [ids:0, entity_id:1, name:5, sku:6, cost:30]
     *   user     = [cost:1, sku:2]
     *   merged   = [ids:0, entity_id:1, cost:1, name:5, sku:2]
     *   sorted   = [ids:0, entity_id:1, cost:1, sku:2, name:5]
     *   final    = [ids:0, entity_id:1, cost:2, sku:3, name:4]
     *
     * @param array<string, int|float> $existing
     * @param array<string, int> $overrides
     * @return array<string, int>
     */
    private function rebuildPositions(array $existing, array $overrides): array
    {
        $merged = $existing;
        foreach ($overrides as $code => $value) {
            $merged[$code] = (int)$value;
        }

        $pairs = [];
        foreach ($merged as $code => $value) {
            $pairs[] = [(string)$code, (int)$value];
        }
        usort($pairs, static function ($a, $b) {
            $cmp = $a[1] <=> $b[1];
            return $cmp !== 0 ? $cmp : strcmp($a[0], $b[0]);
        });

        $out = [];
        $i = 0;
        foreach ($pairs as $pair) {
            $out[$pair[0]] = $i++;
        }
        return $out;
    }
}

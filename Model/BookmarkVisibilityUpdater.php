<?php
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

<?php

namespace App\Payroll;

/**
 * Cost center tree rules. Plain arrays in, plain arrays out: no database, no framework.
 * A node: ['id' => int, 'parent_id' => ?int, 'level' => int, 'code' => string, 'name' => string, 'is_active' => bool], keyed by id.
 * An employee is assigned to a LEAF (a node without active children), which may be at level 2 or 3.
 */
final class CostCenterTree
{
    /** @return array<int, array> the nodes from the top level down to $id */
    public static function path(array $nodes, int $id): array
    {
        $out = [];
        $seen = [];
        $cur = $id;
        while ($cur !== null) {
            if (! isset($nodes[$cur])) {
                throw new FormulaException('Cost center not found.');
            }
            if (isset($seen[$cur])) {
                throw new FormulaException('The cost center tree contains a loop.');
            }
            $seen[$cur] = true;
            array_unshift($out, $nodes[$cur]);
            $p = $nodes[$cur]['parent_id'];
            $cur = $p === null ? null : (int) $p;
        }

        return $out;
    }

    public static function label(array $nodes, int $id, string $sep = ' > '): string
    {
        return implode($sep, array_map(fn ($n) => $n['name'], self::path($nodes, $id)));
    }

    /** @return array<int, array> */
    public static function children(array $nodes, int $id, bool $activeOnly = false): array
    {
        return array_values(array_filter($nodes, fn ($n) => $n['parent_id'] !== null && (int) $n['parent_id'] === $id && (! $activeOnly || $n['is_active'])));
    }

    public static function isLeaf(array $nodes, int $id): bool
    {
        return self::children($nodes, $id, true) === [];
    }

    /** Error text when a child cannot be added under $parent (null parent = a new top-level node). Null = allowed. */
    public static function childProblem(?array $parent, int $maxLevel): ?string
    {
        if ($parent === null) {
            return null;
        }
        if (! $parent['is_active']) {
            return 'The parent cost center is inactive.';
        }

        return $parent['level'] + 1 > $maxLevel ? "Cost centers can have at most {$maxLevel} levels." : null;
    }

    /**
     * What a pay run stores for an employee: the leaf, its full path and one entry per level (missing deeper levels are null).
     *
     * @return array{cost_center_id: int, path: string, levels: array<int, ?array{id: int, code: string, name: string}>}
     */
    public static function snapshot(array $nodes, int $id, int $maxLevel = 3, string $sep = ' > '): array
    {
        $path = self::path($nodes, $id);
        $levels = array_fill(1, $maxLevel, null);
        foreach ($path as $i => $n) {
            if ($i + 1 <= $maxLevel) {
                $levels[$i + 1] = ['id' => (int) $n['id'], 'code' => $n['code'], 'name' => $n['name']];
            }
        }

        return ['cost_center_id' => $id, 'path' => implode($sep, array_map(fn ($n) => $n['name'], $path)), 'levels' => $levels];
    }

    /** @return array<int, array{node: array, depth: int}> depth-first, siblings by code, ready to print as an indented tree */
    public static function flatten(array $nodes): array
    {
        $byParent = [];
        foreach ($nodes as $n) {
            $byParent[$n['parent_id'] === null ? 0 : (int) $n['parent_id']][] = $n;
        }
        foreach ($byParent as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['code'], $b['code']));
        }
        unset($list);

        $out = [];
        $seen = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, &$seen, $byParent) {
            foreach ($byParent[$parent] ?? [] as $n) {
                if (isset($seen[$n['id']])) {
                    continue;
                }
                $seen[$n['id']] = true;
                $out[] = ['node' => $n, 'depth' => $depth];
                $walk((int) $n['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}

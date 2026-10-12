<?php

namespace App\Payroll;

use App\Payroll\Formula\Formula;

/**
 * Calculates pay components from definitions. A definition is a plain array:
 *   name, type, method (fixed|percentage|formula|input|system), formula, amount, percentage_rate, percentage_of[],
 *   rounding_mode, rounding_dp, active
 * Components can reference each other by code; the calculation order is derived automatically.
 */
final class ComponentCalculator
{
    public const LEAF_METHODS = ['fixed', 'input', 'system'];

    private static array $astCache = [];

    /** The formula text a definition evaluates (percentage components are compiled to a formula). */
    public static function effectiveFormula(array $def): ?string
    {
        return match ($def['method']) {
            'formula' => trim((string) ($def['formula'] ?? '')) ?: null,
            'percentage' => empty($def['percentage_of'])
                ? null
                : '('.implode(' + ', $def['percentage_of']).') * '.Decimal::make($def['percentage_rate'] ?? '0').' / 100',
            default => null,
        };
    }

    private function ast(string $formula): array
    {
        return self::$astCache[$formula] ??= Formula::parse($formula);
    }

    /** @return array<string, string[]> variable names each definition references */
    public function references(array $defs): array
    {
        $out = [];
        foreach ($defs as $code => $def) {
            $f = self::effectiveFormula($def);
            try {
                $out[$code] = $f === null ? [] : Formula::references($this->ast($f));
            } catch (FormulaException $e) {
                throw new FormulaException("{$code}: ".$e->getMessage(), 0, $e);
            }
        }

        return $out;
    }

    /** @return array<string, string[]> code => codes it depends on (only other components) */
    public function dependencies(array $defs): array
    {
        $deps = [];
        foreach ($this->references($defs) as $code => $refs) {
            $deps[$code] = array_values(array_filter($refs, fn ($r) => isset($defs[$r])));
        }

        return $deps;
    }

    /** Calculation order (dependencies first). Throws on circular references, naming the loop. */
    public function order(array $defs): array
    {
        $deps = $this->dependencies($defs);
        $state = [];
        $order = [];
        $visit = null;
        $visit = function (string $code, array $path) use (&$visit, &$state, &$order, $deps) {
            $s = $state[$code] ?? 0;
            if ($s === 2) {
                return;
            }
            if ($s === 1) {
                $loop = array_slice($path, (int) array_search($code, $path, true));
                $loop[] = $code;
                throw new FormulaException('Circular reference: '.implode(' → ', $loop).'.');
            }
            $state[$code] = 1;
            foreach ($deps[$code] as $d) {
                $visit($d, [...$path, $code]);
            }
            $state[$code] = 2;
            $order[] = $code;
        };

        foreach (array_keys($defs) as $code) {
            $visit($code, []);
        }

        return $order;
    }

    /** Targets plus everything they depend on, in calculation order. */
    public function closure(array $defs, array $targets): array
    {
        $deps = $this->dependencies($defs);
        $set = [];
        $stack = array_values($targets);
        while ($stack) {
            $c = array_pop($stack);
            if (! isset($defs[$c]) || isset($set[$c])) {
                continue;
            }
            $set[$c] = true;
            array_push($stack, ...$deps[$c]);
        }
        $subset = array_intersect_key($defs, $set);

        return $this->order($subset);
    }

    /** What a caller must supply to calculate $targets: leaf components and system variables. */
    public function needs(array $defs, array $targets): array
    {
        $codes = $this->closure($defs, $targets);
        $subset = array_intersect_key($defs, array_flip($codes));
        $refs = $this->references($subset);

        $leaves = array_values(array_filter($codes, fn ($c) => in_array($defs[$c]['method'], self::LEAF_METHODS, true)));
        $vars = [];
        foreach ($refs as $r) {
            foreach ($r as $name) {
                if (! isset($defs[$name])) {
                    $vars[$name] = true;
                }
            }
        }

        return ['leaves' => $leaves, 'variables' => array_keys($vars), 'order' => $codes];
    }

    /**
     * @param  array<string, mixed>  $values  system variables and amounts for leaf components, keyed by NAME
     * @return array{order: string[], results: array<string, array>}
     */
    public function calculate(array $defs, array $values, ?array $targets = null): array
    {
        $codes = $targets === null ? $this->order($defs) : $this->closure($defs, $targets);
        $deps = $this->dependencies($defs);

        $vars = [];
        foreach ($values as $name => $value) {
            $vars[strtoupper((string) $name)] = Decimal::make($value);
        }

        $results = [];
        foreach ($codes as $code) {
            $def = $defs[$code];
            $formula = self::effectiveFormula($def);

            if (in_array($def['method'], self::LEAF_METHODS, true)) {
                $raw = array_key_exists($code, $vars) ? $vars[$code] : ($def['method'] === 'fixed' ? Decimal::make($def['amount'] ?? '0') : '0');
            } else {
                if ($formula === null) {
                    throw new FormulaException("{$code}: the component has no formula.");
                }
                try {
                    $raw = Formula::evaluate($this->ast($formula), $vars);
                } catch (FormulaException $e) {
                    throw new FormulaException("{$code}: ".$e->getMessage(), 0, $e);
                }
                if (is_bool($raw)) {
                    throw new FormulaException("{$code}: a formula must produce a number, not TRUE/FALSE.");
                }
            }

            $value = Decimal::round($raw, (int) ($def['rounding_dp'] ?? 2), $def['rounding_mode'] ?? 'half_up');
            $vars[$code] = Decimal::plain($value); // later components use the rounded (payslip) amount

            $results[$code] = [
                'code' => $code, 'name' => $def['name'] ?? $code, 'type' => $def['type'] ?? null, 'method' => $def['method'],
                'formula' => $formula, 'raw' => Decimal::plain($raw), 'value' => $value, 'depends' => $deps[$code] ?? [],
            ];
        }

        return ['order' => $codes, 'results' => $results];
    }
}

<?php

namespace App\Payroll\Formula;

use App\Payroll\Decimal;

/** Facade for the formula engine. */
final class Formula
{
    public static function parse(string $source): array
    {
        Decimal::ensureAvailable();

        return (new Parser)->parse($source);
    }

    /** Variable names a parsed formula uses (unique, in order of appearance). */
    public static function references(array $ast): array
    {
        $found = [];
        $walk = function (array $n) use (&$walk, &$found) {
            if (($n['t'] ?? null) === 'var') {
                $found[$n['n']] = true;
            }
            foreach (['x', 'l', 'r'] as $k) {
                if (isset($n[$k])) {
                    $walk($n[$k]);
                }
            }
            foreach ($n['a'] ?? [] as $arg) {
                $walk($arg);
            }
        };
        $walk($ast);

        return array_keys($found);
    }

    public static function evaluate(array $ast, array $vars): string|bool
    {
        return (new Evaluator)->evaluate($ast, $vars);
    }

    /** Parse and evaluate in one go. Variable names are case-insensitive; numeric values are validated. */
    public static function run(string $source, array $vars = []): string|bool
    {
        $clean = [];
        foreach ($vars as $name => $value) {
            $clean[strtoupper((string) $name)] = is_bool($value) ? $value : Decimal::make($value);
        }

        return self::evaluate(self::parse($source), $clean);
    }
}

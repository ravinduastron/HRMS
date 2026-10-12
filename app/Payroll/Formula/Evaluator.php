<?php

namespace App\Payroll\Formula;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;

/** Evaluates a parsed formula against a variable map. No access to anything outside $vars. */
final class Evaluator
{
    public function evaluate(array $n, array $vars): string|bool
    {
        switch ($n['t']) {
            case 'num':
            case 'bool':
                return $n['v'];
            case 'var':
                if (! array_key_exists($n['n'], $vars)) {
                    throw new FormulaException("Unknown variable {$n['n']}.");
                }

                return $vars[$n['n']];
            case 'neg':
                return Decimal::neg($this->num($this->evaluate($n['x'], $vars)));
            case 'pct':
                return Decimal::div($this->num($this->evaluate($n['x'], $vars)), '100');
            case 'not':
                return ! $this->truthy($this->evaluate($n['x'], $vars));
            case 'logic':
                $l = $this->truthy($this->evaluate($n['l'], $vars));
                if ($n['op'] === 'AND') {
                    return $l && $this->truthy($this->evaluate($n['r'], $vars));
                }

                return $l || $this->truthy($this->evaluate($n['r'], $vars));
            case 'bin':
                return $this->binary($n, $vars);
            case 'call':
                return $this->call($n, $vars);
        }

        throw new FormulaException('Unsupported expression.');
    }

    private function binary(array $n, array $vars): string|bool
    {
        $l = $this->evaluate($n['l'], $vars);
        $r = $this->evaluate($n['r'], $vars);

        if (in_array($n['op'], ['==', '!='], true) && is_bool($l) && is_bool($r)) {
            return $n['op'] === '==' ? $l === $r : $l !== $r;
        }

        $a = $this->num($l);
        $b = $this->num($r);

        return match ($n['op']) {
            '+' => Decimal::add($a, $b),
            '-' => Decimal::sub($a, $b),
            '*' => Decimal::mul($a, $b),
            '/' => Decimal::div($a, $b),
            '==' => Decimal::cmp($a, $b) === 0,
            '!=' => Decimal::cmp($a, $b) !== 0,
            '<' => Decimal::cmp($a, $b) < 0,
            '<=' => Decimal::cmp($a, $b) <= 0,
            '>' => Decimal::cmp($a, $b) > 0,
            '>=' => Decimal::cmp($a, $b) >= 0,
        };
    }

    private function call(array $n, array $vars): string|bool
    {
        $args = $n['a'];

        switch ($n['f']) {
            case 'IF': // lazy: only the chosen branch is evaluated
                return $this->evaluate($this->truthy($this->evaluate($args[0], $vars)) ? $args[1] : $args[2], $vars);
            case 'MIN':
            case 'MAX':
                $best = null;
                foreach ($args as $a) {
                    $v = $this->num($this->evaluate($a, $vars));
                    if ($best === null || ($n['f'] === 'MIN' ? Decimal::cmp($v, $best) < 0 : Decimal::cmp($v, $best) > 0)) {
                        $best = $v;
                    }
                }

                return $best;
            case 'ROUND':
            case 'ROUNDUP':
            case 'ROUNDDOWN':
                $mode = ['ROUND' => 'half_up', 'ROUNDUP' => 'up', 'ROUNDDOWN' => 'down'][$n['f']];
                $dp = isset($args[1]) ? $this->decimals($this->evaluate($args[1], $vars)) : 0;

                return Decimal::plain(Decimal::round($this->num($this->evaluate($args[0], $vars)), $dp, $mode));
            case 'FLOOR':
                return Decimal::floor($this->num($this->evaluate($args[0], $vars)));
            case 'CEIL':
                return Decimal::ceil($this->num($this->evaluate($args[0], $vars)));
            case 'ABS':
                return Decimal::abs($this->num($this->evaluate($args[0], $vars)));
            case 'MOD':
                return Decimal::mod($this->num($this->evaluate($args[0], $vars)), $this->num($this->evaluate($args[1], $vars)));
        }

        throw new FormulaException("Unknown function {$n['f']}.");
    }

    private function num(mixed $v): string
    {
        if (is_bool($v)) {
            throw new FormulaException('Expected a number but found '.($v ? 'TRUE' : 'FALSE').'.');
        }

        return Decimal::make($v);
    }

    private function truthy(mixed $v): bool
    {
        return is_bool($v) ? $v : Decimal::cmp(Decimal::make($v), '0') !== 0;
    }

    private function decimals(mixed $v): int
    {
        $s = $this->num($v);
        if (! preg_match('/^\d+$/', $s) || (int) $s > 10) {
            throw new FormulaException('The number of decimal places must be a whole number from 0 to 10.');
        }

        return (int) $s;
    }
}

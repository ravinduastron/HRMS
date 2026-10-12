<?php

namespace App\Payroll\Formula;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;

/**
 * Tokenizer + recursive-descent parser. Produces a plain-array AST; never executes anything.
 *
 *   expr    := or ;  or := and (OR and)* ;  and := not (AND not)* ;  not := NOT not | cmp
 *   cmp     := add ((= == != <> < <= > >=) add)?
 *   add     := mul ((+|-) mul)* ;  mul := unary ((*|/) unary)*
 *   unary   := (-|+) unary | postfix ;  postfix := primary '%'*      (50% = 0.5)
 *   primary := NUMBER | TRUE | FALSE | IDENT '(' args ')' | IDENT | '(' expr ')'
 */
final class Parser
{
    /** function => [min args, max args (null = unlimited)] */
    public const FUNCTIONS = [
        'IF' => [3, 3], 'MIN' => [1, null], 'MAX' => [1, null],
        'ROUND' => [1, 2], 'ROUNDUP' => [1, 2], 'ROUNDDOWN' => [1, 2],
        'FLOOR' => [1, 1], 'CEIL' => [1, 1], 'ABS' => [1, 1], 'MOD' => [2, 2],
    ];

    /** Words a component code may not use. */
    public const RESERVED = ['AND', 'OR', 'NOT', 'TRUE', 'FALSE', 'IF', 'MIN', 'MAX', 'ROUND', 'ROUNDUP', 'ROUNDDOWN', 'FLOOR', 'CEIL', 'ABS', 'MOD'];

    private array $tokens = [];
    private int $p = 0;

    public function parse(string $src): array
    {
        if (trim($src) === '') {
            throw new FormulaException('The formula is empty.');
        }
        $this->tokens = $this->tokenize($src);
        $this->p = 0;

        $node = $this->orExpr();
        if ($this->peek()['type'] !== 'eof') {
            throw $this->unexpected($this->peek());
        }

        return $node;
    }

    /* ------------------------------ lexer ------------------------------ */

    private function tokenize(string $src): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (preg_match('/\G(\d+(?:\.\d+)?|\.\d+)/', $src, $m, 0, $i)) {
                $tokens[] = ['type' => 'num', 'value' => $m[0], 'pos' => $i + 1];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $src, $m, 0, $i)) {
                $word = strtoupper($m[0]);
                $type = in_array($word, ['AND', 'OR', 'NOT'], true) ? 'op' : (in_array($word, ['TRUE', 'FALSE'], true) ? 'bool' : 'id');
                $tokens[] = ['type' => $type, 'value' => $word, 'pos' => $i + 1];
                $i += strlen($m[0]);
                continue;
            }

            $two = substr($src, $i, 2);
            $map2 = ['<=' => '<=', '>=' => '>=', '!=' => '!=', '<>' => '!=', '==' => '==', '&&' => 'AND', '||' => 'OR'];
            if (isset($map2[$two])) {
                $tokens[] = ['type' => 'op', 'value' => $map2[$two], 'pos' => $i + 1];
                $i += 2;
                continue;
            }

            $map1 = ['+' => '+', '-' => '-', '*' => '*', '/' => '/', '<' => '<', '>' => '>', '=' => '==', '!' => 'NOT', '%' => '%'];
            if (isset($map1[$c])) {
                $tokens[] = ['type' => 'op', 'value' => $map1[$c], 'pos' => $i + 1];
                $i++;
                continue;
            }
            if ($c === '(') { $tokens[] = ['type' => 'lp', 'value' => '(', 'pos' => $i + 1]; $i++; continue; }
            if ($c === ')') { $tokens[] = ['type' => 'rp', 'value' => ')', 'pos' => $i + 1]; $i++; continue; }
            if ($c === ',') { $tokens[] = ['type' => 'comma', 'value' => ',', 'pos' => $i + 1]; $i++; continue; }

            throw new FormulaException("Unexpected character \"{$c}\" at position ".($i + 1).'.');
        }

        $tokens[] = ['type' => 'eof', 'value' => '', 'pos' => $n + 1];

        return $tokens;
    }

    /* ----------------------------- parser ------------------------------ */

    private function peek(): array
    {
        return $this->tokens[$this->p];
    }

    private function next(): array
    {
        return $this->tokens[$this->p++];
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();

        return $t['type'] === 'op' && in_array($t['value'], $ops, true);
    }

    private function unexpected(array $t): FormulaException
    {
        return new FormulaException($t['type'] === 'eof'
            ? 'The formula ends unexpectedly. Check for a missing value or closing bracket.'
            : "Unexpected \"{$t['value']}\" at position {$t['pos']}.");
    }

    private function orExpr(): array
    {
        $l = $this->andExpr();
        while ($this->isOp('OR')) {
            $this->next();
            $l = ['t' => 'logic', 'op' => 'OR', 'l' => $l, 'r' => $this->andExpr()];
        }

        return $l;
    }

    private function andExpr(): array
    {
        $l = $this->notExpr();
        while ($this->isOp('AND')) {
            $this->next();
            $l = ['t' => 'logic', 'op' => 'AND', 'l' => $l, 'r' => $this->notExpr()];
        }

        return $l;
    }

    private function notExpr(): array
    {
        if ($this->isOp('NOT')) {
            $this->next();

            return ['t' => 'not', 'x' => $this->notExpr()];
        }

        return $this->cmp();
    }

    private function cmp(): array
    {
        $l = $this->add();
        if ($this->isOp('==', '!=', '<', '<=', '>', '>=')) {
            $op = $this->next()['value'];

            return ['t' => 'bin', 'op' => $op, 'l' => $l, 'r' => $this->add()];
        }

        return $l;
    }

    private function add(): array
    {
        $l = $this->mul();
        while ($this->isOp('+', '-')) {
            $op = $this->next()['value'];
            $l = ['t' => 'bin', 'op' => $op, 'l' => $l, 'r' => $this->mul()];
        }

        return $l;
    }

    private function mul(): array
    {
        $l = $this->unary();
        while ($this->isOp('*', '/')) {
            $op = $this->next()['value'];
            $l = ['t' => 'bin', 'op' => $op, 'l' => $l, 'r' => $this->unary()];
        }

        return $l;
    }

    private function unary(): array
    {
        if ($this->isOp('-')) {
            $this->next();

            return ['t' => 'neg', 'x' => $this->unary()];
        }
        if ($this->isOp('+')) {
            $this->next();

            return $this->unary();
        }

        return $this->postfix();
    }

    private function postfix(): array
    {
        $node = $this->primary();
        while ($this->isOp('%')) {
            $this->next();
            $node = ['t' => 'pct', 'x' => $node];
        }

        return $node;
    }

    private function primary(): array
    {
        $t = $this->next();

        switch ($t['type']) {
            case 'num':
                return ['t' => 'num', 'v' => Decimal::make($t['value'])];
            case 'bool':
                return ['t' => 'bool', 'v' => $t['value'] === 'TRUE'];
            case 'id':
                if ($this->peek()['type'] === 'lp') {
                    return $this->call($t);
                }

                return ['t' => 'var', 'n' => $t['value'], 'p' => $t['pos']];
            case 'lp':
                $node = $this->orExpr();
                if ($this->peek()['type'] !== 'rp') {
                    throw $this->unexpected($this->peek());
                }
                $this->next();

                return $node;
        }

        throw $this->unexpected($t);
    }

    private function call(array $nameToken): array
    {
        $name = $nameToken['value'];
        if (! isset(self::FUNCTIONS[$name])) {
            throw new FormulaException("Unknown function {$name} at position {$nameToken['pos']}. Available: ".implode(', ', array_keys(self::FUNCTIONS)).'.');
        }
        $this->next(); // (
        $args = [];
        if ($this->peek()['type'] !== 'rp') {
            do {
                $args[] = $this->orExpr();
                $more = $this->peek()['type'] === 'comma';
                if ($more) {
                    $this->next();
                }
            } while ($more);
        }
        if ($this->peek()['type'] !== 'rp') {
            throw $this->unexpected($this->peek());
        }
        $this->next();

        [$min, $max] = self::FUNCTIONS[$name];
        if (count($args) < $min || ($max !== null && count($args) > $max)) {
            $expect = $max === null ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} to {$max}");
            throw new FormulaException("{$name} needs {$expect} value(s) but got ".count($args).'.');
        }

        return ['t' => 'call', 'f' => $name, 'a' => $args];
    }
}

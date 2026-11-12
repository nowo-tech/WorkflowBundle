<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Expression;

use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;

use function count;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;

/**
 * The transition condition language (since 1.15): a tiny, closed DSL, not Symfony ExpressionLanguage.
 *
 * ```
 * condition  := or
 * or         := and ("or" and)*
 * and        := unary ("and" unary)*
 * unary      := "not" unary | comparison
 * comparison := operand (("==" | "!=" | "<" | "<=" | ">" | ">=") operand)?
 * operand    := number | 'string' | true | false | null | variable | "(" condition ")"
 * ```
 *
 * Variables are lower-case identifiers from the host's whitelist ({@see WorkflowSubjectVariables}); there is no
 * function call, no `.` / `[` / `->` access, no arithmetic and no string escape, so a condition can only compare
 * whitelisted scalars with literals. Typing is strict: numbers compare with numbers, strings and booleans only with
 * `==` / `!=` of the same type, `null` with `==` / `!=` of anything; anything else (a missing variable, a type
 * mismatch, a non-boolean condition) is an error, and a guard that hits an error blocks. Size limits: 500 bytes,
 * 128 tokens, nesting depth 16.
 *
 * @internal
 */
final class GuardExpression
{
    public const MAX_LENGTH = 500;

    public const MAX_TOKENS = 128;

    public const MAX_DEPTH = 16;

    private const COMPARATORS = ['==', '!=', '<', '<=', '>', '>='];

    /** @var list<array{0: string, 1: bool|float|int|string|null}> token kind and value */
    private array $tokens;

    private int $position = 0;

    /**
     * @param list<array{0: string, 1: bool|float|int|string|null}> $tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * Parses a condition into its syntax tree.
     *
     * @throws GuardExpressionException on a syntax error or a size limit
     *
     * @return array<int, mixed>
     */
    public static function parse(string $expression): array
    {
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new GuardExpressionException(sprintf('The condition is longer than %d bytes.', self::MAX_LENGTH));
        }
        $parser = new self(self::tokenize($expression));
        $tree   = $parser->parseOr(0);
        if ($parser->position !== count($parser->tokens)) {
            throw new GuardExpressionException(sprintf('Unexpected "%s" in the condition.', (string) $parser->tokens[$parser->position][1]));
        }

        return $tree;
    }

    /**
     * The variable names a condition reads.
     *
     * @throws GuardExpressionException on a syntax error
     *
     * @return list<string>
     */
    public static function variables(string $expression): array
    {
        $names = [];
        foreach (self::tokenize($expression) as [$kind, $value]) {
            if ($kind === 'var' && is_string($value) && !in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Checks a condition against the whitelist of variable names (what the editor validates on save).
     *
     * @param list<string> $allowed
     *
     * @throws GuardExpressionException on a syntax error, a size limit or a variable outside the whitelist
     */
    public static function validate(string $expression, array $allowed): void
    {
        self::parse($expression);
        foreach (self::variables($expression) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new GuardExpressionException(sprintf('Unknown variable "%s" (allowed: %s).', $name, $allowed === [] ? 'none' : implode(', ', $allowed)));
            }
        }
    }

    /**
     * Evaluates a condition with the subject's variables.
     *
     * @throws GuardExpressionException on a syntax error, a missing variable or a type error
     */
    public static function evaluate(string $expression, WorkflowSubjectVariables $variables): bool
    {
        $result = self::value(self::parse($expression), $variables);
        if (!is_bool($result)) {
            throw new GuardExpressionException('The condition does not evaluate to true or false.');
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $node
     */
    private static function value(array $node, WorkflowSubjectVariables $variables): bool|float|int|string|null
    {
        switch ($node[0]) {
            case 'lit':
                /** @var bool|float|int|string|null $literal */
                $literal = $node[1];

                return $literal;
            case 'var':
                /** @var string $name */
                $name = $node[1];
                if (!$variables->has($name)) {
                    throw new GuardExpressionException(sprintf('The variable "%s" has no value for this subject.', $name));
                }

                return $variables->get($name);
            case 'not':
                /** @var array<int, mixed> $operand */
                $operand = $node[1];

                return !self::boolean($operand, $variables);
            case 'and':
                /** @var array<int, mixed> $left */
                $left = $node[1];
                /** @var array<int, mixed> $right */
                $right = $node[2];

                return self::boolean($left, $variables) && self::boolean($right, $variables);
            case 'or':
                /** @var array<int, mixed> $left */
                $left = $node[1];
                /** @var array<int, mixed> $right */
                $right = $node[2];

                return self::boolean($left, $variables) || self::boolean($right, $variables);
            default:
                /** @var string $operator */
                $operator = $node[1];
                /** @var array<int, mixed> $left */
                $left = $node[2];
                /** @var array<int, mixed> $right */
                $right = $node[3];

                return self::compare($operator, self::value($left, $variables), self::value($right, $variables));
        }
    }

    /**
     * @param array<int, mixed> $node
     */
    private static function boolean(array $node, WorkflowSubjectVariables $variables): bool
    {
        $value = self::value($node, $variables);
        if (!is_bool($value)) {
            throw new GuardExpressionException('"and", "or" and "not" need true or false on both sides.');
        }

        return $value;
    }

    private static function compare(string $operator, bool|float|int|string|null $left, bool|float|int|string|null $right): bool
    {
        $equality = $operator === '==' || $operator === '!=';
        if ($left === null || $right === null) {
            if (!$equality) {
                throw new GuardExpressionException('null can only be compared with == or !=.');
            }

            return ($left === $right) === ($operator === '==');
        }

        $numeric = (is_int($left) || is_float($left)) && (is_int($right) || is_float($right));
        if ($numeric) {
            return match ($operator) {
                '=='    => $left == $right,
                '!='    => $left != $right,
                '<'     => $left < $right,
                '<='    => $left <= $right,
                '>'     => $left > $right,
                default => $left >= $right,
            };
        }

        $sameType = (is_string($left) && is_string($right)) || (is_bool($left) && is_bool($right));
        if (!$sameType || !$equality) {
            throw new GuardExpressionException(sprintf('Cannot compare %s with %s using %s.', get_debug_type($left), get_debug_type($right), $operator));
        }

        return ($left === $right) === ($operator === '==');
    }

    /**
     * @return array<int, mixed>
     */
    private function parseOr(int $depth): array
    {
        $node = $this->parseAnd($depth);
        while ($this->accept('kw', 'or')) {
            $node = ['or', $node, $this->parseAnd($depth)];
        }

        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function parseAnd(int $depth): array
    {
        $node = $this->parseUnary($depth);
        while ($this->accept('kw', 'and')) {
            $node = ['and', $node, $this->parseUnary($depth)];
        }

        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function parseUnary(int $depth): array
    {
        if ($this->accept('kw', 'not')) {
            return ['not', $this->parseUnary($this->deeper($depth))];
        }

        $left = $this->parseOperand($depth);
        $next = $this->tokens[$this->position] ?? null;
        if ($next !== null && $next[0] === 'op' && in_array($next[1], self::COMPARATORS, true)) {
            // @igor-ignore - Cursor of a parser instance created per parse() call (never a shared service).
            ++$this->position;

            return ['cmp', (string) $next[1], $left, $this->parseOperand($depth)];
        }

        return $left;
    }

    /**
     * @return array<int, mixed>
     */
    private function parseOperand(int $depth): array
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token === null) {
            throw new GuardExpressionException('The condition ends too early.');
        }
        // @igor-ignore - Cursor of a parser instance created per parse() call (never a shared service).
        ++$this->position;

        return match (true) {
            $token[0] === 'lit'                     => ['lit', $token[1]],
            $token[0] === 'var'                     => ['var', $token[1]],
            $token[0] === 'op' && $token[1] === '(' => $this->group($depth),
            default                                 => throw new GuardExpressionException(sprintf('Unexpected "%s" in the condition.', (string) $token[1])),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function group(int $depth): array
    {
        $node = $this->parseOr($this->deeper($depth));
        if (!$this->accept('op', ')')) {
            throw new GuardExpressionException('Missing ")" in the condition.');
        }

        return $node;
    }

    private function deeper(int $depth): int
    {
        if ($depth + 1 > self::MAX_DEPTH) {
            throw new GuardExpressionException(sprintf('The condition is nested deeper than %d levels.', self::MAX_DEPTH));
        }

        return $depth + 1;
    }

    private function accept(string $kind, string $value): bool
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token !== null && $token[0] === $kind && $token[1] === $value) {
            // @igor-ignore - Cursor of a parser instance created per parse() call (never a shared service).
            ++$this->position;

            return true;
        }

        return false;
    }

    /**
     * @return list<array{0: string, 1: bool|float|int|string|null}>
     */
    private static function tokenize(string $expression): array
    {
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new GuardExpressionException(sprintf('The condition is longer than %d bytes.', self::MAX_LENGTH));
        }
        $tokens = [];
        $offset = 0;
        $length = strlen($expression);
        while ($offset < $length) {
            $rest = substr($expression, $offset);
            if (preg_match('/^[ \t\r\n]+/', $rest, $m) === 1) {
                $offset += strlen($m[0]);

                continue;
            }
            if (preg_match('/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,10})?(?![0-9A-Za-z_.])/', $rest, $m) === 1) {
                $tokens[] = ['lit', str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0]];
            } elseif (preg_match("/^'([^'\\\\\\x00-\\x1F]{0,255})'/", $rest, $m) === 1) {
                $tokens[] = ['lit', $m[1]];
            } elseif (preg_match('/^(?:==|!=|<=|>=|<|>|\(|\))/', $rest, $m) === 1) {
                $tokens[] = ['op', $m[0]];
            } elseif (preg_match('/^[a-z_][a-z0-9_]{0,63}(?![A-Za-z0-9_])/', $rest, $m) === 1) {
                $tokens[] = match ($m[0]) {
                    'true'             => ['lit', true],
                    'false'            => ['lit', false],
                    'null'             => ['lit', null],
                    'and', 'or', 'not' => ['kw', $m[0]],
                    default            => ['var', $m[0]],
                };
            } else {
                throw new GuardExpressionException(sprintf('Invalid character at position %d of the condition (only variables, numbers, \'strings\', true, false, null, comparisons, and, or, not and parentheses are allowed).', $offset + 1));
            }
            $offset += strlen($m[0]);
            if (count($tokens) > self::MAX_TOKENS) {
                throw new GuardExpressionException(sprintf('The condition has more than %d tokens.', self::MAX_TOKENS));
            }
        }
        if ($tokens === []) {
            throw new GuardExpressionException('The condition is empty.');
        }

        return $tokens;
    }
}

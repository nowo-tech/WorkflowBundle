<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Expression;

use Nowo\WorkflowBundle\Expression\GuardExpression;
use Nowo\WorkflowBundle\Expression\GuardExpressionException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The condition language is a sandbox: only whitelisted scalar variables compared with literals; no function, method,
 * property, index, arithmetic or escape; bounded size and depth; strict types; every error fails closed.
 */
final class GuardExpressionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function conditions(): iterable
    {
        yield 'number <=' => ['days <= 2', true];
        yield 'number >' => ['days > 2', false];
        yield 'number <' => ['days < 3', true];
        yield 'number >=' => ['days >= 2', true];
        yield 'number ==' => ['days == 2', true];
        yield 'number !=' => ['days != 2', false];
        yield 'float' => ['ratio >= 0.5', true];
        yield 'negative' => ['days > -1', true];
        yield 'string ==' => ["type == 'vacation'", true];
        yield 'string !=' => ["type != 'vacation'", false];
        yield 'bool' => ['urgent == false', true];
        yield 'bool variable' => ['not urgent', true];
        yield 'null ==' => ['manager == null', true];
        yield 'null !=' => ['days != null', true];
        yield 'and' => ["days <= 2 and type == 'vacation'", true];
        yield 'or' => ['days > 5 or urgent == false', true];
        yield 'not and parentheses' => ['not (days > 5 or urgent)', true];
        yield 'short-circuit and' => ['days > 5 and missing == 1', false];
        yield 'short-circuit or' => ['days < 5 or missing == 1', true];
        yield 'literal true' => ['true', true];
        yield 'whitespace' => ["  days\t<=\n2 ", true];
    }

    #[Test]
    #[DataProvider('conditions')]
    public function itEvaluatesComparisonsOfWhitelistedVariables(string $condition, bool $expected): void
    {
        self::assertSame($expected, GuardExpression::evaluate($condition, $this->variables()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapes(): iterable
    {
        yield 'method call' => ['subject.getDays() > 1'];
        yield 'property access' => ['subject.days > 1'];
        yield 'arrow access' => ['subject->days > 1'];
        yield 'function call' => ["constant('PHP_VERSION') == '8'"];
        yield 'function call without args' => ['system() == 1'];
        yield 'index access' => ['days[0] == 1'];
        yield 'arithmetic' => ['days + 1 > 2'];
        yield 'string concatenation' => ["type ~ 'x' == 'y'"];
        yield 'double quotes' => ['type == "vacation"'];
        yield 'escape in string' => ["type == 'a\\'b'"];
        yield 'upper-case identifier' => ['Days > 1'];
        yield 'class constant' => ['App::X == 1'];
        yield 'variable sigil' => ['$days > 1'];
        yield 'ternary' => ['days ? true : false'];
        yield 'assignment' => ['days = 1'];
        yield 'regex match' => ["type matches '/x/'"];
        yield 'array literal' => ['days in [1, 2]'];
        yield 'unterminated string' => ["type == 'vacation"];
        yield 'control character in string' => ["type == 'va\x01cation'"];
        yield 'number glued to name' => ['2days > 1'];
        yield 'leading zero' => ['days == 01'];
        yield 'missing operand' => ['days <='];
        yield 'dangling operator' => ['days <= 2 and'];
        yield 'unbalanced parenthesis' => ['(days <= 2'];
        yield 'extra parenthesis' => ['days <= 2)'];
        yield 'empty' => ['   '];
        yield 'too long' => ['days == 1 or ' . str_repeat('days == 1 or ', 50) . 'days == 1'];
        yield 'too many tokens' => [str_repeat('x<1 or ', 33) . 'x<1'];
        yield 'too deep' => [str_repeat('(', 17) . 'true' . str_repeat(')', 17)];
        yield 'too deep not' => [str_repeat('not ', 17) . 'true'];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function anythingOutsideTheLanguageIsRefused(string $condition): void
    {
        $this->expectException(GuardExpressionException::class);
        GuardExpression::evaluate($condition, $this->variables());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typeErrors(): iterable
    {
        yield 'number with string' => ["days == '2'"];
        yield 'string ordering' => ["type < 'z'"];
        yield 'bool ordering' => ['urgent < true'];
        yield 'bool with number' => ['urgent == 0'];
        yield 'null ordering' => ['manager < 1'];
        yield 'non-boolean condition' => ['days'];
        yield 'non-boolean literal' => ["'x'"];
        yield 'non-boolean and' => ['days and true'];
        yield 'non-boolean not' => ['not days'];
        yield 'missing variable' => ['missing == 1'];
    }

    #[Test]
    #[DataProvider('typeErrors')]
    public function typeErrorsAndMissingVariablesFailClosed(string $condition): void
    {
        $this->expectException(GuardExpressionException::class);
        GuardExpression::evaluate($condition, $this->variables());
    }

    #[Test]
    public function validationChecksTheWhitelist(): void
    {
        GuardExpression::validate('days <= 2 and days >= 0', ['days']);
        self::assertSame(['days', 'type'], GuardExpression::variables("days <= 2 and type == 'x' or days == 1"));

        try {
            GuardExpression::validate("type == 'x'", ['days']);
            self::fail('type is not whitelisted');
        } catch (GuardExpressionException $exception) {
            self::assertStringContainsString('"type"', $exception->getMessage());
        }

        $this->expectExceptionMessage('allowed: none');
        GuardExpression::validate('days == 1', []);
    }

    #[Test]
    public function aHugeExpressionIsRefusedBeforeTokenizing(): void
    {
        $this->expectException(GuardExpressionException::class);
        GuardExpression::variables(str_repeat('a', 501));
    }

    private function variables(): WorkflowSubjectVariables
    {
        return new WorkflowSubjectVariables(['days' => 2, 'ratio' => 0.5, 'type' => 'vacation', 'urgent' => false, 'manager' => null]);
    }
}

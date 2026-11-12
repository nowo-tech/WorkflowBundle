<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function implode;
use function in_array;
use function is_string;

/**
 * Constraints of the label translation fields of places and transitions (since 1.16, audit OD L2): a key of at most
 * 255 and a domain of at most 128 letters, digits, `_`, `.` or `-`; the domain one of `tenancy.label_domains`.
 *
 * @internal
 */
final class LabelTranslationConstraints
{
    public const PATTERN = '/^[A-Za-z0-9_.-]+$/D';

    /** Default of `tenancy.label_domains`. */
    public const DEFAULT_DOMAINS = ['messages', 'workflows'];

    /**
     * @return list<Constraint>
     */
    public static function key(): array
    {
        return [new Length(max: 255), new Regex(self::PATTERN, 'Use letters, digits, "_", "." or "-".')];
    }

    /**
     * @param list<string> $domains
     *
     * @return list<Constraint>
     */
    public static function domain(array $domains): array
    {
        return [
            new Length(max: 128),
            new Regex(self::PATTERN, 'Use letters, digits, "_", "." or "-".'),
            new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($domains): void {
                if (is_string($value) && $value !== '' && !in_array($value, $domains, true)) {
                    $context->addViolation('Use one of these translation domains: ' . implode(', ', $domains) . '.');
                }
            }),
        ];
    }
}

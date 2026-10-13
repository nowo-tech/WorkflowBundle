<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Util;

use Nowo\WorkflowBundle\Service\WorkflowApplicator;

use function class_exists;
use function interface_exists;
use function is_a;
use function is_object;
use function strlen;
use function strrpos;
use function substr;

/**
 * Subject class helpers used to match workflow definitions against runtime subjects.
 *
 * Doctrine proxies (legacy "__CG__" proxy classes) and subclasses of a configured
 * subject_class must resolve to the definition of their parent class, consistently
 * with {@see WorkflowApplicator} which uses is_a().
 *
 * @internal
 */
final class SubjectClass
{
    /** Doctrine\Persistence\Proxy::MARKER (kept literal: persistence proxies are optional on ORM 3 + PHP 8.4 lazy objects). */
    private const PROXY_MARKER = '__CG__';

    /**
     * Returns the real (non-proxy) class name for an object or class name.
     */
    public static function real(object|string $subject): string
    {
        $class  = is_object($subject) ? $subject::class : $subject;
        $marker = '\\' . self::PROXY_MARKER . '\\';
        $pos    = strrpos($class, $marker);

        if ($pos === false) {
            return $class;
        }

        return substr($class, $pos + strlen($marker));
    }

    /**
     * Real class first, then its parent classes, then its interfaces (closest first).
     *
     * Unknown classes (not autoloadable) yield only their own name.
     *
     * @return list<string>
     */
    public static function hierarchy(string $class): array
    {
        $real = self::real($class);
        if (!class_exists($real) && !interface_exists($real)) {
            return [$real];
        }

        $hierarchy = [$real];
        foreach (class_parents($real) as $parent) {
            $hierarchy[] = $parent;
        }
        foreach (class_implements($real) as $interface) {
            $hierarchy[] = $interface;
        }

        return array_values(array_unique($hierarchy));
    }

    /**
     * Whether $subjectClass (possibly a proxy or subclass) is supported by a definition targeting $definitionClass.
     */
    public static function matches(string $subjectClass, string $definitionClass): bool
    {
        return $subjectClass === $definitionClass
            || self::real($subjectClass) === $definitionClass
            || is_a($subjectClass, $definitionClass, true);
    }
}

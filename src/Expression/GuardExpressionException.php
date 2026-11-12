<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Expression;

use InvalidArgumentException;

/**
 * A transition condition that cannot be parsed or evaluated (since 1.15). A guard that hits it blocks (fail closed).
 */
final class GuardExpressionException extends InvalidArgumentException
{
}

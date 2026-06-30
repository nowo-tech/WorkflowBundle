<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Exception;

use function is_int;
use function is_string;

/**
 * Minimal {@see \Doctrine\DBAL\Exception} double for duplicate SQLSTATE/code coverage.
 */
final class TestDbalException extends Exception implements \Doctrine\DBAL\Exception
{
    public function __construct(int|string $code = 1050)
    {
        parent::__construct('duplicate object', is_int($code) ? $code : 0);
        if (is_string($code)) {
            $this->code = $code;
        }
    }
}

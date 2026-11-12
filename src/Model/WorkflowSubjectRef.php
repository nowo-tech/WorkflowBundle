<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * Opaque, stable identifier of a workflow subject in the host (since 1.15), e.g. the UUID of a leave request.
 *
 * Used to pin the definition version a subject started with, in permission checks and in the audit event. Never a
 * name, an e-mail or another personal value: the bundle stores it in `workflow_subject_pin`.
 */
final readonly class WorkflowSubjectRef
{
    public const MAX_LENGTH = 128;

    public string $id;

    /**
     * @throws InvalidArgumentException when the id is empty, longer than 128 bytes or holds a control character
     */
    public function __construct(string $id)
    {
        if (preg_match('/^[\x21-\x7E]{1,' . self::MAX_LENGTH . '}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('A workflow subject reference must be 1 to %d printable ASCII characters without spaces.', self::MAX_LENGTH));
        }
        $this->id = $id;
    }
}

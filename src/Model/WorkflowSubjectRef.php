<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strlen;

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
        if ($id === '' || strlen($id) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new InvalidArgumentException(sprintf('A workflow subject reference must be 1 to %d bytes without control characters.', self::MAX_LENGTH));
        }
        $this->id = $id;
    }
}

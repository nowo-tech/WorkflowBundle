<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function is_string;

/**
 * PSR-3 logger that keeps every record in memory (REQ-OBS-001 assertions).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string> "level: message" of every record
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $record): string => (is_string($record['level']) ? $record['level'] : 'unknown') . ': ' . $record['message'],
            $this->records,
        );
    }
}

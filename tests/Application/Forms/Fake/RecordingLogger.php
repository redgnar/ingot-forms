<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged, so a test can assert that something which must not pass
 * silently did not pass silently.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> level, message, context */
    public array $lines = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->lines[] = [$level, \is_string($message) ? $message : '', $context];
    }

    /**
     * @return list<string>
     */
    public function messagesAt(string $level): array
    {
        $messages = [];

        foreach ($this->lines as [$logged, $message]) {
            if ($logged === $level) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}

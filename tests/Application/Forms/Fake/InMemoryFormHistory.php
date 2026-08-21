<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\History\FormRevision;
use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\ValueObject\FormId;

/**
 * A form's history without a database — same guarantees, kept in an array, so a
 * use case can be tested for what it derives rather than for what SQL returns.
 */
final class InMemoryFormHistory implements FormHistory
{
    /** @var array<string, list<array{FormRevision, string}>> */
    private array $history = [];

    /** Appends a save, the way the repository does when it stores a draft. */
    public function append(FormId $form, string $document, ?\DateTimeImmutable $savedAt = null): void
    {
        $revisions = $this->history[(string) $form] ?? [];
        $revisions[] = [
            new FormRevision(\count($revisions) + 1, $savedAt ?? new \DateTimeImmutable()),
            $document,
        ];
        $this->history[(string) $form] = $revisions;
    }

    public function revisionsOf(FormId $form): array
    {
        return array_map(
            static fn(array $entry): FormRevision => $entry[0],
            $this->history[(string) $form] ?? [],
        );
    }

    public function documentOf(FormId $form, int $seq): ?string
    {
        return ($this->history[(string) $form][$seq - 1] ?? null)[1] ?? null;
    }
}

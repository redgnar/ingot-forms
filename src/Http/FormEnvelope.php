<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Persistence\Form;

/**
 * The canonical JSON shape of a form returned by every endpoint.
 */
final class FormEnvelope
{
    /**
     * @return array<string, mixed>
     */
    public function build(Form $record): array
    {
        $definition = json_decode($record->definition(), true, 512, \JSON_THROW_ON_ERROR);
        $title = \is_array($definition) && \is_string($definition['title'] ?? null) ? $definition['title'] : '';

        return [
            'id' => $record->id()->toRfc4122(),
            'title' => $title,
            'status' => $record->status()->value,
            'expireDate' => $record->expireDate()->format(\DateTimeInterface::ATOM),
            'createdAt' => $record->createdAt()->format(\DateTimeInterface::ATOM),
            'definition' => $definition,
            'data' => $record->data() === null ? null : json_decode($record->data(), true, 512, \JSON_THROW_ON_ERROR),
            'dataSavedAt' => $record->dataSavedAt()?->format(\DateTimeInterface::ATOM),
            'confirmedAt' => $record->confirmedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}

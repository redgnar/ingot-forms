<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use App\Domain\Forms\Form;

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
        return [
            'id' => (string) $record->id(),
            'status' => $record->status()->value,
            'expireDate' => (string) $record->expireDate(),
            'createdAt' => $record->createdAt()->format(\DateTimeInterface::ATOM),
            // The definition is served whole; nothing is lifted out of it into a
            // member of its own, or the same fact would live in two places.
            'definition' => json_decode($record->definition(), true, 512, \JSON_THROW_ON_ERROR),
            'data' => $record->valuesJson() === null ? null : json_decode($record->valuesJson(), true, 512, \JSON_THROW_ON_ERROR),
            'dataSavedAt' => $record->dataSavedAt()?->format(\DateTimeInterface::ATOM),
            'confirmedAt' => $record->confirmedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}

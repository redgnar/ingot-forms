<?php

declare(strict_types=1);

namespace App\UserInterface\Api;

use App\Domain\Forms\Form;

/**
 * The canonical JSON shape of a form returned by every endpoint.
 *
 * Built as **text**, and that is the whole point. Both documents a form holds
 * are stored as the exact JSON that passed validation and are handed back byte
 * for byte everywhere else; decoding them into PHP to put them in an array
 * would break exactly that, because a PHP array cannot tell an empty object
 * from an empty list. A form whose values are `{}` would leave here as `[]` —
 * so `GET /api/forms/{id}` and `GET /api/forms/{id}/data` would disagree about
 * the same document, which is the one thing this API may never do.
 *
 * So the documents are pasted in as they are stored, and only the members this
 * layer computes are encoded.
 */
final class FormEnvelope
{
    public function json(Form $record): string
    {
        return self::document([
            'id' => self::encoded((string) $record->id()),
            'status' => self::encoded($record->status()->value),
            'expireDate' => self::encoded((string) $record->expireDate()),
            'createdAt' => self::encoded($record->createdAt()->format(\DateTimeInterface::ATOM)),
            // The definition is served whole; nothing is lifted out of it into a
            // member of its own, or the same fact would live in two places.
            'definition' => (string) $record->definition(),
            'data' => $record->valuesJson() ?? 'null',
            'dataSavedAt' => self::encoded($record->dataSavedAt()?->format(\DateTimeInterface::ATOM)),
            'confirmedAt' => self::encoded($record->confirmedAt()?->format(\DateTimeInterface::ATOM)),
        ]);
    }

    /**
     * @param array<string, string> $members each already a JSON value
     */
    private static function document(array $members): string
    {
        $pairs = [];

        foreach ($members as $name => $value) {
            $pairs[] = self::encoded($name) . ':' . $value;
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private static function encoded(string|int|float|bool|null $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }
}

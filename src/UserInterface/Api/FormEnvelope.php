<?php

declare(strict_types=1);

namespace App\UserInterface\Api;

use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Actor;

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
            // How many saves this form has accepted, which is the number of its
            // newest one. Served so that a caller can hold it and hand it back
            // in `If-Match`, and so that "the newest revision" needs no second
            // request to the history to be named.
            'revision' => self::encoded($record->revision()),
            'confirmedAt' => self::encoded($record->confirmedAt()?->format(\DateTimeInterface::ATOM)),
            // When this form told whoever owns it that it had been confirmed.
            // Beside `confirmedAt` because that is what it is about, and on the
            // form rather than on a revision because confirming writes no values.
            'confirmNotifiedAt' => self::encoded($record->confirmNotifiedAt()?->format(\DateTimeInterface::ATOM)),
            // And when it told them it exists at all, which is the same kind of
            // fact about the same row.
            'createdNotifiedAt' => self::encoded($record->createdNotifiedAt()?->format(\DateTimeInterface::ATOM)),
            // Whether this form records who fills it in, and the two people it
            // knows by name. Who filled it in is per save and is read from the
            // management side's history instead: "who last changed this form" is
            // the newest revision, and a second copy of it here would be a
            // second truth. Opaque strings, never resolved into anybody, and
            // served only here — no page draws them.
            'identity' => self::encoded($record->identityMode()->value),
            'author' => self::encoded(self::subject($record->author())),
            'confirmedBy' => self::encoded(self::subject($record->confirmedBy())),
            // Where this form reports itself. Served because the system that
            // configured it is the only audience this envelope has, and a
            // deployment reading its own form should be able to see what it asked
            // for. Nothing secret is in here — a notification is signed with the
            // deployment's secret, which is never part of a form.
            'webhooks' => self::document([
                'created' => self::encoded($record->webhooks()->created),
                'save' => self::encoded($record->webhooks()->save),
                'confirm' => self::encoded($record->webhooks()->confirm),
                'deleted' => self::encoded($record->webhooks()->deleted),
            ]),
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

    private static function subject(?Actor $actor): ?string
    {
        return $actor === null ? null : (string) $actor;
    }

    private static function encoded(string|int|float|bool|null $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * The two documents this model accepts, and the meta-schema each is judged by.
 *
 * The schemas are code rather than storage — they sit beside the classes they
 * describe and change with them — so this says where they are, once. Both the
 * mapper that enforces them ({@see FormMapperFactory}) and the endpoint that
 * publishes them read from here, which is what keeps "the authoritative
 * contract" from meaning two different files.
 *
 * Publishing them is not decoration. Both documents are written by a client,
 * and their rules are deliberately not duplicated into the OpenAPI document —
 * so a contract that only names a path inside this repository is a contract its
 * own readers cannot reach.
 */
enum MetaSchema: string
{
    case Definition = 'definition';
    case Presentation = 'presentation';

    /** The wire values, which is also what the published contract lists. */
    public const array NAMES = ['definition', 'presentation'];

    /** The schema itself, as the JSON text it is stored as. */
    public function document(): string
    {
        $document = file_get_contents($this->file());

        if ($document === false) {
            throw new \RuntimeException(\sprintf('The %s meta-schema cannot be read.', $this->value));
        }

        return $document;
    }

    public function file(): string
    {
        return match ($this) {
            self::Definition => __DIR__ . '/form-definition.schema.json',
            self::Presentation => __DIR__ . '/Presentation/presentation.schema.json',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Schema\Schema;

/**
 * The values schema of a form: one document, wanted in two shapes by two
 * callers, which is why this port has two methods rather than one.
 *
 * The endpoint that publishes it has an id and nothing else, so it asks for the
 * document and pays for the read that finds the definition. The gate that judges
 * a save is already holding that definition — it is being asked under the form's
 * row lock — so making it read the form again to get back what it has would be
 * the one avoidable cost on the hottest path there is.
 *
 * Definitions are immutable, so an implementation is free to cache a derived
 * document for as long as the rules that derive it hold — no longer.
 */
interface DataSchemas
{
    /**
     * The schema as the JSON document a client is served.
     *
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function json(FormId $formId, DeriveMode $mode): string;

    /** The same schema, ready to validate against, for a caller that already holds the definition. */
    public function schemaFor(FormId $formId, FormDefinition $definition, DeriveMode $mode): Schema;
}

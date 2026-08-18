<?php

declare(strict_types=1);

namespace App\Http\Form;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Infrastructure\Cache\CachedDataSchemaProvider;
use Ingot\Error\ErrorReport;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\SchemaValidator;
use Symfony\Component\Uid\Uuid;

/**
 * The first gate submitted values pass: the JSON Schema derived from the
 * definition — the very document clients are handed by
 * `GET /api/forms/{id}/schema`.
 *
 * It runs before {@see FormValuesType} is built, for two reasons. It is an
 * order of magnitude cheaper (a cached schema against a small document costs
 * tens of microseconds; assembling the form and running its constraints costs
 * hundreds), so a payload that was never going to fit is refused without that
 * work. And it settles the question of whether the server can be looser than
 * its own published contract: it cannot, because that contract answers first.
 *
 * Whatever the schema cannot express — anything the form adds — is checked
 * afterwards by {@see FormValuesValidator}.
 */
final class SchemaValuesValidator
{
    public function __construct(
        private readonly CachedDataSchemaProvider $schemas,
        private readonly DataSchemaDeriver $deriver = new DataSchemaDeriver(),
        private readonly SchemaValidator $schemaValidator = new OpisSchemaValidator(),
    ) {}

    /**
     * @param ?Uuid $formId the form the values belong to; when it is known the
     *                      schema comes from the cache the schema endpoint fills
     */
    public function validate(FormDefinition $definition, \stdClass $values, DeriveMode $mode, ?Uuid $formId = null): ErrorReport
    {
        $schema = $formId === null
            ? $this->deriver->derive($definition, $mode)
            : $this->schemas->schemaFor($formId, $definition, $mode);

        return $this->schemaValidator->validate($values, $schema);
    }
}

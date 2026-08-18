<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\SchemaValidator;

/**
 * Validates form data against the schema derived from the definition.
 *
 * Draft saves use the relaxed schema (partial progress is storable);
 * confirmation uses the strict schema and additionally refuses definitions
 * containing unknown (plugin) field types — the server cannot vouch for a
 * value contract it does not know.
 */
final class FormDataValidator
{
    public function __construct(
        private readonly DataSchemaDeriver $deriver = new DataSchemaDeriver(),
        private readonly SchemaValidator $schemaValidator = new OpisSchemaValidator(),
    ) {}

    /**
     * Values are passed already decoded, the way json_decode() produces them
     * (objects as \stdClass) — the caller owns the wire format.
     *
     * @throws FormDataNotValid
     */
    public function validateDraft(FormDefinition $definition, \stdClass $values): void
    {
        $this->validate($definition, $values, DeriveMode::Draft);
    }

    /**
     * @throws FormDataNotValid
     */
    public function validateFinal(FormDefinition $definition, \stdClass $values): void
    {
        $unknown = [];

        foreach ($definition->fields as $index => $field) {
            if ($field instanceof GenericField) {
                $unknown[] = new MappingError(
                    JsonPointer::fromString(\sprintf('/fields/%d/type', $index)),
                    'form.data.unknown-field-type',
                    \sprintf('Field "%s" has unknown type "%s" — its value contract cannot be confirmed.', $field->name, $field->type),
                    $field->type,
                );
            }
        }

        if ($unknown !== []) {
            throw new FormDataNotValid(ErrorReport::of(...$unknown));
        }

        $this->validate($definition, $values, DeriveMode::Strict);
    }

    /**
     * @throws FormDataNotValid
     */
    private function validate(FormDefinition $definition, \stdClass $values, DeriveMode $mode): void
    {
        $report = $this->schemaValidator->validate($values, $this->deriver->derive($definition, $mode));

        if (!$report->isEmpty()) {
            throw new FormDataNotValid($report);
        }
    }
}

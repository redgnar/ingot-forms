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
use Ingot\Source;

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
     * @throws FormDataNotValid
     */
    public function validateDraft(FormDefinition $definition, string $json): void
    {
        $this->validate($definition, $json, DeriveMode::Draft);
    }

    /**
     * @throws FormDataNotValid
     */
    public function validateFinal(FormDefinition $definition, string $json): void
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

        $this->validate($definition, $json, DeriveMode::Strict);
    }

    /**
     * @throws FormDataNotValid
     */
    private function validate(FormDefinition $definition, string $json, DeriveMode $mode): void
    {
        try {
            $decoded = Source::json($json)->data();
        } catch (\JsonException $exception) {
            throw new FormDataNotValid(ErrorReport::of(
                new MappingError(JsonPointer::root(), 'source.malformed_json', $exception->getMessage()),
            ));
        }

        $report = $this->schemaValidator->validate($decoded, $this->deriver->derive($definition, $mode));

        if (!$report->isEmpty()) {
            throw new FormDataNotValid($report);
        }
    }
}

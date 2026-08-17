<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use Ingot\Schema\Schema;

/**
 * Derives the JSON Schema of a form's *values* from its definition — the
 * definition is the source of truth, the data schema is a generated artifact.
 * The same schema can validate values in PHP and in a future frontend (Ajv),
 * and documents stored data.
 */
final class DataSchemaDeriver
{
    public function derive(FormDefinition $definition, DeriveMode $mode = DeriveMode::Strict): Schema
    {
        $properties = [];
        $required = [];

        foreach ($definition->fields as $field) {
            $properties[$field->name] = $this->fieldSchema($field, $mode);

            if ($field->required) {
                $required[] = $field->name;
            }
        }

        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => \sprintf('Values of form "%s"', $definition->id),
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== [] && $mode === DeriveMode::Strict) {
            $document['required'] = $required;
        }

        return Schema::fromJson(json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function fieldSchema(Field $field, DeriveMode $mode): array|\stdClass
    {
        if ($field instanceof TextField) {
            $schema = ['type' => 'string'];

            if ($field->required && $mode === DeriveMode::Strict) {
                $schema['minLength'] = 1; // required means non-empty, not merely present
            }

            if ($field->maxLength !== null) {
                $schema['maxLength'] = $field->maxLength;
            }

            if ($field->pattern !== null) {
                $schema['pattern'] = $field->pattern;
            }

            return $schema;
        }

        if ($field instanceof SelectField) {
            return ['enum' => $field->options];
        }

        if ($field instanceof NumberField) {
            $schema = ['type' => 'number'];

            if ($field->min !== null) {
                $schema['minimum'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['maximum'] = $field->max;
            }

            return $schema;
        }

        // Unknown (plugin) field types accept anything at draft time; the
        // confirm path rejects definitions containing them (see FormDataValidator).
        return new \stdClass();
    }
}

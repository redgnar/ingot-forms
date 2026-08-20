<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
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
        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            // Which form's values these are is the endpoint's business, not the
            // document's: it is served per form and cached per form.
            'title' => \sprintf('Form values (%s contract)', $mode->value),
            ...$this->objectSchema($definition->items, $mode),
        ];

        return Schema::fromJson(json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * One set of items as the object that answers them. The whole values
     * document is one of these, and so is a single entry of a collection — the
     * same code, because a row is a form's worth of answers and nothing else.
     *
     * @param list<Field> $items
     *
     * @return array<string, mixed>
     */
    private function objectSchema(array $items, DeriveMode $mode): array
    {
        $properties = [];
        $required = [];

        foreach ($items as $field) {
            $properties[$field->name] = $this->fieldSchema($field, $mode);

            if (self::mustBeAnswered($field)) {
                $required[] = $field->name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== [] && $mode === DeriveMode::Strict) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * A collection asks for entries rather than for a member, and a member that
     * is not there has none of them — so a minimum is what makes it required.
     * Every other kind of item says so itself.
     */
    private static function mustBeAnswered(Field $field): bool
    {
        return $field instanceof CollectionField
            ? $field->min !== null && $field->min > 0
            : $field->required;
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

        if ($field instanceof CheckboxField) {
            // Having to tick a box is an obligation to finish, not a rule about
            // what a value may be — the same category as `required`. So it holds
            // at confirmation and not while somebody is still filling the form
            // in: a draft that refuses "not yet agreed" is a draft nobody can
            // save.
            return $field->mustBeChecked && $mode === DeriveMode::Strict
                ? ['type' => 'boolean', 'const' => true]
                : ['type' => 'boolean'];
        }

        if ($field instanceof DateField) {
            // A day, as text: `format` is what makes it a date rather than any
            // ten characters, and the two bounds are how a period is said —
            // standard JSON Schema has no way to bound a string in time.
            $schema = ['type' => 'string', 'format' => 'date'];

            if ($field->min !== null) {
                $schema['formatMinimum'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['formatMaximum'] = $field->max;
            }

            return $schema;
        }

        if ($field instanceof SelectField) {
            return ['enum' => $field->options];
        }

        if ($field instanceof NumberField) {
            // A whole number is its own JSON type; anything finer is said as
            // the step the value must land on.
            $schema = $field->decimals === 0 ? ['type' => 'integer'] : ['type' => 'number'];

            if ($field->decimals !== null && $field->decimals > 0) {
                $schema['multipleOf'] = 10 ** -$field->decimals;
            }

            if ($field->min !== null) {
                $schema['minimum'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['maximum'] = $field->max;
            }

            return $schema;
        }

        if ($field instanceof CollectionField) {
            $schema = ['type' => 'array'];

            // A maximum is a rule about the value, so it holds while somebody is
            // still filling the form in; a minimum is an obligation to finish,
            // like `required` itself, so it waits for confirmation — otherwise
            // "save for later" would refuse the empty list it exists for.
            if ($field->min !== null && $mode === DeriveMode::Strict) {
                $schema['minItems'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['maxItems'] = $field->max;
            }

            $schema['items'] = $this->objectSchema($field->items, $mode);

            return $schema;
        }

        // Unknown (plugin) field types accept anything at draft time; the
        // confirm path rejects definitions containing them (see UnknownFieldTypes).
        return new \stdClass();
    }
}

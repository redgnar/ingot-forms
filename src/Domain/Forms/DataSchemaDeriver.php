<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\DateTimeField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
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

        if ($field instanceof DateTimeField) {
            // `format` says what this is; the pattern says the part of RFC 3339
            // that `format` does not enforce. Every implementation reads
            // `date-time` a little differently and the common ones admit a
            // string with no offset — which is not a moment, only a reading on
            // a wall, and two readers would mean two different instants by it.
            // Stated here so the published contract refuses it as well.
            $schema = [
                'type' => 'string',
                'format' => 'date-time',
                'pattern' => '^\\d{4}-\\d{2}-\\d{2}[Tt]\\d{2}:\\d{2}:\\d{2}(\\.\\d+)?([Zz]|[+-]\\d{2}:\\d{2})$',
            ];

            if ($field->min !== null) {
                $schema['formatMinimum'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['formatMaximum'] = $field->max;
            }

            return $schema;
        }

        if ($field instanceof FileField) {
            // The description of a file, not the bytes — so the item's own two
            // rules are said here, in the published contract, and hold wherever
            // that contract is checked. All four members are required in both
            // contracts, which is not the same thing as the *item* being
            // required: nobody types a description, it arrives whole from one
            // response, so half of one is a client mistake rather than an
            // answer somebody has not got round to.
            return [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'name' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 255,
                        // A name is a label: no separators, no control characters.
                        'pattern' => '^[^/\\\\\x00-\x1f]+$',
                    ],
                    'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $field->maxSize],
                    'type' => ['enum' => $field->accept],
                ],
                'required' => ['id', 'name', 'size', 'type'],
                'additionalProperties' => false,
            ];
        }

        if ($field instanceof SelectField) {
            return ['enum' => $field->options];
        }

        if ($field instanceof NumberField) {
            // A whole number is its own JSON type. Anything finer is *said* here
            // and not stated as a rule, which is the one place this contract
            // deliberately stops short — see the note below.
            $schema = $field->decimals === 0 ? ['type' => 'integer'] : ['type' => 'number'];

            if ($field->decimals !== null && $field->decimals > 0) {
                // `multipleOf: 0.01` is the obvious spelling and it is unusable:
                // the keyword is defined as division yielding an integer, and in
                // binary floating point 1.15 / 0.01 is 114.99999999999999. Ajv
                // (division === Math.floor(division)) and Python's jsonschema
                // (instance % divisor == 0) both refuse 1.15, 0.07 and 0.29 —
                // ordinary money — so publishing it would hand every client a
                // rule that rejects correct answers. Opis, which validates here,
                // rounds to a tolerance and accepts them, which is worse still: a
                // contract meaning two different things on the two sides of it.
                //
                // So the precision is described rather than asserted, and a gate
                // of our own enforces it exactly, in decimal.
                $schema['description'] = \sprintf(
                    'At most %d decimal place%s. Not stated as a rule: JSON Schema can only say this as multipleOf, which no validator computes in decimal.',
                    $field->decimals,
                    $field->decimals === 1 ? '' : 's',
                );
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DeriveMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Symfony form built from one form's own definition — this is what actually
 * validates submitted values.
 *
 * The engine's derived JSON Schema stays the *published* contract (clients
 * validate against it before sending), while a form gives the server room for
 * the rules a schema cannot express as the field catalogue grows: dependent
 * fields, transformers, per-type options.
 *
 * Strict mode is the confirmation contract (required fields must be filled);
 * draft mode relaxes exactly that, so partial progress can be stored.
 */
final class FormValuesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $definition = $options['definition'];
        \assert($definition instanceof FormDefinition);
        $strict = $options['mode'] === DeriveMode::Strict;

        foreach ($definition->items as $field) {
            [$type, $fieldOptions] = match (true) {
                $field instanceof TextField => [TextType::class, self::textOptions($field, $strict)],
                $field instanceof SelectField => [ChoiceType::class, self::selectOptions($field, $strict)],
                $field instanceof NumberField => [NumberType::class, self::numberOptions($field, $strict)],
                // What a date is and which period it falls in is said in the
                // published schema, and enforced there; here it is the text it
                // travels as, so this stage cannot end up stricter.
                $field instanceof DateField => [TextType::class, self::dateOptions($field, $strict)],
                // Whether a box may be left undecided, and whether it has to be
                // ticked, are both said in the published schema and enforced
                // there; this stage only takes the boolean as it came.
                $field instanceof CheckboxField => [CheckboxType::class, ['required' => false, 'false_values' => [null]]],
                // A field type this application does not know: its value is
                // stored as it came. Confirmation refuses such a form outright,
                // so only drafts ever reach this branch.
                default => [RawValueType::class, ['required' => false]],
            };

            $builder->add($field->name, $type, $fieldOptions);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['definition'])
            ->setAllowedTypes('definition', FormDefinition::class)
            ->setDefault('mode', DeriveMode::Draft)
            ->setAllowedTypes('mode', DeriveMode::class)
            // Values are keyed by field name; anything else is a client error,
            // which is also what the published schema says.
            ->setDefault('allow_extra_fields', false)
            ->setDefault('extra_fields_message', 'This form does not declare a field named "{{ extra_fields }}".')
            ->setDefault('data_class', null)
            ->setDefault('error_bubbling', false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function textOptions(TextField $field, bool $strict): array
    {
        $constraints = [];

        if ($strict && $field->required) {
            $constraints[] = new Assert\NotBlank(message: 'This field is required.', payload: ['code' => 'form.value.required']);
        }

        // The definition guarantees a positive limit; Length insists on knowing.
        if ($field->maxLength !== null && $field->maxLength > 0) {
            $constraints[] = new Assert\Length(max: $field->maxLength, maxMessage: 'This value is longer than {{ limit }} characters.');
        }

        if ($field->pattern !== null) {
            $constraints[] = new Assert\Regex(pattern: '{' . $field->pattern . '}', message: 'This value does not match the expected pattern.');
        }

        return ['required' => false, 'constraints' => $constraints];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dateOptions(DateField $field, bool $strict): array
    {
        $constraints = [];

        if ($strict && $field->required) {
            $constraints[] = new Assert\NotBlank(message: 'This field is required.', payload: ['code' => 'form.value.required']);
        }

        return ['required' => false, 'constraints' => $constraints];
    }

    /**
     * @return array<string, mixed>
     */
    private static function selectOptions(SelectField $field, bool $strict): array
    {
        $constraints = [];

        if ($strict && $field->required) {
            $constraints[] = new Assert\NotBlank(message: 'This field is required.', payload: ['code' => 'form.value.required']);
        }

        return [
            'required' => false,
            'choices' => array_combine($field->options, $field->options),
            'placeholder' => null,
            'constraints' => $constraints,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function numberOptions(NumberField $field, bool $strict): array
    {
        $constraints = [];

        if ($strict && $field->required) {
            $constraints[] = new Assert\NotNull(message: 'This field is required.', payload: ['code' => 'form.value.required']);
        }

        // Range words itself differently depending on which bounds are set,
        // and refuses messages for a bound it does not have.
        if ($field->min !== null && $field->max !== null) {
            $constraints[] = new Assert\Range(
                notInRangeMessage: 'This value must be between {{ min }} and {{ max }}.',
                min: $field->min,
                max: $field->max,
            );
        } elseif ($field->min !== null) {
            $constraints[] = new Assert\Range(min: $field->min, minMessage: 'This value must be {{ limit }} or more.');
        } elseif ($field->max !== null) {
            $constraints[] = new Assert\Range(max: $field->max, maxMessage: 'This value must be {{ limit }} or less.');
        }

        return ['required' => false, 'html5' => false, 'scale' => $field->decimals, 'constraints' => $constraints];
    }
}

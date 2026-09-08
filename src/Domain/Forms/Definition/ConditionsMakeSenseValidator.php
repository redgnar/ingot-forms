<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Whether a condition could ever hold — asked of the scope it was written in.
 *
 * A condition's shape is the condition's own business
 * ({@see ConditionShapeValidator}); everything here needs the *siblings* to
 * answer, so it is registered for both kinds of scope, exactly as
 * {@see UniqueFieldNamesValidator} is. A condition names items **declared
 * together with it**: a collection's entry asks about its own answers, and
 * reaching out of one would be asking about a document this one is not.
 *
 * Every refusal here is a mistake somebody will make, and each is silent
 * without it. `not-comparable` earns its place twice over: `is: "yes"` on a
 * checkbox and `is: "ES"` on a select that offers no `ES` are typos that would
 * otherwise hide a question for the life of the form, with nothing anywhere
 * saying so.
 *
 * @implements ObjectValidator<FormDefinition|CollectionField>
 */
final class ConditionsMakeSenseValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $declared = [];

        foreach ($object->items as $field) {
            $declared[$field->name] = $field;
        }

        /** @var array<string, list<string>> $mentions filled in as conditions are walked */
        $mentions = [];

        foreach ($object->items as $index => $field) {
            if ($field->required && $field->requiredWhen !== null) {
                $context->addError(
                    \sprintf('/items/%d/requiredWhen', $index),
                    'form.field.required-and-conditional',
                    'An item is required, or required under a condition — never both.',
                    true,
                );
            }

            foreach (['askedWhen' => $field->askedWhen, 'requiredWhen' => $field->requiredWhen] as $member => $condition) {
                if ($condition === null) {
                    continue;
                }

                $this->walk(
                    $condition,
                    \sprintf('/items/%d/%s', $index, $member),
                    $field,
                    $declared,
                    $context,
                    1,
                    $mentions,
                );
            }
        }

        $this->refuseCycles($object, $mentions, $context);
    }

    /**
     * One condition, one complaint — and the graph of what waits on what, built
     * here because this is where an item has been resolved. Nothing has to be
     * filtered out of it afterwards: a name that is the owner's own, or nobody's,
     * has already been answered above and never reaches the graph.
     *
     * @param array<string, Field>        $declared
     * @param array<string, list<string>> $mentions
     */
    private function walk(
        Condition $condition,
        string $path,
        Field $owner,
        array $declared,
        ValidationContext $context,
        int $depth,
        array &$mentions,
    ): void {
        $combinator = $condition->combinator();
        $item = $condition->item;

        match (true) {
            $combinator !== null => $this->walkChildren($condition, $combinator, $path, $owner, $declared, $context, $depth, $mentions),
            // Its own shape is somebody else's complaint, already made.
            $item === null => null,
            $item === $owner->name => $context->addError(
                $path . '/item',
                'form.condition.self-reference',
                \sprintf('Item "%s" cannot be asked for on the strength of its own answer.', $owner->name),
                $item,
            ),
            !isset($declared[$item]) => $context->addError(
                $path . '/item',
                'form.condition.unknown-item',
                \sprintf('No item called "%s" is declared beside this one.', $item),
                $item,
            ),
            default => $this->about($condition, $declared[$item], $owner, $path, $context, $mentions),
        };
    }

    /**
     * @param array<string, Field>        $declared
     * @param array<string, list<string>> $mentions
     */
    private function walkChildren(
        Condition $condition,
        string $combinator,
        string $path,
        Field $owner,
        array $declared,
        ValidationContext $context,
        int $depth,
        array &$mentions,
    ): void {
        if ($depth > Condition::MAX_DEPTH) {
            // Refused here and not walked into: what is inside a condition
            // nobody may write is nobody's business, and a second complaint from
            // in there would only bury the first.
            $context->addError(
                $path,
                'form.condition.too-deep',
                \sprintf('Conditions may be combined %d deep, and this is deeper.', Condition::MAX_DEPTH),
                $combinator,
            );

            return;
        }

        foreach ($condition->children() as $child => $nested) {
            $this->walk($nested, \sprintf('%s/%s/%d', $path, $combinator, $child), $owner, $declared, $context, $depth + 1, $mentions);
        }
    }

    /**
     * @param array<string, list<string>> $mentions
     */
    private function about(
        Condition $condition,
        Field $about,
        Field $owner,
        string $path,
        ValidationContext $context,
        array &$mentions,
    ): void {
        $mentions[$owner->name] = [...$mentions[$owner->name] ?? [], $about->name];

        // A number worked out from other answers cannot decide which questions
        // are asked, and the reason is a loop rather than a taste: hiding an
        // answer takes it out of the document, which changes the number, which
        // changes the question, which shows the answer again. A page evaluating
        // that would flap, and the two mechanisms are ordered exactly so that it
        // cannot — conditions from what somebody typed, totals afterwards.
        if ($about instanceof NumberField && $about->calculated !== null) {
            $context->addError(
                $path . '/item',
                'form.condition.on-a-calculated-number',
                \sprintf('Item "%s" is worked out from other answers, so it cannot decide which questions are asked.', $about->name),
                $about->name,
            );
        } else {
            $this->refuseWhatCannotBeCompared($condition, $about, $path, $context);
        }
    }

    private function refuseWhatCannotBeCompared(
        Condition $condition,
        Field $about,
        string $path,
        ValidationContext $context,
    ): void {
        $comparison = $condition->comparison();

        if ($comparison === null) {
            // Nothing to compare: whether an answer is there at all can be
            // asked of anything ({@see Condition::comparison()}).
            return;
        }

        [$predicate, $literals] = $comparison;

        foreach ($literals as $literal) {
            $wrong = self::whatIsWrongWith($about, $literal);

            if ($wrong === null) {
                continue;
            }

            $context->addError(
                \sprintf('%s/%s', $path, $predicate),
                'form.condition.not-comparable',
                \sprintf('Item "%s" %s.', $about->name, $wrong),
                $literal,
            );

            return;
        }
    }

    /**
     * What is wrong with comparing this item to that literal, or null when
     * nothing is.
     *
     * Deliberately per kind of item rather than "is it a scalar": the whole
     * value of this check is that it knows a checkbox holds `true` and a select
     * holds one of the words it offers.
     */
    private static function whatIsWrongWith(Field $about, mixed $literal): ?string
    {
        return match (true) {
            $about instanceof CheckboxField => \is_bool($literal)
                ? null
                : 'is a checkbox, so it can only be compared to true or false',
            $about instanceof SelectField => \in_array($literal, $about->options, true)
                ? null
                : \sprintf('offers no option %s', json_encode($literal, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)),
            // Asked as one expression rather than two, because `is_int($l) ||
            // is_float($l)` and `is_int($l) | is_float($l)` are the same answer
            // and nothing could tell them apart.
            $about instanceof NumberField => \in_array(get_debug_type($literal), ['int', 'float'], true)
                ? null
                : 'holds a number, so it can only be compared to one',
            $about instanceof FileField => 'holds the description of a file, so only "answered" can be asked of it',
            // A multiple choice answers with a *list*, so comparing it to one of
            // its options is a condition that could never hold — `{"tags":
            // ["urgent"]}` is not `"urgent"`. Asking whether it was answered at
            // all is the only question this vocabulary can put to it.
            $about instanceof MultiSelectField => 'holds several answers, so only "answered" can be asked of it',
            $about instanceof CollectionField => 'is a list, so only "answered" can be asked of it',
            $about instanceof GenericField => null,
            default => \is_string($literal) ? null : 'holds text, so it can only be compared to text',
        };
    }

    /**
     * @param FormDefinition|CollectionField $object
     * @param array<string, list<string>>    $mentions
     */
    private function refuseCycles(FormDefinition|CollectionField $object, array $mentions, ValidationContext $context): void
    {
        foreach ($object->items as $index => $field) {
            if (!self::reaches($field->name, $field->name, $mentions, [])) {
                continue;
            }

            $context->addError(
                \sprintf('/items/%d', $index),
                'form.condition.cycle',
                \sprintf('Item "%s" is asked for on the strength of an answer that waits for it.', $field->name),
                $field->name,
            );

            return;
        }
    }

    /**
     * Whether following what these conditions ask about gets back to where it
     * started. A cycle is not merely nonsense on paper: a page evaluating it
     * would never settle, so it cannot be stored.
     *
     * @param array<string, list<string>> $mentions
     * @param list<string>                $walked
     */
    private static function reaches(string $from, string $target, array $mentions, array $walked): bool
    {
        foreach ($mentions[$from] ?? [] as $next) {
            if ($next === $target) {
                return true;
            }

            if (\in_array($next, $walked, true)) {
                continue;
            }

            if (self::reaches($next, $target, $mentions, [...$walked, $next])) {
                return true;
            }
        }

        return false;
    }

}

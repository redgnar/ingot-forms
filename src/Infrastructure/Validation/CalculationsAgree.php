<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Domain\Forms\Definition\Calculation;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * A number that says what it is worked out from has to be worked out from it —
 * the third gate in this codebase that is stricter than the published contract,
 * and the third one that says so out loud.
 *
 * The reason is the same as the other two: no JSON Schema can say "this member
 * equals the sum of that member across that list", any more than it can say
 * "this file id exists". So {@see \App\Domain\Forms\DataSchemaDeriver} describes
 * the calculation and this asks the question.
 *
 * **The client works the number out and sends it**, which is a decision rather
 * than a convenience ({@see .claude/plan/21-calculated-values.md}): a server
 * that filled the member in would make the stored document something the client
 * never sent, and this service hands back the exact text that passed validation.
 * The page is a client and does the same arithmetic after every keystroke, so a
 * person never meets this refusal — whoever does is a client that added up
 * differently, which is exactly what needs telling.
 *
 * Two readings are worth stating, because a draft is judged too:
 *
 * - **an answer nobody has given counts as nothing.** A half-filled list has
 *   entries with no amount in them, and a total of "what is there so far" is the
 *   number a page shows and the number this expects. The alternative — refusing
 *   to judge until every entry is complete — would make a wrong total storable
 *   for as long as anything was missing.
 * - **the comparison is decimal**, rounded to the places the item declares
 *   (which is why a calculated number must declare them). Comparing sums of
 *   binary floats exactly is a coin toss, and this is a question a person can
 *   check by hand.
 */
final class CalculationsAgree
{
    public function validate(FormDefinition $definition, \stdClass $values): ErrorReport
    {
        $errors = [];
        self::walk($definition->items, $values, JsonPointer::root(), $errors);

        return ErrorReport::of(...$errors);
    }

    /**
     * @param list<Field>        $items
     * @param list<MappingError> $errors
     */
    private static function walk(array $items, mixed $document, JsonPointer $at, array &$errors): void
    {
        if (!$document instanceof \stdClass) {
            return;
        }

        foreach ($items as $item) {
            $answer = $document->{$item->name} ?? null;

            if ($item instanceof CollectionField) {
                foreach (\is_array($answer) ? array_values($answer) : [] as $index => $entry) {
                    self::walk($item->items, $entry, $at->append($item->name)->append($index), $errors);
                }

                continue;
            }

            // Nothing to check on an answer nobody gave: whether it is owed at
            // all is the schema's question, asked before this gate ran.
            if (!$item instanceof NumberField || $item->calculated === null || $answer === null) {
                continue;
            }

            self::judge($item, $item->calculated, $answer, $document, $at->append($item->name), $errors);
        }
    }

    /**
     * @param list<MappingError> $errors
     */
    private static function judge(
        NumberField $item,
        Calculation $calculation,
        mixed $answer,
        \stdClass $document,
        JsonPointer $pointer,
        array &$errors,
    ): void {
        // The schema has already said this is a number, and the definition that
        // a calculated one declares its places.
        if (!\is_float($answer) && !\is_int($answer)) {
            return;
        }

        $places = $item->decimals ?? 0;
        $worked = round(self::worthOf($calculation, $document), $places);

        if (round((float) $answer, $places) === $worked) {
            return;
        }

        $errors[] = new MappingError(
            $pointer,
            'form.value.miscalculated',
            \sprintf('This number is worked out from the others, and that comes to %s.', self::asText($worked, $places)),
            $answer,
        );
    }

    /**
     * What the answers in this document come to under this calculation.
     */
    private static function worthOf(Calculation $calculation, \stdClass $document): float
    {
        $list = $calculation->list();

        if ($list === null) {
            return self::of($calculation, $document);
        }

        /** @var mixed $entries */
        $entries = $document->{$list} ?? null;
        $entries = \is_array($entries) ? array_values($entries) : [];

        if ($calculation->count !== null) {
            return (float) \count($entries);
        }

        $worth = 0.0;

        // Once per entry, and the results added: that is what `over` means, and
        // it is the same whether the entry multiplies or adds inside itself.
        foreach ($entries as $entry) {
            $worth += $entry instanceof \stdClass ? self::of($calculation, $entry) : 0.0;
        }

        return $worth;
    }

    /**
     * One scope's worth: the named answers added, or multiplied.
     */
    private static function of(Calculation $calculation, \stdClass $scope): float
    {
        if ($calculation->product !== null) {
            $worth = 1.0;

            foreach ($calculation->product as $name) {
                $worth *= self::number($scope, $name);
            }

            return $worth;
        }

        $worth = 0.0;

        foreach ($calculation->sum ?? [] as $name) {
            $worth += self::number($scope, $name);
        }

        return $worth;
    }

    /**
     * An answer as a number, and nothing as nothing: a list somebody is still
     * filling in has entries with no amount in them, and the total of what is
     * there so far is the number a page shows.
     */
    private static function number(\stdClass $scope, string $name): float
    {
        /** @var mixed $answer */
        $answer = $scope->{$name} ?? null;

        return \is_float($answer) || \is_int($answer) ? (float) $answer : 0.0;
    }

    /**
     * The number as somebody would write it, so the message says what to send
     * rather than what a float looks like.
     */
    private static function asText(float $worked, int $places): string
    {
        return number_format($worked, $places, '.', '');
    }
}

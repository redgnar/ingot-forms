<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Whether a calculation could ever work out a number, asked of the scope it was
 * written in.
 *
 * Every refusal here is a mistake somebody will make and none of them shows: a
 * total that names an item nobody declared, or adds up a date, is a number that
 * silently never matches whatever a client sends — so the form would refuse
 * every save of it for the life of the form, saying only that the number is
 * wrong.
 *
 * **A calculation reaches into a list, and a condition may not**, which is worth
 * saying beside {@see ConditionsMakeSenseValidator} rather than left for
 * somebody to notice. A condition asks about *an* answer, and "the amount"
 * inside a list of three entries is three answers, so the question has no
 * meaning; an aggregate asks about *all* of them at once, which is exactly why
 * it has one. What holds for both is the other direction: neither may reach out
 * of the scope it was written in.
 *
 * @implements ObjectValidator<FormDefinition|CollectionField>
 */
final class CalculationsMakeSenseValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $declared = [];

        foreach ($object->items as $item) {
            $declared[$item->name] = $item;
        }

        /** @var array<string, list<string>> $reads what each calculated item reads, for the ring below */
        $reads = [];

        foreach ($object->items as $index => $item) {
            if (!$item instanceof NumberField || $item->calculated === null) {
                continue;
            }

            $path = \sprintf('/items/%d', $index);
            self::judge($item, $item->calculated, $path, $declared, $context, $reads);
        }

        self::refuseRings($object, $reads, $context);
    }

    /**
     * @param array<string, Field>        $declared
     * @param array<string, list<string>> $reads
     */
    private static function judge(
        NumberField $item,
        Calculation $calculation,
        string $path,
        array $declared,
        ValidationContext $context,
        array &$reads,
    ): void {
        // Without it, a sum of doubles compared exactly is a coin toss: `0.1 +
        // 0.2` is not `0.3` in any of the languages that will read this
        // document, and the gate that checks the answer needs a number of
        // places to round to before it can ask anything at all.
        if ($item->decimals === null) {
            $context->addError(
                $path . '/decimals',
                'form.calculated.needs-decimals',
                'A calculated number says how many decimal places it has, so the answer can be checked exactly.',
                $item->name,
            );
        }

        $list = $calculation->list();
        $inside = $declared;

        if ($list !== null) {
            $over = $declared[$list] ?? null;

            if (!$over instanceof CollectionField) {
                $context->addError(
                    $path . '/calculated/' . ($calculation->count !== null ? 'count' : 'over'),
                    $over === null ? 'form.calculated.unknown-item' : 'form.calculated.not-a-list',
                    $over === null
                        ? \sprintf('This scope declares no item named "%s".', $list)
                        : \sprintf('Item "%s" is not a list, so there are no entries to work across.', $list),
                    $list,
                );

                return;
            }

            // The names are read one scope down, once per entry — which is the
            // one place a rule here looks inside a list rather than beside it.
            $inside = [];

            foreach ($over->items as $declaredItem) {
                $inside[$declaredItem->name] = $declaredItem;
            }
        }

        foreach ($calculation->reads() as $name) {
            self::judgeName($item, $name, $calculation, $path, $inside, $context, $reads);
        }
    }

    /**
     * @param array<string, Field>        $inside
     * @param array<string, list<string>> $reads
     */
    private static function judgeName(
        NumberField $item,
        string $name,
        Calculation $calculation,
        string $path,
        array $inside,
        ValidationContext $context,
        array &$reads,
    ): void {
        $where = $path . '/calculated/' . ($calculation->sum !== null ? 'sum' : 'product');

        if ($name === $item->name) {
            $context->addError(
                $where,
                'form.calculated.self-reference',
                \sprintf('Item "%s" cannot be worked out from itself.', $item->name),
                $name,
            );

            return;
        }

        $read = $inside[$name] ?? null;

        if (!$read instanceof NumberField) {
            $context->addError(
                $where,
                $read === null ? 'form.calculated.unknown-item' : 'form.calculated.not-a-number',
                $read === null
                    ? \sprintf('Nothing named "%s" is declared where this calculation can see it.', $name)
                    : \sprintf('Item "%s" is not a number, so it cannot be added or multiplied.', $name),
                $name,
            );
        } elseif ($calculation->list() === null) {
            // Only what is read *beside* this item can make a ring: a name read
            // inside a list belongs to another scope, which is judged on its own
            // and cannot lead back here.
            $reads[$item->name] = [...$reads[$item->name] ?? [], $name];
        }
    }

    /**
     * @param array<string, list<string>> $reads
     */
    private static function refuseRings(
        FormDefinition|CollectionField $object,
        array $reads,
        ValidationContext $context,
    ): void {
        foreach ($object->items as $index => $item) {
            if (!self::reaches($item->name, $item->name, $reads, [])) {
                continue;
            }

            $context->addError(
                \sprintf('/items/%d', $index),
                'form.calculated.cycle',
                \sprintf('Item "%s" is worked out from a number that is worked out from it.', $item->name),
                $item->name,
            );

            return;
        }
    }

    /**
     * @param array<string, list<string>> $reads
     * @param list<string>                $walked
     */
    private static function reaches(string $from, string $target, array $reads, array $walked): bool
    {
        foreach ($reads[$from] ?? [] as $next) {
            if ($next === $target) {
                return true;
            }

            if (\in_array($next, $walked, true)) {
                continue;
            }

            if (self::reaches($next, $target, $reads, [...$walked, $next])) {
                return true;
            }
        }

        return false;
    }
}

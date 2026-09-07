<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * One form on several pages: a `wizard` shows one `step` at a time, and neither
 * word means anything without the other.
 *
 * A page of a form is not a kind of group that happens to be drawn one at a
 * time — it is a group *something steps*, and what steps it is the wizard. So a
 * `step` standing anywhere else would draw as an ordinary container and nothing
 * would ever hide it, while a `wizard` holding something that is not a step
 * would have content with no page to be on: a heading for the whole wizard goes
 * before it, where it is always visible, because inside it nothing could say
 * when to draw it.
 *
 * Two of the refusals are about where a wizard may *be*. Inside another wizard,
 * "next" is a question with no answer; inside a list entry it is the mistake
 * {@see TriggersBelongToTheFormValidator} refuses one line up — an entry is
 * answered in a form folded under its row, and paging that is not a thing
 * anybody asked for.
 *
 * Two wizards side by side are **not** refused: each steps its own steps, every
 * mechanism on the page is per-wizard, and a document that wants two of them is
 * describing two independent parts of one form.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class StepsBelongToAWizardValidator implements ObjectValidator
{
    public const string WIZARD = 'wizard';

    public const string STEP = 'step';

    public function validate(object $object, ValidationContext $context): void
    {
        self::walk($object->items, '/items', $context, insideAWizard: false, insideAnEntry: false);
    }

    /**
     * @param list<PresentedItem> $items
     */
    private static function walk(
        array $items,
        string $path,
        ValidationContext $context,
        bool $insideAWizard,
        bool $insideAnEntry,
    ): void {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            self::judge($item, $here, $context, $insideAWizard, $insideAnEntry);

            self::walk(
                $item->items,
                $here . '/items',
                $context,
                $item->widget === self::WIZARD,
                $insideAnEntry || $item->isCollection(),
            );
        }
    }

    private static function judge(
        PresentedItem $item,
        string $here,
        ValidationContext $context,
        bool $insideAWizard,
        bool $insideAnEntry,
    ): void {
        if ($item->widget === self::WIZARD) {
            self::judgeWizard($item, $here, $context, $insideAWizard, $insideAnEntry);
        } elseif ($item->widget === self::STEP && !$insideAWizard) {
            $context->addError(
                $here . '/widget',
                'presentation.step.outside-a-wizard',
                'A "step" is a page of a "wizard", so it has to sit directly inside one.',
                self::STEP,
            );
        }
    }

    private static function judgeWizard(
        PresentedItem $item,
        string $here,
        ValidationContext $context,
        bool $insideAWizard,
        bool $insideAnEntry,
    ): void {
        if ($insideAWizard) {
            $context->addError(
                $here . '/widget',
                'presentation.wizard.nested',
                'A "wizard" cannot sit inside another one: which of them "next" belongs to has no answer.',
                self::WIZARD,
            );

            return;
        }

        if ($insideAnEntry) {
            $context->addError(
                $here . '/widget',
                'presentation.wizard.in-an-entry',
                'A "wizard" pages a form, so it cannot sit inside an entry of a list.',
                self::WIZARD,
            );

            return;
        }

        $holdsAStep = false;
        $holdsAWizard = false;

        foreach ($item->items as $index => $inside) {
            if ($inside->widget === self::STEP) {
                $holdsAStep = true;
            } elseif ($inside->widget === self::WIZARD) {
                // A wizard inside this one is one mistake, and it is complained
                // about where it sits — saying "that is not a step" about it
                // too, and then "this one has no steps", would be one mistake
                // reported three times.
                $holdsAWizard = true;
            } else {
                $context->addError(
                    \sprintf('%s/items/%d/widget', $here, $index),
                    'presentation.wizard.holds-more-than-steps',
                    'A "wizard" holds the pages of the form and nothing else: whatever is not a "step" goes beside it.',
                    $inside->widget ?? '',
                );
            }
        }

        if (!$holdsAStep && !$holdsAWizard) {
            $context->addError(
                $here . '/items',
                'presentation.wizard.no-steps',
                'A "wizard" with no "step" in it is a stepper with nothing to step.',
                self::WIZARD,
            );
        }
    }
}

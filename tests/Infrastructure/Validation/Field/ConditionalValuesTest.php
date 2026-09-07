<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\MappingError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a conditional form takes, judged the way production judges it.
 *
 * This is not one kind of item but the rule that runs across them, so it uses
 * the same battery every type uses — and it inherits the assertion that matters
 * most here: **the form gate may not refuse what the published schema accepts**.
 * A conditional obligation is something only the schema can see, so that is the
 * one this feature could most easily have broken.
 */
final class ConditionalValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'checkbox', 'name' => 'hasCompany'],
            // Required *when asked*: the two members compose, and the obligation
            // moves into the condition.
            ['type' => 'text', 'name' => 'nip', 'required' => true, 'pattern' => '^[0-9]{10}$',
                'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
            ['type' => 'select', 'name' => 'rating', 'required' => true, 'options' => ['1', '2', '3']],
            // Always asked, sometimes owed.
            ['type' => 'text', 'name' => 'why', 'maxLength' => 500,
                'requiredWhen' => ['item' => 'rating', 'in' => ['1', '2']]],
        ]];
    }

    public static function verdicts(): iterable
    {
        // ── asked, and answered ──────────────────────────────────────────────
        yield 'the question is asked and answered' => [DeriveMode::Strict, '{"hasCompany": true, "nip": "1234567890", "rating": "3"}', null, null];
        yield 'asked and owed, and missing' => [DeriveMode::Strict, '{"hasCompany": true, "rating": "3"}', '/nip', 'schema.required'];
        // The item's own rules still apply, unchanged, whenever it is asked.
        yield 'asked, answered wrongly' => [DeriveMode::Strict, '{"hasCompany": true, "nip": "abc", "rating": "3"}', '/nip', 'schema.pattern'];

        // ── not asked ────────────────────────────────────────────────────────
        yield 'not asked, not answered' => [DeriveMode::Strict, '{"hasCompany": false, "rating": "3"}', null, null];
        // The half that makes a page's hiding safe: an answer to a question this
        // document did not ask is refused, and the finding names the member.
        yield 'not asked, answered anyway' => [DeriveMode::Strict, '{"hasCompany": false, "nip": "1234567890", "rating": "3"}', '/nip', 'schema.properties'];
        // Nothing said about the item the condition asks about is not the same
        // as saying no — but it is not "asked" either.
        yield 'nothing said either way' => [DeriveMode::Strict, '{"rating": "3"}', null, null];
        yield 'nothing said, answered anyway' => [DeriveMode::Strict, '{"rating": "3", "nip": "1234567890"}', '/nip', 'schema.properties'];

        // ── a conditional obligation ─────────────────────────────────────────
        yield 'owed by the answer before it' => [DeriveMode::Strict, '{"rating": "1"}', '/why', 'schema.required'];
        yield 'owed, and given' => [DeriveMode::Strict, '{"rating": "1", "why": "It did not work"}', null, null];
        yield 'not owed, and not given' => [DeriveMode::Strict, '{"rating": "3"}', null, null];
        // Always asked, so always allowed: only the obligation was conditional.
        yield 'not owed, given anyway' => [DeriveMode::Strict, '{"rating": "3", "why": "It was fine"}', null, null];

        // ── while filling in ─────────────────────────────────────────────────
        yield 'a draft owes nothing at all' => [DeriveMode::Draft, '{}', null, null];
        yield 'a draft owes no conditional answer' => [DeriveMode::Draft, '{"hasCompany": true}', null, null];
        yield 'a draft owes no reason either' => [DeriveMode::Draft, '{"rating": "1"}', null, null];
        // …but "this was not asked" is a rule about the value, so it holds while
        // somebody is still filling the form in.
        yield 'a draft still refuses what was not asked' => [DeriveMode::Draft, '{"hasCompany": false, "nip": "1234567890"}', '/nip', 'schema.properties'];
        yield 'a draft judges an answer it did ask for' => [DeriveMode::Draft, '{"hasCompany": true, "nip": "abc"}', '/nip', 'schema.pattern'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function owed(): iterable
    {
        // An obligation of the definition's own, beside one a condition brought
        // about — the pair that used to come back one at a time, because the
        // schema gate reports a level in phases and stops after the phase that
        // failed.
        yield 'an obligation and a conditional one' => ['{"hasCompany": true}', ['/rating', '/nip']];

        // And two conditional ones, which `allOf` used to report one of: it
        // stops at the first branch that did not hold.
        yield 'two conditional obligations' => ['{"hasCompany": true, "rating": "1"}', ['/nip', '/why']];
    }

    /**
     * @param list<string> $pointers
     */
    #[DataProvider('owed')]
    public function testEveryAnswerThatIsOwedIsNamedInOneRefusal(string $json, array $pointers): void
    {
        // GIVEN a document that owes more than one answer
        // WHEN it is judged
        try {
            $this->values->assertFit(self::definition(), self::values($json), DeriveMode::Strict, FormId::next());
            self::fail('Expected the values to be refused.');
        } catch (ValuesNotValid $refused) {
            // THEN every one of them is in the one refusal, each pointed at its
            // own member: a page marks two controls and a person answers both in
            // one go, instead of being sent round again for the second
            self::assertSame($pointers, array_map(
                static fn(MappingError $error): string => $error->pointer->toString(),
                $refused->report->errors,
            ));
        }
    }
}

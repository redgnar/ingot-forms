<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\SaveFormData;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * What saving a draft orchestrates: one transaction, a locked read, the draft
 * contract, and a write that only happens when everything before it held.
 */
final class SaveFormDataTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    public function testStoresTheDraftUnderTheRowLock(): void
    {
        // GIVEN a form nobody has filled in yet
        $forms = new InMemoryForms();
        $transactions = new ImmediateTransactions();
        $values = new StubValues();
        $id = self::plant($forms);

        // WHEN
        self::saveData($transactions, $forms, $values)($id, self::values('{"email": "ada@example.com"}'));

        // THEN the values are stored, having been judged as a draft
        $form = $forms->get($id);
        self::assertSame(FormStatus::Draft, $form->status());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertSame([DeriveMode::Draft], $values->modes);

        // AND the whole transition happened in one transaction, on a locked row
        self::assertSame(1, $transactions->opened);
        self::assertSame([(string) $id], $forms->locked);
        self::assertSame(1, $forms->saves);
    }

    public function testAConfirmedFormIsLockedForGood(): void
    {
        // GIVEN a form that was already confirmed
        $forms = new InMemoryForms();
        $values = new StubValues();
        $id = self::plant($forms);
        $forms->get($id)->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $forms->get($id)->confirm(new StubValues());

        // WHEN / THEN
        $this->expectException(FormLocked::class);

        self::saveData(new ImmediateTransactions(), $forms, $values)($id, self::values('{"email": "eve@example.com"}'));
    }

    public function testValuesThatDoNotFitAreNeverStored(): void
    {
        // GIVEN a validator that refuses whatever it is handed
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN
        try {
            self::saveData(new ImmediateTransactions(), $forms, new StubValues(refuse: true))($id, self::values('{"email": 1}'));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid $exception) {
            // THEN the report travels untouched, and the form stayed empty
            self::assertSame('schema.minimum', $exception->report->errors[0]->code);
            self::assertSame(FormStatus::Empty, $forms->get($id)->status());
            self::assertSame(0, $forms->saves);
        }
    }

    private static function saveData(
        ImmediateTransactions $transactions,
        InMemoryForms $forms,
        StubValues $values,
    ): SaveFormData {
        return new SaveFormData($transactions, $forms, $values);
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, Definition::stored(self::DEFINITION, new SpyParser()), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}

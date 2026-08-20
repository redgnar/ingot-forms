<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\ConfirmForm;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
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
 * Confirmation is the one-way door: the stored values are judged strictly, and
 * only then does the form lock.
 */
final class ConfirmFormTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    public function testJudgesTheStoredValuesStrictlyAndLocksTheForm(): void
    {
        // GIVEN a draft
        $forms = new InMemoryForms();
        $values = new StubValues();
        $id = self::plant($forms);
        $forms->get($id)->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());

        // WHEN
        (new ConfirmForm(new ImmediateTransactions(), $forms, $values))($id);

        // THEN
        self::assertSame(FormStatus::Confirmed, $forms->get($id)->status());
        self::assertSame([DeriveMode::Strict], $values->modes);
    }

    public function testThereIsNothingToConfirmOnAnEmptyForm(): void
    {
        // GIVEN a form nobody filled in
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN / THEN
        $this->expectException(FormHasNoData::class);

        (new ConfirmForm(new ImmediateTransactions(), $forms, new StubValues()))($id);
    }

    public function testConfirmingTwiceIsRefused(): void
    {
        // GIVEN a form already confirmed
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $forms->get($id)->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $forms->get($id)->confirm(new StubValues());

        // WHEN / THEN
        $this->expectException(FormAlreadyConfirmed::class);

        (new ConfirmForm(new ImmediateTransactions(), $forms, new StubValues()))($id);
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

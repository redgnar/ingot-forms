<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\CreateForm;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * What creating a form orchestrates — and, since a form may be born holding
 * something, when that something is judged and what happens if it does not fit.
 */
final class CreateFormTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true],
            ['type' => 'number', 'name' => 'age', 'min' => 18],
        ],
    ];

    public function testAFormCreatedWithNothingHoldsNothing(): void
    {
        // GIVEN
        $forms = new InMemoryForms();
        $values = new StubValues();

        // WHEN
        $id = self::createForm($forms, $values)(self::DEFINITION, self::tomorrow());

        // THEN there is nothing to judge, so nothing was asked
        self::assertSame(FormStatus::Empty, $forms->get($id)->status());
        self::assertSame([], $values->modes);
        self::assertSame(1, $forms->adds);
    }

    public function testAFormIsBornHoldingWhatItWasGiven(): void
    {
        // GIVEN
        $forms = new InMemoryForms();
        $values = new StubValues();

        // WHEN a client creates a form with what it already knows
        $id = self::createForm($forms, $values)(self::DEFINITION, self::tomorrow(), data: self::data('{"email": "ada@example.com"}'));

        // THEN the form starts as a draft holding it, byte for byte
        $form = $forms->get($id);
        self::assertSame(FormStatus::Draft, $form->status());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertNotNull($form->dataSavedAt());

        // AND it was judged as a draft, because a form somebody has not filled
        // in yet is exactly what this is
        self::assertSame([DeriveMode::Draft], $values->modes);

        // AND one write did it: an insert writes the whole row, so nothing has
        // to go back and add the values afterwards
        self::assertSame(1, $forms->adds);
        self::assertSame(0, $forms->saves);
    }

    public function testAnEmptyDocumentStillMakesItADraft(): void
    {
        // GIVEN / WHEN a client says "it holds nothing yet" rather than saying
        // nothing at all
        $forms = new InMemoryForms();
        $id = self::createForm($forms, new StubValues())(self::DEFINITION, self::tomorrow(), data: self::data('{}'));

        // THEN that is a draft holding an empty document, and `{}` survives as
        // itself rather than becoming a list
        self::assertSame(FormStatus::Draft, $forms->get($id)->status());
        self::assertSame('{}', $forms->get($id)->valuesJson());
    }

    public function testAFormIsNeverCreatedHoldingSomethingItWouldRefuse(): void
    {
        // GIVEN a validator that refuses what it is handed
        $forms = new InMemoryForms();

        // WHEN
        try {
            self::createForm($forms, new StubValues(refuse: true))(self::DEFINITION, self::tomorrow(), data: self::data('{"age": 7}'));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid $exception) {
            // THEN the report travels untouched, and no form exists at all: a
            // form that could not accept these values later must not be born
            // holding them now
            self::assertSame('schema.minimum', $exception->report->errors[0]->code);
            self::assertSame(0, $forms->adds);
        }
    }

    private static function createForm(InMemoryForms $forms, StubValues $values): CreateForm
    {
        return new CreateForm(
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
            $forms,
            new PresentationRules(new Engines([new CoreHtmlEngine(), new BootstrapEngine()])),
            $values,
        );
    }

    private static function tomorrow(): ExpireDate
    {
        return ExpireDate::future(new \DateTimeImmutable('+1 day'));
    }

    private static function data(string $json): \stdClass
    {
        $data = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }
}

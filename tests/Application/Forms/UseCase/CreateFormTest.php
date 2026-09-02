<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\WebhooksNotSignable;
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
use App\Domain\Forms\ValueObject\Webhooks;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingAnnouncer;
use App\Tests\Application\Forms\Fake\RecordingWebhook;
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

    public function testAWorkerIsAskedToGetOnWithItWhateverTheFormWasBornHolding(): void
    {
        // GIVEN a form created with nothing in it — the ordinary case
        $forms = new InMemoryForms();
        $announcer = new RecordingAnnouncer();

        // WHEN
        new CreateForm(
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
            $forms,
            new PresentationRules(new Engines([new CoreHtmlEngine(), new BootstrapEngine()])),
            new StubValues(),
            $announcer,
            new RecordingWebhook(),
        )(self::DEFINITION, self::tomorrow());

        // THEN a worker is still nudged. It was gated on `$data` while the only
        // thing a creation could owe was the first draft's announcement, and
        // that gate left `form.created` sitting in the queue until the next
        // sweep — the queue is what knows whether anything is owed, and a nudge
        // about nothing costs one empty look.
        self::assertSame(1, $announcer->hurried);
    }

    public function testAFormMayNotNameAnEndpointNothingCouldSignFor(): void
    {
        // GIVEN a deployment with no signing secret
        $forms = new InMemoryForms();
        $webhook = new RecordingWebhook();
        $webhook->signing = false;

        // WHEN a form is created naming where it reports itself
        try {
            self::createForm($forms, new StubValues(), $webhook)(
                self::DEFINITION,
                self::tomorrow(),
                webhooks: Webhooks::of(null, 'https://receiver.test/confirmed'),
            );
            self::fail('Expected WebhooksNotSignable.');
        } catch (WebhooksNotSignable) {
            // THEN no form exists: one holding a promise nothing can keep would
            // refuse every notification it owes for the rest of its life, and
            // its author would find out from a column in a queue
            self::assertSame(0, $forms->adds);
        }
    }

    public function testAFormThatNamesNobodyIsFineWhereNothingCanBeSigned(): void
    {
        // GIVEN the same deployment
        $forms = new InMemoryForms();
        $webhook = new RecordingWebhook();
        $webhook->signing = false;

        // WHEN a form that reports itself nowhere is created
        self::createForm($forms, new StubValues(), $webhook)(self::DEFINITION, self::tomorrow());

        // THEN nothing is refused: the check is about a promise, and this form
        // made none
        self::assertSame(1, $forms->adds);
    }

    private static function createForm(InMemoryForms $forms, StubValues $values, ?RecordingWebhook $webhook = null): CreateForm
    {
        return new CreateForm(
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
            $forms,
            new PresentationRules(new Engines([new CoreHtmlEngine(), new BootstrapEngine()])),
            $values,
            new RecordingAnnouncer(),
            $webhook ?? new RecordingWebhook(),
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

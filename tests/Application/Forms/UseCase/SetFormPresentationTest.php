<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\ReadForm;
use App\Application\Forms\UseCase\SetFormPresentation;
use App\Domain\Forms\Exception\PresentationNotSet;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\EngineCatalogue;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * What saying how a form is shown orchestrates: one transaction, a locked read,
 * and a write that only happens when the form accepted the document.
 */
final class SetFormPresentationTest extends TestCase
{
    private const string DEFINITION = '{"id":"contact","items":[{"type":"text","name":"email"}]}';

    public function testItStoresThePresentationUnderTheRowLock(): void
    {
        // GIVEN a form nobody has said anything about
        $forms = new InMemoryForms();
        $transactions = new ImmediateTransactions();
        $id = self::plant($forms);

        // WHEN
        new SetFormPresentation($transactions, $forms, self::rules())($id, self::presentation());

        // THEN it is held...
        self::assertStringContainsString('"name":"email"', (string) $forms->get($id)->presentation());

        // ...and the whole thing happened in one transaction, on a locked row
        self::assertSame(1, $transactions->opened);
        self::assertSame([(string) $id], $forms->locked);
        self::assertSame(1, $forms->saves);
    }

    public function testAPresentationThatDoesNotFitIsNeverStored(): void
    {
        // GIVEN a document naming an item the form does not declare
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN
        try {
            new SetFormPresentation(new ImmediateTransactions(), $forms, self::rules())($id, self::presentation('nickname'));
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $exception) {
            // THEN the report travels untouched, and nothing was written
            self::assertSame('presentation.item.unknown', $exception->report->errors[0]->code);
            self::assertNull($forms->get($id)->presentation());
            self::assertSame(0, $forms->saves);
        }
    }

    public function testReadingBackWhatWasSet(): void
    {
        // GIVEN a form that has been given a presentation
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        new SetFormPresentation(new ImmediateTransactions(), $forms, self::rules())($id, self::presentation());

        // WHEN
        $json = new ReadForm($forms)->presentationJson($id);

        // THEN the document comes back as it went in
        self::assertJsonStringEqualsJsonString((string) self::presentation(), $json);
    }

    public function testAFormNobodyDescribedSaysSo(): void
    {
        // GIVEN a form with no presentation
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN / THEN the refusal names the form it is about
        try {
            new ReadForm($forms)->presentationJson($id);
            self::fail('Expected PresentationNotSet.');
        } catch (PresentationNotSet $exception) {
            self::assertStringContainsString((string) $id, $exception->getMessage());
        }
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, Definition::stored(self::DEFINITION, new SpyParser()), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function presentation(string $name = 'email'): Presentation
    {
        $processor = new PresentationProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse([
            'engine' => 'core-html',
            'items' => [['name' => $name, 'widget' => 'text']],
        ]));
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new EngineCatalogue());
    }
}

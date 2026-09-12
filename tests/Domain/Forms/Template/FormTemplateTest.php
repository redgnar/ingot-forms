<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Template;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use PHPUnit\Framework\TestCase;

/**
 * The one rule a template keeps: the pair it points at fits.
 *
 * Everything else about it is a name and two pointers, so these tests are mostly
 * about what it refuses and about the two moments it refuses at — coming into
 * being, and every time a pointer moves.
 */
final class FormTemplateTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    private const string SHOWS_EMAIL = '{"engine":"core-html","items":[{"name":"email"},{"widget":"confirm"}]}';

    private const string SHOWS_SOMETHING_ELSE = '{"engine":"core-html","items":[{"name":"nickname"},{"widget":"confirm"}]}';

    public function testATemplateComesIntoBeingAlreadyUsingItsFirstPair(): void
    {
        // GIVEN the first version of each
        $definition = self::definition();
        $presentation = self::presentation(self::SHOWS_EMAIL);

        // WHEN a template is made of them
        $template = FormTemplate::of(
            FormTemplateId::next(),
            'Damage report',
            $definition,
            $presentation,
            self::rules(),
            new \DateTimeImmutable('2026-09-12T08:00:00+00:00'),
            Actor::of('sso:ada'),
        );

        // THEN it is usable at once — there is no state in which one exists with
        // nothing in use
        self::assertSame('Damage report', $template->name());
        self::assertTrue($definition->id()->equals($template->definition()));
        self::assertTrue($presentation->id()->equals($template->presentation() ?? throw new \LogicException()));
        self::assertSame('2026-09-12T08:00:00+00:00', $template->createdAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('sso:ada', (string) $template->createdBy());
    }

    public function testATemplateMayOfferNoPresentationAtAll(): void
    {
        // GIVEN a deployment that never draws a page
        // WHEN
        $template = FormTemplate::of(FormTemplateId::next(), 'API only', self::definition(), null, self::rules());

        // THEN it shows nothing and nobody was asserted, and neither is a refusal
        self::assertNull($template->presentation());
        self::assertNull($template->createdBy());
    }

    public function testAPairThatDoesNotFitIsRefusedBeforeTheTemplateExists(): void
    {
        // GIVEN a presentation showing an item the definition does not declare
        // WHEN / THEN the findings are the ordinary presentation ones, pointing
        // at the item rather than saying the pair is bad
        try {
            FormTemplate::of(FormTemplateId::next(), 'Broken', self::definition(), self::presentation(self::SHOWS_SOMETHING_ELSE), self::rules());
            self::fail('A template was made of a pair that does not fit.');
        } catch (PresentationNotValid $refused) {
            self::assertSame('presentation.item.unknown', $refused->report->errors[0]->code);
        }
    }

    public function testMovingThePointerJudgesThePairAgain(): void
    {
        // GIVEN a template already in use
        $template = FormTemplate::of(FormTemplateId::next(), 'Damage report', self::definition(), self::presentation(self::SHOWS_EMAIL), self::rules());
        $was = $template->presentation();

        // WHEN a pair that does not fit is put in use
        try {
            $template->activate(self::definition(), self::presentation(self::SHOWS_SOMETHING_ELSE), self::rules());
            self::fail('A pair that does not fit was put in use.');
        } catch (PresentationNotValid) {
            // THEN nothing moved: the judgment is what stands between a prepared
            // definition and every form made from here afterwards
            self::assertSame($was, $template->presentation());
        }
    }

    public function testATemplateCanStopShowingAnything(): void
    {
        // GIVEN a template that shows something
        $template = FormTemplate::of(FormTemplateId::next(), 'Damage report', self::definition(), self::presentation(self::SHOWS_EMAIL), self::rules());

        // WHEN a pair with no presentation is put in use
        $definition = self::definition();
        $template->activate($definition, null, self::rules());

        // THEN it shows nothing, because the pair is stated whole and naming no
        // presentation means none rather than "keep the one you had"
        self::assertNull($template->presentation());
        self::assertTrue($definition->id()->equals($template->definition()));
    }

    public function testATemplateReadBackIsNotJudgedAgain(): void
    {
        // GIVEN what storage hands over: two ids and no documents to judge
        $definition = DefinitionId::next();
        $presentation = PresentationId::next();
        $moment = new \DateTimeImmutable('2026-09-12T08:00:00+00:00');

        // WHEN
        $template = FormTemplate::fromState(FormTemplateId::next(), 'Damage report', $definition, $presentation, $moment);

        // THEN it is whole, and reading it asked no rules anything — the pair was
        // judged when it was put in use
        self::assertTrue($definition->equals($template->definition()));
        self::assertTrue($presentation->equals($template->presentation() ?? throw new \LogicException()));
        self::assertSame($moment, $template->createdAt());
        self::assertNull($template->createdBy());
    }

    public function testATemplateCanBeRenamedAndNothingElseChanges(): void
    {
        // GIVEN
        $template = FormTemplate::of(FormTemplateId::next(), 'Damage report', self::definition(), null, self::rules());
        $definition = $template->definition();

        // WHEN
        $template->rename('Claim');

        // THEN
        self::assertSame('Claim', $template->name());
        self::assertSame($definition, $template->definition());
    }

    public function testANameThatIsNothingButSpaceIsNotAName(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        FormTemplate::of(FormTemplateId::next(), "  \t ", self::definition(), null, self::rules());
    }

    public function testANameLongerThanTheColumnIsRefusedRatherThanCut(): void
    {
        // GIVEN a name one character past what is kept
        // WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        FormTemplate::of(FormTemplateId::next(), str_repeat('a', FormTemplate::MAX_NAME_LENGTH + 1), self::definition(), null, self::rules());
    }

    public function testANameExactlyAsLongAsTheColumnIsKept(): void
    {
        // GIVEN the longest name there is
        $name = str_repeat('a', FormTemplate::MAX_NAME_LENGTH);

        // WHEN / THEN — the limit itself is pinned, not only the far side of it
        self::assertSame($name, FormTemplate::of(FormTemplateId::next(), $name, self::definition(), null, self::rules())->name());
    }

    public function testALongNameIsMeasuredInCharactersAndNotInBytes(): void
    {
        // GIVEN the longest name there is, written in a language that needs more
        // than one byte a letter — which is most of them
        $name = str_repeat('ż', FormTemplate::MAX_NAME_LENGTH);
        self::assertGreaterThan(FormTemplate::MAX_NAME_LENGTH, \strlen($name));

        // WHEN / THEN it is accepted, because the limit is about what somebody
        // reads and the column is wide enough for it. Counting bytes would
        // refuse half the languages this service is translated into
        self::assertSame($name, FormTemplate::of(FormTemplateId::next(), $name, self::definition(), null, self::rules())->name());
    }

    public function testATemplateThatIsNotThereSaysWhichOneWasAskedFor(): void
    {
        // GIVEN an id nothing was created under
        $id = FormTemplateId::next();

        // WHEN / THEN — the id is in the message, because "does not exist" alone
        // leaves whoever reads a log with nothing to look for
        self::assertSame(
            \sprintf('Form template "%s" does not exist.', $id),
            new FormTemplateNotFound($id)->getMessage(),
        );
    }

    public function testRenamingIsHeldToTheSameRule(): void
    {
        // GIVEN
        $template = FormTemplate::of(FormTemplateId::next(), 'Damage report', self::definition(), null, self::rules());

        // WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        $template->rename('');
    }

    public function testTheMomentIsKeptInUtcHoweverItWasGiven(): void
    {
        // GIVEN a moment written in another zone
        $template = FormTemplate::of(
            FormTemplateId::next(),
            'Damage report',
            self::definition(),
            null,
            self::rules(),
            new \DateTimeImmutable('2026-09-12T10:00:00+02:00'),
        );

        // THEN
        self::assertSame('2026-09-12T08:00:00+00:00', $template->createdAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('UTC', $template->createdAt()->getTimezone()->getName());
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }

    private static function definition(): StoredDefinition
    {
        return new StoredDefinition(
            DefinitionId::next(),
            Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION),
            new \DateTimeImmutable(),
        );
    }

    private static function presentation(string $document): StoredPresentation
    {
        $processor = new PresentationProcessor(new FormMapperFactory()->create());

        return new StoredPresentation(
            PresentationId::next(),
            Presentation::of($processor->presentationFromStored($document), $document),
            new \DateTimeImmutable(),
        );
    }
}

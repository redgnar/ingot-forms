<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Record;

use App\Application\Forms\Record\Answered;
use App\Application\Forms\Record\Entries;
use App\Application\Forms\Record\Filed;
use App\Application\Forms\Record\FormRecords;
use App\Application\Forms\Record\RecordedRow;
use App\Application\Forms\Record\Section;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * A confirmed form read as a record: every question, in order, with the answer
 * it was given.
 *
 * This is where the record's whole content is pinned. What renders it is one
 * template and one library, so what is asserted here is the *reading* — the
 * order, the labels, and how each kind of stored value comes out as something a
 * person can read back.
 */
final class FormRecordsTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = ['items' => [
        ['type' => 'text', 'name' => 'title', 'required' => true],
        ['type' => 'number', 'name' => 'floor', 'min' => 1, 'max' => 5, 'decimals' => 0],
        ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
        ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'legal'], 'min' => 1],
        ['type' => 'checkbox', 'name' => 'terms', 'mustBeChecked' => true],
        ['type' => 'datetime', 'name' => 'seen'],
        ['type' => 'text', 'name' => 'note'],
        ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 65536],
        ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'items' => [
            ['type' => 'text', 'name' => 'sku', 'required' => true],
            ['type' => 'select', 'name' => 'unit', 'options' => ['pc', 'kg']],
        ]],
    ]];

    private const string VALUES = '{
        "title": "Two lines\nof text",
        "floor": 2,
        "country": "de",
        "tags": ["urgent", "legal"],
        "terms": true,
        "seen": "2026-03-01T14:30:00+01:00",
        "scan": {"id": "01a0f3d4-0000-7000-8000-0000000000a2", "name": "signature.png", "size": 8462, "type": "image/png"},
        "lines": [{"sku": "A-1", "unit": "kg"}, {"sku": "B-2"}]
    }';

    public function testEveryQuestionIsThereWithTheAnswerItWasGiven(): void
    {
        // GIVEN a confirmed form described by a presentation
        $rows = new FormRecords()->of(self::form(presented: true), 'en')->rows;

        // THEN the labels are the document's, in the language asked for, and in
        // the order the presentation put them — not the definition's. The two
        // questions inside the card are under its heading rather than beside it
        self::assertSame(
            ['What happened', 'About it', 'Which floor', 'Terms', 'Seen at', 'Anything else', 'Your signature', 'Lines'],
            array_map(static fn(RecordedRow $row): string => $row->label(), $rows),
        );
    }

    public function testAGroupKeepsItsWordsAndLosesItsShape(): void
    {
        // GIVEN a presentation that puts two questions in a card labelled
        // "About it" — a card being one of three ways this kit groups things
        $rows = new FormRecords()->of(self::form(presented: true), 'en')->rows;
        $section = $rows[1];

        // THEN the record has no card, and it has the sentence somebody wrote
        // about those two questions: dropping it would drop part of what was
        // asked
        self::assertInstanceOf(Section::class, $section);
        self::assertSame('section', $section->kind());
        self::assertSame('About it', $section->label());
        self::assertSame(['Country', 'Tags'], array_map(
            static fn(RecordedRow $row): string => $row->label(),
            $section->rows,
        ));

        // AND the answers inside it read exactly as they would anywhere else
        self::assertSame('Germany (de)', self::answers($section->rows)['Country']);
    }

    public function testAGroupWithNothingToSayIsSteppedThrough(): void
    {
        // GIVEN a presentation whose container carries no label at all
        $presentation = self::PRESENTATION;
        unset($presentation['items'][2]['label']);

        // WHEN
        $rows = new FormRecords()->of(self::form(presented: true, presentation: $presentation), 'en')->rows;

        // THEN what was inside it stands where it was, without a heading: a
        // heading with no words is a line where a sentence should be
        self::assertSame(
            ['What happened', 'Country', 'Tags', 'Which floor', 'Terms', 'Seen at', 'Anything else', 'Your signature', 'Lines'],
            array_map(static fn(RecordedRow $row): string => $row->label(), $rows),
        );
    }

    public function testHowEachKindOfAnswerReadsBack(): void
    {
        // GIVEN — flattened, because which group an answer sits in is a
        // different question from how it reads
        $answers = self::answers(self::flattened(new FormRecords()->of(self::form(presented: true), 'en')->rows));

        // THEN text is itself, and a number is the number
        self::assertSame("Two lines\nof text", $answers['What happened']);
        self::assertSame('2', $answers['Which floor']);

        // AND a choice reads as the words the document gave it, with the value
        // beside them: a record is read by somebody asking what was answered,
        // and what was *sent* is the value
        self::assertSame('Germany (de)', $answers['Country']);
        self::assertSame('Urgent (urgent), Legal (legal)', $answers['Tags']);

        // AND a tick is true or false rather than a word, because "yes" and "no"
        // are page chrome and this layer has no business inventing a sentence
        self::assertTrue($answers['Terms']);

        // AND a moment keeps its offset, which is the whole reason it is not a
        // date: "14:30" alone says nothing about which 14:30
        self::assertSame('2026-03-01 14:30 (UTC+01:00)', $answers['Seen at']);

        // AND an unanswered optional question says so, rather than looking like
        // an answer somebody gave as nothing
        self::assertNull($answers['Anything else']);
    }

    public function testAFileIsItsOwnKindOfRowRatherThanASentenceAboutOne(): void
    {
        // GIVEN a form answered with a file
        $rows = self::flattened(new FormRecords()->of(self::form(presented: false), 'en')->rows);
        $filed = array_values(array_filter($rows, static fn(RecordedRow $row): bool => $row->label() === 'scan'));

        // THEN the row carries the four facts the values document holds, and
        // nothing is decided here about what can be done with them: for some
        // files the name is the least of what a record should say — a signature
        // *is* an image — and only a renderer knows what it can draw
        self::assertInstanceOf(Filed::class, $filed[0]);
        self::assertSame('filed', $filed[0]->kind());
        self::assertSame('signature.png', $filed[0]->name);
        self::assertSame('image/png', $filed[0]->type);
        self::assertSame(8462, $filed[0]->size);
        self::assertSame('01a0f3d4-0000-7000-8000-0000000000a2', $filed[0]->id);
        self::assertNull($filed[0]->picture);

        // AND how big it is, in words a person reads rather than bytes to count
        self::assertSame('8.3 kB', $filed[0]->measured());
    }

    public function testAListIsOneRowPerEntryAndEachEntryIsADocumentOfItsOwn(): void
    {
        // GIVEN
        $rows = new FormRecords()->of(self::form(presented: true), 'en')->rows;
        $lines = array_values(array_filter($rows, static fn(RecordedRow $row): bool => $row->label() === 'Lines'));

        // THEN the list is drawn as what it is: entries, each with its own
        // questions and answers, rather than flattened into a sentence
        self::assertInstanceOf(Entries::class, $lines[0]);
        self::assertSame('entries', $lines[0]->kind());
        self::assertCount(2, $lines[0]->entries);
        self::assertSame(['SKU' => 'A-1', 'Unit' => 'Kilograms (kg)'], self::answers($lines[0]->entries[0]));

        // AND a question the second entry left alone says so there, one scope
        // down, exactly as it would at the top
        self::assertSame(['SKU' => 'B-2', 'Unit' => null], self::answers($lines[0]->entries[1]));
    }

    public function testAListNobodyAnsweredIsARowThatSaysSo(): void
    {
        // GIVEN a confirmed form whose list was left alone — which only a form
        // that does not ask for entries can be
        $definition = self::DEFINITION;
        unset($definition['items'][8]['min']);
        $rows = new FormRecords()->of(self::form(presented: false, definition: $definition, values: '{"title": "x"}'), 'en')->rows;
        $lines = array_values(array_filter($rows, static fn(RecordedRow $row): bool => $row->label() === 'lines'));

        // THEN it is still a row, with nothing in it: asked and left alone is
        // something a record has to be able to say
        self::assertInstanceOf(Entries::class, $lines[0]);
        self::assertSame([], $lines[0]->entries);
    }

    public function testAFormNobodyDescribedStillHasARecord(): void
    {
        // GIVEN a form created through the API with no presentation at all —
        // which is a normal form here, and the case a page cannot be drawn for
        $sheet = new FormRecords()->of(self::form(presented: false), 'en');
        $answers = self::answers($sheet->rows);

        // THEN the definition is what was asked, so it is what the record says:
        // its items, in the order it declares them, labelled by their own names
        self::assertSame(
            ['title', 'floor', 'country', 'tags', 'terms', 'seen', 'note', 'scan', 'lines'],
            array_map(static fn(RecordedRow $row): string => $row->label(), $sheet->rows),
        );

        // AND every answer is still readable; only the wording of the options is
        // gone, because nothing worded them
        self::assertSame('de', $answers['country']);
        self::assertSame('urgent, legal', $answers['tags']);
        self::assertSame('2026-03-01 14:30 (UTC+01:00)', $answers['seen']);
        self::assertTrue($answers['terms']);
    }

    public function testTheRecordCarriesWhoClosedTheFormAndWhen(): void
    {
        // GIVEN a form that records who touches it
        $form = self::form(presented: true, identity: IdentityMode::Recorded);
        $sheet = new FormRecords()->of($form, 'en');

        // THEN the facts a record is filed by
        self::assertSame('crm', (string) $sheet->author);
        self::assertSame('owner', (string) $sheet->confirmedBy);
        self::assertSame('en', $sheet->locale);
        // The moment the form closed, which is the one a record is filed by
        self::assertSame($sheet->confirmedAt, $form->confirmedAt());
    }

    public function testItIsReadInTheLanguageAskedForAndFallsBackTheWayALabelDoes(): void
    {
        // GIVEN the same form read in Polish, whose catalogue is deliberately
        // incomplete — one label and one option, and nothing else
        $answers = self::answers(self::flattened(new FormRecords()->of(self::form(presented: true), 'pl')->rows));

        // THEN what Polish words is Polish
        self::assertArrayHasKey('Co się stało', $answers);
        self::assertSame('Niemcy (de)', $answers['Country']);

        // AND what it does not falls back to the document's default rather than
        // to the code: a half-translated catalogue is how translating goes
        self::assertArrayHasKey('Tags', $answers);
    }

    /**
     * @param list<RecordedRow> $rows
     *
     * @return array<string, string|bool|null>
     */
    private static function answers(array $rows): array
    {
        $answers = [];

        foreach ($rows as $row) {
            if ($row instanceof Answered) {
                $answers[$row->label()] = $row->answer;
            }
        }

        return $answers;
    }

    /**
     * Every row, whichever group it is in. What sections do is asserted on its
     * own; the rest of this battery is about how an answer reads.
     *
     * @param list<RecordedRow> $rows
     *
     * @return list<RecordedRow>
     */
    private static function flattened(array $rows): array
    {
        $flat = [];

        foreach ($rows as $row) {
            if ($row instanceof Section) {
                $flat = [...$flat, ...self::flattened($row->rows)];

                continue;
            }

            $flat[] = $row;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed>|null $presentation
     * @param array<string, mixed>|null $definition
     */
    private static function form(
        bool $presented,
        IdentityMode $identity = IdentityMode::Anonymous,
        ?array $presentation = null,
        ?array $definition = null,
        ?string $values = null,
    ): Form {
        $mapper = new FormMapperFactory()->create();
        $definitions = new FormDefinitionProcessor($mapper);
        $presentations = new PresentationProcessor($mapper);

        $form = new Form(
            FormId::next(),
            $definitions->document($definitions->parse($definition ?? self::DEFINITION)),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presented ? $presentations->document($presentations->parse($presentation ?? self::PRESENTATION)) : null,
            $presented ? new PresentationRules(new Engines([new BootstrapEngine()])) : null,
            identity: $identity,
            author: Actor::of('crm'),
        );

        $document = json_decode($values ?? self::VALUES, false, flags: \JSON_THROW_ON_ERROR);
        $form->saveDraft($document, new StubValues(), Actor::of('ada'));
        $form->confirm(new StubValues(), Actor::of('owner'));

        return $form;
    }

    /** @var array<string, mixed> */
    private const array PRESENTATION = [
        'engine' => 'bootstrap',
        'defaultLocale' => 'en',
        'items' => [
            ['widget' => 'heading', 'label' => 'r.heading'],
            ['name' => 'title', 'widget' => 'textarea', 'label' => 'r.title'],
            // A container is stepped through rather than printed: a record has
            // no cards, and what is inside one is still what was asked.
            ['widget' => 'card', 'label' => 'r.about', 'items' => [
                ['name' => 'country', 'widget' => 'select', 'label' => 'r.country',
                    'choices' => ['pl' => 'r.pl', 'de' => 'r.de']],
                ['name' => 'tags', 'widget' => 'checkboxes', 'label' => 'r.tags',
                    'choices' => ['urgent' => 'r.urgent', 'legal' => 'r.legal']],
            ]],
            ['name' => 'floor', 'widget' => 'number', 'label' => 'r.floor'],
            ['name' => 'terms', 'widget' => 'checkbox', 'label' => 'r.terms'],
            ['name' => 'seen', 'widget' => 'datetime', 'label' => 'r.seen'],
            ['name' => 'note', 'widget' => 'text', 'label' => 'r.note'],
            ['name' => 'scan', 'widget' => 'signature', 'label' => 'r.scan'],
            ['name' => 'lines', 'widget' => 'table', 'label' => 'r.lines', 'columns' => ['sku'], 'items' => [
                ['name' => 'sku', 'widget' => 'text', 'label' => 'r.sku'],
                ['name' => 'unit', 'widget' => 'select', 'label' => 'r.unit',
                    'choices' => ['pc' => 'r.pc', 'kg' => 'r.kg']],
            ]],
            ['widget' => 'confirm', 'label' => 'r.send'],
        ],
        'translations' => [
            'en' => [
                'r.heading' => 'A ticket',
                'r.title' => 'What happened',
                'r.about' => 'About it',
                'r.country' => 'Country',
                'r.pl' => 'Poland',
                'r.de' => 'Germany',
                'r.tags' => 'Tags',
                'r.urgent' => 'Urgent',
                'r.legal' => 'Legal',
                'r.floor' => 'Which floor',
                'r.terms' => 'Terms',
                'r.seen' => 'Seen at',
                'r.note' => 'Anything else',
                'r.scan' => 'Your signature',
                'r.lines' => 'Lines',
                'r.sku' => 'SKU',
                'r.unit' => 'Unit',
                'r.pc' => 'Pieces',
                'r.kg' => 'Kilograms',
                'r.send' => 'Send it',
            ],
            'pl' => ['r.title' => 'Co się stało', 'r.de' => 'Niemcy'],
        ],
    ];
}

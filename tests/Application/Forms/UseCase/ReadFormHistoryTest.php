<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\RevisionNotFound;
use App\Application\Forms\UseCase\ReadFormHistory;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\InMemoryFormHistory;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * Reading what a form used to hold. Two things are being pinned: that history
 * answers to the same rules as everything else about a form, and the one thing
 * this use case *derives* rather than reads — which save a confirmation locked.
 */
final class ReadFormHistoryTest extends TestCase
{
    public function testTheSavesComeBackNewestFirst(): void
    {
        // GIVEN a form saved twice
        $forms = new InMemoryForms();
        $history = new InMemoryFormHistory();
        $id = self::plant($forms);
        $history->append($id, '{"email":"ada@example.com"}');
        $history->append($id, '{"email":"eve@example.com"}');

        // WHEN
        $revisions = new ReadFormHistory($forms, $history)($id);

        // THEN the last thing that happened is the first thing offered: what
        // somebody looks for in a history is almost always its last few moments
        self::assertSame([2, 1], array_map(static fn(object $r): int => $r->seq, $revisions));
        self::assertSame([false, false], array_map(static fn(object $r): bool => $r->confirmed, $revisions));
    }

    public function testAFormNobodyFilledInHasNoHistory(): void
    {
        // GIVEN / WHEN / THEN — and not an error: nothing happened yet
        $forms = new InMemoryForms();

        self::assertSame([], new ReadFormHistory($forms, new InMemoryFormHistory())(self::plant($forms)));
    }

    public function testWhatAConfirmationLockedIsSaidRatherThanStored(): void
    {
        // GIVEN a form saved twice and then confirmed
        $forms = new InMemoryForms();
        $history = new InMemoryFormHistory();
        $id = self::plant($forms);
        $history->append($id, '{"email":"ada@example.com"}');
        $history->append($id, '{"email":"eve@example.com"}');
        $form = $forms->get($id);
        $form->saveDraft(json_decode('{"email":"eve@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $revisions = new ReadFormHistory($forms, $history)($id);

        // THEN the last save is the one that got locked — which is the first of
        // these. Confirming stores no values, so it is no revision of its own, and
        // a stored marker would be a second copy of `confirmed_at`
        self::assertSame([true, false], array_map(static fn(object $r): bool => $r->confirmed, $revisions));
    }

    public function testOneSaveComesBackAsTheTextItWasStoredAs(): void
    {
        // GIVEN
        $forms = new InMemoryForms();
        $history = new InMemoryFormHistory();
        $id = self::plant($forms);
        $history->append($id, '{"email":"ada@example.com"}');

        // WHEN / THEN byte for byte, exactly as the current values are served
        self::assertSame('{"email":"ada@example.com"}', new ReadFormHistory($forms, $history)->document($id, 1));
    }

    public function testASaveThatNeverHappenedIsNotThere(): void
    {
        // GIVEN a form saved once
        $forms = new InMemoryForms();
        $history = new InMemoryFormHistory();
        $id = self::plant($forms);
        $history->append($id, '{"email":"ada@example.com"}');

        // WHEN / THEN
        $this->expectException(RevisionNotFound::class);

        new ReadFormHistory($forms, $history)->document($id, 2);
    }

    public function testAnUnknownFormHasNoHistoryToRead(): void
    {
        // GIVEN / WHEN / THEN the form is read first, so history answers to the
        // same rules as everything else
        $this->expectException(FormNotFound::class);

        new ReadFormHistory(new InMemoryForms(), new InMemoryFormHistory())(FormId::next());
    }

    public function testAnExpiredFormIsGoneHereToo(): void
    {
        // GIVEN a form past its date, which did save something once
        $forms = new InMemoryForms();
        $history = new InMemoryFormHistory();
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 day'))));
        $history->append($id, '{"email":"ada@example.com"}');

        // WHEN / THEN a history is not a way to read a form the API treats as gone
        $this->expectException(FormGone::class);

        new ReadFormHistory($forms, $history)($id);
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored('{"items":[{"type":"text","name":"email"}]}', new SpyParser());
    }
}

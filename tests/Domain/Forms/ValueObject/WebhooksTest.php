<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\Exception\WebhookNotValid;
use App\Domain\Forms\ValueObject\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * Where a form reports itself, and what may not be an endpoint.
 *
 * The rules are few and each of them is here because of what it prevents: a
 * relative address would be resolved against whatever this service thinks it
 * is, an empty string is a client that meant something and sent nothing, and a
 * scheme nothing listens on is a form that will never be told about.
 */
final class WebhooksTest extends TestCase
{
    public function testAFormTellsNobodyUnlessSomebodySaidOtherwise(): void
    {
        // GIVEN / WHEN
        $none = Webhooks::none();

        // THEN both are absent, and the form knows it owes nobody anything —
        // which is what keeps the queue empty in a deployment that does not use
        // this at all
        self::assertNull($none->created);
        self::assertNull($none->save);
        self::assertNull($none->confirm);
        self::assertNull($none->deleted);
        self::assertFalse($none->any());
    }

    public function testEachEventIsToldIndependently(): void
    {
        // GIVEN a form that only cares about being finished
        $confirmOnly = Webhooks::of(null, 'https://example.test/confirmed');

        // THEN nothing is told about a save, somebody is told about a
        // confirmation, and the form owes something
        self::assertNull($confirmOnly->save);
        self::assertSame('https://example.test/confirmed', $confirmOnly->confirm);
        self::assertTrue($confirmOnly->any());

        // AND the other way round, which is a separate claim rather than the
        // same one mirrored: two members that share one check would be one
        // member
        $saveOnly = Webhooks::of('http://example.test/saved', null);
        self::assertSame('http://example.test/saved', $saveOnly->save);
        self::assertNull($saveOnly->confirm);
        self::assertTrue($saveOnly->any());
    }

    public function testAllAtOnceIsFourAddresses(): void
    {
        // GIVEN
        $all = Webhooks::of(
            'https://a.test/saved',
            'https://b.test/confirmed',
            'https://c.test/deleted',
            'https://d.test/created',
        );

        // THEN none is the other: a form may report its four events to four
        // different systems
        self::assertSame('https://d.test/created', $all->created);
        self::assertSame('https://a.test/saved', $all->save);
        self::assertSame('https://b.test/confirmed', $all->confirm);
        self::assertSame('https://c.test/deleted', $all->deleted);
        self::assertTrue($all->any());
    }

    public function testReportingOnlyThatItExistsIsEnoughToOweSomebody(): void
    {
        // GIVEN a form whose receiver only mirrors what exists — the case this
        // endpoint was added for, since the creator learns nothing from it
        $announced = Webhooks::of(null, null, null, 'https://d.test/created');

        // THEN
        self::assertSame('https://d.test/created', $announced->created);
        self::assertNull($announced->save);
        self::assertTrue($announced->any());
    }

    public function testReportingOnlyItsOwnDisappearanceIsEnoughToOweSomebody(): void
    {
        // GIVEN a form that says nothing about saves or confirmations — the case
        // this endpoint exists for is the purge, which nobody asks for
        $gone = Webhooks::of(null, null, 'https://c.test/deleted');

        // THEN
        self::assertNull($gone->save);
        self::assertNull($gone->confirm);
        self::assertSame('https://c.test/deleted', $gone->deleted);
        self::assertTrue($gone->any());
    }

    /**
     * @param non-empty-string $said
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function testAnAddressNothingCouldBeToldAtIsRefused(string $said, string $code): void
    {
        // GIVEN an endpoint that cannot be one
        // WHEN it is offered as the save endpoint
        try {
            Webhooks::of($said, null);
            self::fail(\sprintf('"%s" was accepted as an endpoint.', $said));
        } catch (WebhookNotValid $refused) {
            // THEN it is refused, saying which member and why
            self::assertSame('save', $refused->member);
            self::assertSame($code, $refused->refusal);
        }

        // AND the same address is refused in the other members, pointed at each
        // of them: a rule that only held for one of the three would be a rule a
        // client could walk around
        foreach ([
            'confirm' => static fn(string $one): Webhooks => Webhooks::of(null, $one),
            'deleted' => static fn(string $one): Webhooks => Webhooks::of(null, null, $one),
            'created' => static fn(string $one): Webhooks => Webhooks::of(null, null, null, $one),
        ] as $member => $offer) {
            try {
                $offer($said);
                self::fail(\sprintf('"%s" was accepted as the %s endpoint.', $said, $member));
            } catch (WebhookNotValid $refused) {
                self::assertSame($member, $refused->member);
                self::assertSame($code, $refused->refusal);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusable(): iterable
    {
        yield 'empty' => ['', 'form.webhook.empty'];
        yield 'whitespace only' => ["  \t ", 'form.webhook.empty'];
        yield 'no scheme' => ['example.test/hook', 'form.webhook.not_a_url'];
        yield 'relative' => ['/hook', 'form.webhook.not_a_url'];
        yield 'a scheme nothing listens on' => ['ftp://example.test/hook', 'form.webhook.not_a_url'];
        yield 'a file' => ['file:///etc/passwd', 'form.webhook.not_a_url'];
        yield 'scheme with no host' => ['https://', 'form.webhook.not_a_url'];
        yield 'longer than a column' => ['https://example.test/' . str_repeat('a', Webhooks::MAX_LENGTH), 'form.webhook.too_long'];
    }

    public function testAnAddressExactlyAsLongAsAllowedIsAccepted(): void
    {
        // GIVEN an address of exactly the maximum length — the limit itself,
        // which the refused side alone would leave unpinned
        $prefix = 'https://example.test/';
        $said = $prefix . str_repeat('a', Webhooks::MAX_LENGTH - \strlen($prefix));
        self::assertSame(Webhooks::MAX_LENGTH, mb_strlen($said));

        // WHEN / THEN it is kept as it was given
        self::assertSame($said, Webhooks::of($said, null)->save);
    }

    public function testTheLimitIsCountedInCharactersRatherThanBytes(): void
    {
        // GIVEN an address at exactly the limit whose characters are two bytes
        // each — a host or a path with a non-ASCII character in it
        $prefix = 'https://example.test/';
        $said = $prefix . str_repeat('ä', Webhooks::MAX_LENGTH - \strlen($prefix));
        self::assertSame(Webhooks::MAX_LENGTH, mb_strlen($said));
        self::assertGreaterThan(Webhooks::MAX_LENGTH, \strlen($said));

        // WHEN / THEN it is accepted: the limit is about the address, not about
        // how many bytes it happens to take
        self::assertSame($said, Webhooks::of($said, null)->save);
    }

    public function testTheRefusalSaysWhichMemberAndWhyInItsMessageToo(): void
    {
        // GIVEN an endpoint that cannot be one
        // WHEN it is refused
        try {
            Webhooks::of(null, 'ftp://example.test/hook');
            self::fail('An ftp endpoint was accepted.');
        } catch (WebhookNotValid $refused) {
            // THEN the sentence says both, so a log line is worth reading on its
            // own — the two properties are for code, the message is for people
            self::assertSame(
                'The confirm webhook is not a usable endpoint (form.webhook.not_a_url).',
                $refused->getMessage(),
            );
        }
    }

    public function testAnAddressIsKeptExactlyAsItWasGiven(): void
    {
        // GIVEN an address with everything an address may have
        $said = 'https://user:pass@example.test:8443/hooks/forms?tenant=7#part';

        // WHEN / THEN nothing is normalized, trimmed or re-encoded: what a
        // deployment wrote is what a notification is posted to
        self::assertSame($said, Webhooks::of($said, $said)->save);
        self::assertSame($said, Webhooks::of($said, $said)->confirm);
    }

    public function testWhatWasStoredIsJudgedAgainOnTheWayOut(): void
    {
        // GIVEN a row holding something no client could have sent
        // WHEN it is read back
        // THEN it is refused rather than used: a form that would report itself
        // to whatever that is should refuse to be read instead
        $this->expectException(WebhookNotValid::class);
        Webhooks::stored('javascript:alert(1)', null);
    }

    public function testWhatWasStoredIsOtherwiseTheSameThingBack(): void
    {
        // GIVEN two endpoints that were accepted once
        // WHEN read back
        $stored = Webhooks::stored('https://a.test/saved', 'https://b.test/confirmed');

        // THEN they are what they were
        self::assertSame('https://a.test/saved', $stored->save);
        self::assertSame('https://b.test/confirmed', $stored->confirm);
    }
}

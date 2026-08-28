<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What may be an identity here, and what may not.
 *
 * The rules are about transport and trust rather than about shape: there is no
 * format to check, so what is checked is what would otherwise let a newline into
 * a header, a byte string into a log line, or one identifier turn into another on
 * the way in.
 */
final class ActorTest extends TestCase
{
    /**
     * @return \Generator<string, array{string}>
     */
    public static function acceptable(): \Generator
    {
        yield 'an OIDC subject' => ['248289761001'];
        yield 'a uuid' => ['01a04822-17f3-71f2-b99e-352b7a72bee2'];
        yield 'an email address' => ['ada@example.com'];
        yield 'a namespaced one, which is how two identity sources are kept apart' => ['sso:12345'];
        // Nothing about a subject is ASCII-only in the model. The *header* is,
        // because a proxy chain is not, and that is the intake's rule rather than
        // this one's.
        yield 'a name outside ASCII' => ['Żółw'];
        yield 'one character' => ['x'];
        yield 'exactly as long as it may be' => [str_repeat('a', Actor::MAX_LENGTH)];
        // 255 *characters*, and twice that many bytes. The cap is on what a
        // person's identifier is, not on how the encoding happens to store it —
        // measuring bytes here would refuse a legal subject in half the world's
        // alphabets.
        yield 'as long as it may be, outside ASCII' => [str_repeat('ż', Actor::MAX_LENGTH)];
        // Refused nowhere: a comma is legal in a subject as far as the model is
        // concerned. It is the header that cannot carry one, because a folded
        // header looks exactly like this.
        yield 'a certificate subject' => ['CN=ada,OU=people'];
    }

    #[DataProvider('acceptable')]
    public function testAnIdentityIsKeptExactlyAsItWasAsserted(string $subject): void
    {
        // GIVEN / WHEN
        $actor = Actor::of($subject);

        // THEN nothing was normalized, trimmed or rewritten on the way in: the
        // only operation this service performs on a subject is comparison, and
        // two spellings of one name are two people precisely because nothing
        // here decides they are not
        self::assertSame($subject, (string) $actor);
        self::assertNull(Actor::whatIsWrongWith($subject));
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function refused(): \Generator
    {
        yield 'empty' => ['', 'it is empty'];
        yield 'longer than the cap' => [str_repeat('a', Actor::MAX_LENGTH + 1), 'it is longer than 255 characters'];
        yield 'a newline, which in a header is header injection' => ["ada\nSet-Cookie: x", 'it contains a control character'];
        yield 'a carriage return' => ["ada\r", 'it contains a control character'];
        yield 'a NUL' => ["ada\0", 'it contains a control character'];
        yield 'a C1 control character' => ["ada\u{0085}", 'it contains a control character'];
        yield 'a leading space' => [' ada', 'it begins or ends with whitespace'];
        yield 'a trailing space' => ['ada ', 'it begins or ends with whitespace'];
        yield 'a trailing tab' => ["ada\t", 'it contains a control character'];
        yield 'bytes that are not UTF-8' => ["ada\xC3", 'it is not valid UTF-8'];
    }

    #[DataProvider('refused')]
    public function testWhatCannotBeAnIdentitySaysWhyWithoutRepeatingIt(string $subject, string $because): void
    {
        // GIVEN / WHEN asking what is wrong with it
        // THEN the reason is the one that matters, and it never carries the value
        // — a subject may be somebody's email address, and this travels into a
        // response and into logs
        self::assertSame($because, Actor::whatIsWrongWith($subject));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('That is not an identity: %s.', $because));

        Actor::of($subject);
    }

    public function testARefusalNeverEchoesWhatWasRefused(): void
    {
        // GIVEN something unusable that a reader must not learn from a message
        $secretish = "ada@example.com\nX-Injected: 1";

        // WHEN it is refused
        try {
            Actor::of($secretish);
            self::fail('That should not be an identity.');
        } catch (\InvalidArgumentException $refusal) {
            // THEN the message says what was wrong and not what it was
            self::assertStringNotContainsString('ada@example.com', $refusal->getMessage());
        }
    }

    public function testAStoredIdentityIsNotJudgedAgain(): void
    {
        // GIVEN a subject that today's rules would refuse — a row written before
        // the cap was what it is now, say
        $tooLong = str_repeat('a', Actor::MAX_LENGTH + 1);

        // WHEN it is read back
        $actor = Actor::stored($tooLong);

        // THEN it reads: tightening a rule must not make yesterday's rows
        // unreadable, exactly as a stored definition is not parsed again to be
        // served
        self::assertSame($tooLong, (string) $actor);
    }

    public function testTwoIdentitiesAreTheSameOnlyWhenTheStringIs(): void
    {
        // GIVEN / WHEN / THEN
        self::assertTrue(Actor::of('ada')->equals(Actor::of('ada')));
        self::assertFalse(Actor::of('ada')->equals(Actor::of('Ada')));
        // Namespacing is what a deployment with two identity sources does, and
        // this is why it works: nothing here folds the prefix away.
        self::assertFalse(Actor::of('sso:1')->equals(Actor::of('ldap:1')));
    }
}

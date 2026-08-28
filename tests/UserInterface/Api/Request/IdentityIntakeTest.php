<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api\Request;

use App\Domain\Forms\ValueObject\Actor;
use App\UserInterface\Api\Problem\ProblemException;
use App\UserInterface\Api\Request\IdentityIntake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Uid\Uuid;

/**
 * The boundary identity crosses, and what it refuses on the way.
 *
 * Two things are being pinned here and they are not the same. One is that a
 * header nobody can vouch for is *ignored* — with no trusted proxy configured
 * there is nothing to believe, and believing it anyway would make every row
 * written afterwards worthless in a way no later fix can repair. The other is
 * that a header which is there and unusable is *refused* rather than quietly
 * replaced by the fallback: falling back would let the save through attributed to
 * the wrong person and hide the misconfiguration for months.
 */
final class IdentityIntakeTest extends TestCase
{
    /** @var array<string> */
    private array $proxiesBefore = [];

    /** @var int<0, 63> */
    private int $trustedHeadersBefore = 0;

    protected function setUp(): void
    {
        // Symfony keeps the trusted-proxy list in static state, so it is borrowed
        // and put back rather than assumed.
        $this->proxiesBefore = Request::getTrustedProxies();
        /** @var int<0, 63> $headers */
        $headers = Request::getTrustedHeaderSet();
        $this->trustedHeadersBefore = $headers;
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->proxiesBefore, $this->trustedHeadersBefore);
    }

    public function testWhatAProxyAssertsIsWhoIsCalling(): void
    {
        // GIVEN a deployment that says where its proxy is
        self::trust();

        // WHEN a request arrives from there carrying an identity
        $actor = self::resolve(self::from('127.0.0.1', 'ada@example.com'));

        // THEN that is the caller, exactly as asserted
        self::assertInstanceOf(Actor::class, $actor);
        self::assertSame('ada@example.com', (string) $actor);
    }

    public function testAHeaderFromNowhereInParticularIsIgnoredEntirely(): void
    {
        // GIVEN a deployment that has not said where its proxy is — which is the
        // state every deployment starts in
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        // WHEN a request arrives asserting whatever it likes
        $actor = self::resolve(self::from('10.9.9.9', 'administrator'));

        // THEN nobody was asserted. This is the one irreversible detail in the
        // whole design: rows written under a header any client could set cannot
        // be repaired afterwards, or even told apart from the good ones — so with
        // nothing to believe, the header is not read at all.
        self::assertNull($actor);
    }

    public function testAnUnbelievableHeaderDoesNotEvenReachTheFallback(): void
    {
        // GIVEN a deployment with a fallback and no trusted proxy
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        // WHEN a request arrives asserting somebody
        $actor = self::resolve(self::from('10.9.9.9', 'administrator'), 'unattributed');

        // THEN the fallback answers, and what the request claimed is nowhere
        self::assertSame('unattributed', (string) $actor);
    }

    public function testNothingAssertedFallsBackWhenAFallbackIsConfigured(): void
    {
        // GIVEN a deployment that configured one
        self::trust();

        // WHEN a request arrives from the proxy with no identity on it
        $actor = self::resolve(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']), 'unattributed');

        // THEN that is who the save is attributed to — a reserved value that
        // reads as "nobody told us" rather than as somebody's name
        self::assertSame('unattributed', (string) $actor);
    }

    public function testNothingAssertedAndNoFallbackIsNobody(): void
    {
        // GIVEN a deployment that configured none
        self::trust();

        // WHEN a request arrives with no identity
        $actor = self::resolve(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']));

        // THEN nobody is resolved, and it is the *form* that decides whether that
        // is allowed: one that records who fills it in refuses the save, and one
        // that records nobody never wanted an answer here
        self::assertNull($actor);
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function unusable(): \Generator
    {
        yield 'a newline, which is header injection' => ["ada\nX-Injected: 1", 'it contains a control character'];
        yield 'empty' => ['', 'it is empty'];
        yield 'longer than a subject may be' => [str_repeat('a', Actor::MAX_LENGTH + 1), 'it is longer than 255 characters'];
        yield 'padded, which would be a different identifier if trimmed' => [' ada', 'it begins or ends with whitespace'];
    }

    #[DataProvider('unusable')]
    public function testAnAssertionThatCannotBeAnIdentityIsRefusedRatherThanReplaced(string $header, string $because): void
    {
        // GIVEN a proxy to believe, and a fallback that must not be reached
        self::trust();

        // WHEN it asserts something unusable
        try {
            self::resolve(self::from('127.0.0.1', $header), 'unattributed');
            self::fail('That should have been refused.');
        } catch (ProblemException $refusal) {
            // THEN the request is refused: a header that is there and broken means
            // something upstream is wrong, and answering "nobody" would record the
            // fallback and hide it
            self::assertSame(400, $refusal->status);
            self::assertSame('identity-not-valid', $refusal->type);
            self::assertStringContainsString($because, $refusal->getMessage() . (string) $refusal->detail);
            // AND the value never travels into the message, because a subject can
            // be somebody's email address
            if ($header !== '') {
                self::assertStringNotContainsString($header, (string) $refusal->detail);
            }
        }
    }

    public function testAFoldedHeaderIsRefusedBecauseItCannotBeToldFromASubject(): void
    {
        // GIVEN a proxy that appends instead of replacing, or a client copy that
        // survived: PHP folds repeated headers into one comma-joined value, and by
        // the time it is here "a, b" looks exactly like a subject somebody chose
        self::trust();

        // WHEN that arrives
        try {
            self::resolve(self::from('127.0.0.1', 'attacker, ada'));
            self::fail('That should have been refused.');
        } catch (ProblemException $refusal) {
            // THEN it is refused rather than recorded as a person. A deployment
            // whose subjects contain commas — a certificate DN — namespaces or
            // encodes them, which is cheaper than this being an identity.
            self::assertSame(400, $refusal->status);
            self::assertStringContainsString('comma', (string) $refusal->detail);
        }
    }

    public function testTheSameHeaderTwiceIsNotAnAssertionAboutAnybody(): void
    {
        // GIVEN a request where the two copies survived separately
        self::trust();
        $request = Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set(strtolower(IdentityIntake::HEADER), ['ada', 'attacker']);

        // WHEN it is read
        $this->expectException(ProblemException::class);

        // THEN it is refused
        self::resolve($request);
    }

    public function testAFallbackThatCannotBeAnIdentityIsSomebodysFirstFailure(): void
    {
        // GIVEN a deployment that configured a broken one
        // WHEN the intake is built
        // THEN it says so there and then, rather than on whichever save happens
        // to arrive without a header
        $this->expectException(\InvalidArgumentException::class);

        new IdentityIntake("nobody\n");
    }

    public function testItResolvesNothingButAnIdentity(): void
    {
        // GIVEN an action argument of another type entirely
        self::trust();

        // WHEN / THEN this intake has nothing to say about it, so the resolver
        // chain moves on
        $resolved = new IdentityIntake()->resolve(
            self::from('127.0.0.1', 'ada'),
            new ArgumentMetadata('id', Uuid::class, false, false, null),
        );

        self::assertSame([], [...$resolved]);
    }

    private static function trust(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    }

    private static function from(string $address, string $identity): Request
    {
        return Request::create('/', server: [
            'REMOTE_ADDR' => $address,
            'HTTP_' . str_replace('-', '_', strtoupper(IdentityIntake::HEADER)) => $identity,
        ]);
    }

    private static function resolve(Request $request, ?string $fallback = null): ?Actor
    {
        $resolved = [...new IdentityIntake($fallback)->resolve(
            $request,
            new ArgumentMetadata('actor', Actor::class, false, false, null, true),
        )];

        // Exactly one value, and it is either somebody or nobody — an intake that
        // resolved nothing would leave the action's argument unfilled instead.
        self::assertCount(1, $resolved);

        return $resolved[0];
    }
}

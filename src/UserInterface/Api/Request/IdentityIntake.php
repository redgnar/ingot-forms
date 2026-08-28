<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\Domain\Forms\ValueObject\Actor;
use App\UserInterface\Api\Problem\ProblemException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Who is calling, as an argument an action can declare.
 *
 * This service does not authenticate anybody and never will: a gateway does that
 * and asserts the answer in a header. All that happens here is the *intake* —
 * read it, judge it as transport, and hand an `Actor` to the action, which passes
 * it to a use case as an argument. No ambient state and no holder service: the
 * only thing that can attribute a write is what was resolved for that one
 * request.
 *
 * **The header is read only from a configured trusted proxy**, and that is the
 * one irreversible detail in this whole design. If anybody can set it, the audit
 * trail is written by the party being audited — and rows recorded under a
 * forgeable header can never be repaired, or even told apart from the good ones.
 * So with `framework.trusted_proxies` unset the header is ignored *entirely*,
 * which fails safe in the loud direction: a form that records who fills it in
 * refuses every save until the deployment says where its proxy is.
 *
 * Symfony's trusted-proxy machinery only sanitises `X-Forwarded-*`, so consulting
 * it for a header of our own is this intake's own decision. It reuses the address
 * list rather than keeping a second one, because two lists of the same addresses
 * are two things that drift.
 */
final class IdentityIntake implements ValueResolverInterface
{
    /**
     * What a proxy asserts. A name of ours rather than one of the ecosystem's
     * (`X-Auth-Request-User` and friends) because a deployment must configure its
     * proxy to *set* this one and strip whatever a client sent — and a name
     * nobody else uses is a name no other component fills in by accident.
     */
    public const string HEADER = 'X-Forms-Identity';

    private readonly ?Actor $fallback;

    public function __construct(
        #[Autowire(param: 'forms.identity_fallback')]
        ?string $fallback = null,
    ) {
        // Judged here rather than on the request that needs it: a bad value in
        // configuration should be somebody's first failure, not a surprise on
        // whichever save happens to arrive without a header.
        $this->fallback = $fallback === null || $fallback === '' ? null : Actor::of($fallback);
    }

    /**
     * @return iterable<Actor|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Actor::class) {
            return [];
        }

        return [$this->of($request)];
    }

    /**
     * The asserted identity, the configured fallback, or nobody — in that order.
     *
     * @throws ProblemException when something was asserted and it cannot be one
     */
    private function of(Request $request): ?Actor
    {
        $asserted = $this->asserted($request);

        if ($asserted === null) {
            return $this->fallback;
        }

        $wrong = Actor::whatIsWrongWith($asserted);

        if ($wrong !== null) {
            // A refusal, not a quiet fall back to the fallback: a header that is
            // there and unusable means something upstream is wrong, and falling
            // back would let the save through attributed to the wrong person and
            // hide the misconfiguration for months.
            throw self::refuse($wrong);
        }

        return Actor::of($asserted);
    }

    /**
     * What the proxy said, or null when there is no proxy to believe or it said
     * nothing.
     *
     * @throws ProblemException when the header arrived more than once
     */
    private function asserted(Request $request): ?string
    {
        if (!$request->isFromTrustedProxy()) {
            return null;
        }

        $values = $request->headers->all(strtolower(self::HEADER));

        if ($values === []) {
            return null;
        }

        // Two of them means either a proxy that appends instead of replacing, or
        // a client copy that survived — and either way the value is not an
        // assertion about anybody.
        if (\count($values) > 1) {
            throw self::refuse('it arrived more than once');
        }

        $value = $values[0] ?? null;

        if (!\is_string($value)) {
            return null;
        }

        // PHP folds repeated headers into one comma-joined value, so by the time
        // it is here `a, b` looks exactly like a subject somebody chose. There is
        // no way to tell the two apart, so a comma is refused: a deployment whose
        // subjects contain one (a certificate DN, say) encodes or namespaces them,
        // which is cheaper than a folded header being recorded as a person.
        if (str_contains($value, ',')) {
            throw self::refuse('it contains a comma, which cannot be told apart from two headers folded into one');
        }

        return $value;
    }

    /**
     * The value is never in the message. A subject may be somebody's email
     * address, and this travels into a response and into logs.
     */
    private static function refuse(string $because): ProblemException
    {
        return new ProblemException(
            400,
            'identity-not-valid',
            'The asserted identity cannot be read.',
            \sprintf('The %s header was rejected: %s.', self::HEADER, $because),
        );
    }
}

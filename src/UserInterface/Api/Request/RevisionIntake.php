<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\Domain\Forms\ValueObject\ExpectedRevision;
use App\UserInterface\Api\Problem\ProblemException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * What the caller believes the form is at, as an argument an action can declare.
 *
 * It arrives in `If-Match`, which is the header HTTP already has for exactly this
 * — "replace this only if it is still what I read" — and the validator is the
 * form's revision, served as the `ETag` of `GET /api/forms/{id}/data`. So the
 * whole exchange is one a generic client already knows how to make: read, keep
 * the tag, hand it back, and be told `412` rather than overwriting a document
 * somebody else saved in between.
 *
 * A header, not a body member, and not by preference: the body of a save **is**
 * the values document, stored byte for byte. There is nowhere in it to put a
 * revision that would not become part of somebody's form.
 *
 * Three shapes are read, because all three are legal and refusing a legal
 * request is worse than reading it:
 *
 *   If-Match: "7"        this revision
 *   If-Match: "7", "8"   any of them
 *   If-Match: *          any revision, as long as the form is there — which is
 *                        what every request here already requires, so it asks
 *                        for nothing extra and resolves to no expectation
 *
 * `W/"7"` is refused rather than accepted-as-`"7"`: a weak validator explicitly
 * means "close enough to be equivalent for display", and a client that sent one
 * is asking for a comparison this endpoint does not make.
 */
final class RevisionIntake implements ValueResolverInterface
{
    /**
     * @return iterable<ExpectedRevision|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== ExpectedRevision::class) {
            return [];
        }

        return [$this->of($request)];
    }

    /**
     * @throws ProblemException when the header is there and is not a revision
     */
    private function of(Request $request): ?ExpectedRevision
    {
        $header = $request->headers->get('If-Match');

        if ($header === null || trim($header) === '') {
            return null;
        }

        if (trim($header) === '*') {
            return null;
        }

        $revisions = [];

        foreach (explode(',', $header) as $candidate) {
            $revisions[] = self::revision(trim($candidate));
        }

        return ExpectedRevision::of(...$revisions);
    }

    /**
     * @throws ProblemException
     */
    private static function revision(string $candidate): int
    {
        // A quoted, non-negative integer and nothing else. Deliberately strict:
        // an unquoted `7` is not an entity tag, and a client sending one has read
        // this endpoint's contract wrong in a way that would otherwise only show
        // up as a save that was refused for a reason nobody could see.
        if (preg_match('/^"(\d{1,18})"$/', $candidate, $matched) !== 1) {
            throw new ProblemException(
                400,
                'precondition-not-readable',
                'The If-Match header cannot be read.',
                \sprintf(
                    'Expected a quoted revision, several of them, or "*" — for example If-Match: "7". Got: %s.',
                    $candidate,
                ),
            );
        }

        return (int) $matched[1];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * Who did something to a form: one opaque string, asserted by whatever
 * authenticated the caller, and **never resolved into a person**.
 *
 * This service has no accounts, no user store and no directory, and it acquires
 * none by recording this. The only operation performed on a subject is `===`:
 * nothing here parses it, looks it up, or asks what it means. A deployment whose
 * subjects could come from two identity sources namespaces them itself
 * (`sso:12345`), which is why there is no `issuer` member — zero cost today, the
 * option preserved, and no migration if it is ever wanted.
 *
 * The rules below are about **transport and trust**, not about shape. An opaque
 * string is easy to under-validate — there is no format to check, so the
 * temptation is to check nothing — and an unvalidated one is how a newline ends
 * up in a header and how an audit trail becomes something the audited party can
 * write. What is deliberately *not* done is normalizing: normalizing is
 * interpreting, so two Unicode spellings of one name are two subjects here, and
 * a subject with a space at the end is refused rather than quietly trimmed into
 * a different identifier.
 */
final readonly class Actor implements \Stringable
{
    /**
     * What an OIDC `sub` is expected to fit in, what an email address fits in,
     * and what the `name` cap on a file descriptor already is here — a number
     * this codebase has chosen once already.
     */
    public const int MAX_LENGTH = 255;

    private function __construct(
        private string $subject,
    ) {}

    /**
     * An assertion on its way in, judged.
     *
     * The message never carries the value. A subject may be somebody's email
     * address, and an exception message travels into logs and, in the wrong
     * hands, into a response — so what is wrong is said, and what was wrong is
     * not.
     *
     * @throws \InvalidArgumentException when it is not one
     */
    public static function of(string $subject): self
    {
        $wrong = self::whatIsWrongWith($subject);

        if ($wrong !== null) {
            throw new \InvalidArgumentException(\sprintf('That is not an identity: %s.', $wrong));
        }

        return new self($subject);
    }

    /**
     * One that was stored, put back without judging it again — the way
     * {@see Definition::stored()} does. Tightening a rule here must not make
     * yesterday's rows unreadable: reading is not the moment to judge.
     */
    public static function stored(string $subject): self
    {
        return new self($subject);
    }

    /**
     * Why this cannot be an identity, or null when it can. Separate from the
     * constructor so a caller that reports rather than throws — an intake at the
     * boundary — can ask without catching.
     */
    public static function whatIsWrongWith(string $subject): ?string
    {
        return match (true) {
            !mb_check_encoding($subject, 'UTF-8') => 'it is not valid UTF-8',
            $subject === '' => 'it is empty',
            mb_strlen($subject) > self::MAX_LENGTH => \sprintf('it is longer than %d characters', self::MAX_LENGTH),
            // C0 and C1. The one character rule that is not taste: a newline in
            // a value read out of a header is header injection, and the same
            // value reaching a log line is log injection.
            preg_match('/\p{Cc}/u', $subject) === 1 => 'it contains a control character',
            // Refused rather than trimmed, because trimming would silently
            // change an identifier: " 42" and "42" really are two subjects.
            trim($subject) !== $subject => 'it begins or ends with whitespace',
            default => null,
        };
    }

    public function equals(self $other): bool
    {
        return $this->subject === $other->subject;
    }

    public function __toString(): string
    {
        return $this->subject;
    }
}

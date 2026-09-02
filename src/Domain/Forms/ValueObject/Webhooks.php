<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use App\Domain\Forms\Exception\WebhookNotValid;

/**
 * Where this form is to be reported, and for which of the two things that happen
 * to it.
 *
 * Given at creation and immutable afterwards, like everything else about a form:
 * an address that can drift is an address somebody has to reconcile, and a form
 * that has already been reported to one endpoint cannot honestly change its mind
 * about who was told. Changing either means delete and recreate, which is the
 * same answer the definition gives.
 *
 * All three are optional and independent. Telling nobody is the default and
 * costs nothing — a form that names no endpoint queues nothing at all, so a
 * deployment that does not use this pays for no table growth. Naming only
 * `confirm` is the common case: the system that owns the form usually cares that
 * somebody finished, not that they typed a line and stopped.
 *
 * `deleted` earns its place on the **expiry** path rather than on the delete
 * button. Whoever calls `DELETE /api/manage/forms/{id}` already knows it did —
 * that is the same argument that keeps `form.created` out of this — but
 * `app:forms:purge-expired` removes a form nobody asked about, and that is news
 * the owner cannot learn any other way: the form answers 410 and then stops
 * existing. Both paths report, and the notification says which.
 *
 * What is *sent* is a notification and never the values
 * ({@see \App\Application\Forms\Webhook\Announcement} is where that is argued),
 * so nothing here decides what a receiver learns — only who is told.
 */
final readonly class Webhooks
{
    /** Long enough for any real address, short enough to bound a column and a log line. */
    public const int MAX_LENGTH = 2000;

    private function __construct(
        /** Told when a draft save was accepted, or null. */
        public ?string $save,
        /** Told when the form was confirmed, or null. */
        public ?string $confirm,
        /** Told when the form ceased to exist — deleted, or reaped for having expired. */
        public ?string $deleted,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * @throws WebhookNotValid
     */
    public static function of(?string $save, ?string $confirm, ?string $deleted = null): self
    {
        return new self(
            self::url($save, 'save'),
            self::url($confirm, 'confirm'),
            self::url($deleted, 'deleted'),
        );
    }

    /**
     * What was stored, read back.
     *
     * Judged again rather than trusted: these came in through {@see of()}, so a
     * row that fails here is a row something else wrote — and a form that would
     * report itself to whatever that is should refuse to be read rather than go
     * ahead and do it.
     *
     * @throws WebhookNotValid
     */
    public static function stored(?string $save, ?string $confirm, ?string $deleted = null): self
    {
        return self::of($save, $confirm, $deleted);
    }

    /** Whether anybody is told anything at all. */
    public function any(): bool
    {
        return $this->save !== null || $this->confirm !== null || $this->deleted !== null;
    }

    /**
     * @throws WebhookNotValid
     */
    private static function url(?string $said, string $member): ?string
    {
        if ($said === null) {
            return null;
        }

        // An empty string is not "no endpoint": it is a client that meant
        // something and sent nothing, and guessing which would be the start of a
        // form reporting itself nowhere while its author believes otherwise.
        if (trim($said) === '') {
            throw new WebhookNotValid($member, 'form.webhook.empty');
        }

        if (mb_strlen($said) > self::MAX_LENGTH) {
            throw new WebhookNotValid($member, 'form.webhook.too_long');
        }

        $parts = parse_url($said);

        // Absolute, and one of the two schemes anything on the other side can
        // actually be listening on. A relative address would be resolved against
        // whatever this service thinks it is, which is the one thing it makes a
        // point of not knowing.
        if ($parts === false || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new WebhookNotValid($member, 'form.webhook.not_a_url');
        }

        return $said;
    }
}

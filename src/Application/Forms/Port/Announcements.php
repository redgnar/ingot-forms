<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Webhook\Announcement;
use App\Application\Forms\Webhook\Delivery;
use Symfony\Component\Uid\Uuid;

/**
 * What has happened to a form and has not been told yet.
 *
 * **The queue is written by the same transaction as the save it is about**, which
 * is the whole reason it exists as a table rather than as an HTTP call somewhere
 * in the request. Three things fall out of that and none of them can be had any
 * other way: a save is never slowed down or refused by somebody else's endpoint
 * being down, a delivery cannot be recorded for a save that then rolled back, and
 * a save cannot be committed without the delivery — which is exactly the bargain
 * `form_revisions` already makes with the row it belongs to.
 *
 * A queue holds what is still owed, so a delivery that succeeded is **removed**
 * rather than marked: the only rows that outlive their telling are the ones this
 * service gave up on, kept because a deployment should be able to see them. They
 * leave with the form, by foreign key, like every other record of it.
 *
 * Read side is deliberately blunt — `due()` takes no lock. Run one deliverer at a
 * time; two of them would each send what the other is sending, and the promise
 * here is at-least-once anyway ({@see Delivery} says what makes that harmless).
 */
interface Announcements
{
    /**
     * Puts one announcement in the queue, in the caller's transaction.
     *
     * Nothing is queued at all in a deployment that named no endpoint: a queue
     * for nobody is a table that grows for ever.
     */
    public function announce(Announcement $what): void;

    /**
     * What is owed now, oldest first, and never anything already given up on.
     *
     * @return list<Delivery>
     */
    public function due(\DateTimeImmutable $now, int $limit): array;

    /** Told, and therefore no longer owed. */
    public function told(Uuid $delivery): void;

    /** Refused, to be tried again then — with why, so a deployment can read it. */
    public function tellAgainAt(Uuid $delivery, \DateTimeImmutable $when, string $why): void;

    /** Refused too many times. Kept, visible, and never tried again. */
    public function giveUp(Uuid $delivery, string $why): void;
}

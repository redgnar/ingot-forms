<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Port\FormDeliveries;
use App\Application\Forms\Webhook\Announcement;
use App\Application\Forms\Webhook\Delivery;
use App\Application\Forms\Webhook\RecordedDelivery;
use App\Domain\Forms\ValueObject\FormId;
use Symfony\Component\Uid\Uuid;

/**
 * The queue, in memory, with the bookkeeping visible.
 *
 * What a use-case test is about here is the *settling*: told is gone, refused
 * comes back later with a reason, and one refused too often is left where
 * somebody can see it. So the three of those are recorded separately rather
 * than collapsed into one list.
 */
final class InMemoryAnnouncements implements Announcements, FormDeliveries
{
    /** @var array<string, Delivery> */
    public array $owed = [];

    /** @var list<string> ids, in the order they were told */
    public array $told = [];

    /** @var array<string, array{when: \DateTimeImmutable, why: string}> */
    public array $again = [];

    /** @var array<string, string> id => why */
    public array $abandoned = [];

    /** @var array<string, Delivery> every delivery this fake ever held, by id */
    private array $made = [];

    public function announce(Announcement $what): void
    {
        $id = Uuid::v7();
        $this->owed[(string) $id] = $this->made[(string) $id] = new Delivery($id, $what, 0);
    }

    /** Puts one in the queue that has already been refused a few times. */
    public function owe(Announcement $what, int $attempts = 0): Delivery
    {
        $delivery = new Delivery(Uuid::v7(), $what, $attempts);
        $this->owed[(string) $delivery->id] = $this->made[(string) $delivery->id] = $delivery;

        return $delivery;
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        return \array_slice(array_values($this->owed), 0, $limit);
    }

    public function told(Uuid $delivery): void
    {
        // Kept in `told` rather than thrown away, the way the real one keeps the
        // row: what was told cannot be untold, and a test about that has to be
        // able to look.
        // Gone from the queue, the way the real one drops the row: the fact of
        // having told somebody lives on the thing it was about.
        $this->told[] = (string) $delivery;
        unset($this->owed[(string) $delivery]);
    }

    public function tellAgainAt(Uuid $delivery, \DateTimeImmutable $when, string $why): void
    {
        $this->again[(string) $delivery] = ['when' => $when, 'why' => $why];
        unset($this->owed[(string) $delivery]);
    }

    public function giveUp(Uuid $delivery, string $why): void
    {
        $this->abandoned[(string) $delivery] = $why;
        unset($this->owed[(string) $delivery]);
    }

    /**
     * What this form still owes, the way the real adapter reads it back — kept as
     * one fake because one adapter answers both ports, and a second fake would be
     * free to disagree with this one about what is outstanding.
     */
    public function ofForm(FormId $form): array
    {
        $outstanding = [];

        foreach ($this->owed as $id => $delivery) {
            if ((string) $delivery->what->formId === (string) $form) {
                $outstanding[] = $this->recorded($id, $delivery, null);
            }
        }

        foreach ($this->abandoned as $id => $why) {
            $delivery = $this->made[$id] ?? null;

            if ($delivery !== null && (string) $delivery->what->formId === (string) $form) {
                $outstanding[] = $this->recorded($id, $delivery, $why);
            }
        }

        return $outstanding;
    }

    private function recorded(string $id, Delivery $delivery, ?string $why): RecordedDelivery
    {
        return new RecordedDelivery(
            $id,
            $delivery->what->event,
            $delivery->what->revision,
            $delivery->what->occurredAt,
            $delivery->what->target,
            $delivery->what->actor === null ? null : (string) $delivery->what->actor,
            $delivery->attempts,
            $why === null ? null : new \DateTimeImmutable(),
            $delivery->what->occurredAt,
            $why,
        );
    }
}

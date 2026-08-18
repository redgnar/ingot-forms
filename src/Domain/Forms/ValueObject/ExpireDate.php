<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * When a form stops being fillable — and the point past which its data is due
 * to leave the system.
 *
 * A form that expires in the past could never be filled in, so that state
 * cannot be constructed: {@see future()} refuses it. Reading a stored value
 * goes through {@see at()}, because yesterday's date is a fact once it is in
 * the database, not a mistake to reject.
 *
 * Always UTC: the column type carries no zone on most platforms.
 */
final readonly class ExpireDate implements \Stringable
{
    private function __construct(
        private \DateTimeImmutable $moment,
    ) {}

    /**
     * @throws \InvalidArgumentException when the moment has already passed
     */
    public static function future(\DateTimeImmutable $moment, ?\DateTimeImmutable $now = null): self
    {
        $date = self::at($moment);

        if ($date->hasPassed($now ?? new \DateTimeImmutable())) {
            throw new \InvalidArgumentException(\sprintf('%s is not in the future.', $date));
        }

        return $date;
    }

    /** A moment as recorded, whether or not it has passed. */
    public static function at(\DateTimeImmutable $moment): self
    {
        return new self($moment->setTimezone(new \DateTimeZone('UTC')));
    }

    public function hasPassed(\DateTimeImmutable $now): bool
    {
        return $this->moment <= $now;
    }

    public function toDateTime(): \DateTimeImmutable
    {
        return $this->moment;
    }

    public function __toString(): string
    {
        return $this->moment->format(\DateTimeInterface::ATOM);
    }
}

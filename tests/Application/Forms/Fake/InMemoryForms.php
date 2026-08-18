<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * The forms port without a database — same guarantees, kept in an array, so a
 * use case can be tested for what it orchestrates rather than what it stores.
 */
final class InMemoryForms implements FormRepository
{
    /** @var array<string, Form> */
    private array $forms = [];

    public int $saves = 0;

    /** @var list<string> ids read with the row lock, in order */
    public array $locked = [];

    public function add(Form $form): void
    {
        $this->forms[(string) $form->id()] = $form;
    }

    public function get(FormId $id): Form
    {
        $form = $this->forms[(string) $id] ?? throw new FormNotFound($id);

        if ($form->hasExpired(new \DateTimeImmutable())) {
            throw new FormGone($id);
        }

        return $form;
    }

    public function getForUpdate(FormId $id): Form
    {
        $this->locked[] = (string) $id;

        return $this->get($id);
    }

    public function remove(FormId $id): void
    {
        $this->get($id);
        unset($this->forms[(string) $id]);
    }

    public function save(): void
    {
        ++$this->saves;
    }

    public function purgeExpired(): int
    {
        $now = new \DateTimeImmutable();
        $purged = 0;

        foreach ($this->forms as $key => $form) {
            if ($form->hasExpired($now)) {
                unset($this->forms[$key]);
                ++$purged;
            }
        }

        return $purged;
    }
}

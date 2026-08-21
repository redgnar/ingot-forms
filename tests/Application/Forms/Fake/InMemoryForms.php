<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * The forms port without a database — same guarantees, kept in an array, so a
 * use case can be tested for what it orchestrates rather than what it stores.
 */
final class InMemoryForms implements FormRepository
{
    /** @var array<string, Form> */
    private array $forms = [];

    public int $adds = 0;

    public int $saves = 0;

    /** @var list<string> ids read with the row lock, in order */
    public array $locked = [];

    /** Set to make a cleanup read fail the way a stored document under new rules does. */
    public bool $unreadable = false;

    public function add(Form $form): void
    {
        $this->forms[(string) $form->id()] = $form;
        ++$this->adds;
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

    public function save(Form $form): void
    {
        $this->forms[(string) $form->id()] = $form;
        ++$this->saves;
    }

    public function expiredIds(int $limit): array
    {
        $now = new \DateTimeImmutable();
        $expired = [];

        foreach ($this->forms as $form) {
            if (\count($expired) >= $limit) {
                break;
            }

            if ($form->hasExpired($now)) {
                $expired[] = $form->id();
            }
        }

        return $expired;
    }

    public function removeExpired(FormId $id): void
    {
        $form = $this->forms[(string) $id] ?? null;

        if ($form !== null && $form->hasExpired(new \DateTimeImmutable())) {
            unset($this->forms[(string) $id]);
        }
    }

    public function getForCleanup(FormId $id): ?Form
    {
        if ($this->unreadable) {
            throw new FormUnreadable($id, ErrorReport::of(
                new MappingError(JsonPointer::fromString('/items/0/type'), 'mapping.unknown_variant', 'This type is no longer known.'),
            ));
        }

        $form = $this->forms[(string) $id] ?? null;

        if ($form === null) {
            return null;
        }

        // Locked like the real one: what a collector decides, it decides on a row
        // nothing else can move under it.
        $this->locked[] = (string) $id;

        return $form;
    }
}

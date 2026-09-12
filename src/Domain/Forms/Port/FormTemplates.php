<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * How the domain reaches the catalogue. A collection of templates, in the shape
 * {@see FormRepository} already established: add, read, read under a lock, write
 * back what changed.
 *
 * There is no listing here, and that is the same line `FormRepository` draws: a
 * catalogue page is a query with an order and a page size, which is a reading
 * port's business and not a collection's.
 */
interface FormTemplates
{
    public function add(FormTemplate $template): void;

    /**
     * @throws FormTemplateNotFound
     */
    public function get(FormTemplateId $id): FormTemplate;

    /**
     * The same read with the row locked until the surrounding transaction ends.
     *
     * Every write goes through it, and one of them needs it for a reason worth
     * naming: a version's number is `max + 1` over a history, so two publications
     * racing would hand out the same number twice. The lock is on the template
     * row — never on a document, which is shared and immutable and must never be
     * something a save queues behind.
     *
     * @throws FormTemplateNotFound
     */
    public function getForUpdate(FormTemplateId $id): FormTemplate;

    /** Persists what changed on the template handed over. */
    public function save(FormTemplate $template): void;
}

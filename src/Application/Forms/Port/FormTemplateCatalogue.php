<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Template\CataloguedTemplate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * The catalogue as something to browse, and the one number a template cannot
 * answer about itself.
 *
 * A reading port, like {@see FormHistory}: {@see \App\Domain\Forms\Port\FormTemplates}
 * is a collection of templates and a listing is a query, with an order and a
 * shape of its own.
 *
 * **There is no paging here, and that is a decision rather than an oversight.**
 * This service has no endpoint listing forms, deliberately — forms arrive by the
 * machine-load, and a list of them is somebody else's index. A catalogue is the
 * other kind of collection: every entry is one an administrator sat down and
 * made, so it is bounded by human effort. A limit and a cursor for a list of
 * tens would be machinery nobody asked for, and the day a deployment has
 * thousands of templates is a day something else has gone wrong.
 */
interface FormTemplateCatalogue
{
    /**
     * Every template, newest first.
     *
     * @return list<CataloguedTemplate>
     */
    public function all(): array;

    /**
     * How many forms are made of any version this template has ever published.
     *
     * Asked of the rows rather than kept as a count on the template, for the
     * reason a form's revision count is kept and this one is not: nothing is
     * judged against it, so a column would be a second truth to keep in step for
     * the sake of a number two endpoints show.
     */
    public function formsMadeFrom(FormTemplateId $template): int;

    /**
     * Some of those forms, at most this many — what a batch of emptying works
     * on.
     *
     * Bounded because a template may hold fifty thousand forms and a request
     * that tries to delete them all is one that times out half way with no way
     * to say what it did. The caller repeats until nothing comes back, which is
     * what makes emptying resumable and idempotent.
     *
     * @return list<FormId>
     */
    public function formIdsMadeFrom(FormTemplateId $template, int $limit): array;
}

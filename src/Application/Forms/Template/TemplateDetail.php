<?php

declare(strict_types=1);

namespace App\Application\Forms\Template;

/**
 * One template as its own address answers with it: what the catalogue lists,
 * plus the one number a listing deliberately does not carry.
 *
 * `forms` is what a delete is refused over, so it belongs where somebody can see
 * it before trying — and nowhere else: counting it for every row of the
 * catalogue would be one query per entry for a number nobody browsing is acting
 * on.
 */
final readonly class TemplateDetail
{
    public function __construct(
        public CataloguedTemplate $template,
        public int $forms,
    ) {}
}

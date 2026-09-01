<?php

declare(strict_types=1);

namespace App\UserInterface\Web;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where one form's API is, for the page that is a client of it.
 *
 * The pages write nothing of their own: what somebody types goes back through
 * `/api/forms/{id}/…` from the browser, exactly as any other client would send
 * it. So a kit needs addresses — and the one thing it must not do is *build*
 * them, because a string like `/api/forms/${id}/data` is a claim that this
 * service stands at the root of a host. It does, until somebody puts it behind a
 * gateway on a path of its own, and then every one of those strings is wrong
 * while the page still looks right: the form draws, and saving 404s.
 *
 * So every address comes from the router, which already knows both ways an
 * installation can move ({@see \App\Kernel}): the prefix its routes were built
 * under, and the one a trusted proxy asserts per request. This is the single
 * place that says which routes a page needs, handed to it as data
 * (`data-form-api-value`) like every other thing the browser is told.
 *
 * Two of the four are bases rather than whole addresses. A file and a revision
 * are sub-resources of them — `{files}/{fileId}`, `{history}/{seq}` — and the
 * page composes those itself, because it is the page that learns a file's id
 * from the upload it just made and a revision's number from the list it just
 * read. What it composes onto is still the router's answer.
 */
final readonly class FormApi
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {}

    /**
     * Everything a page does to a form, in the shape it is handed to a kit.
     *
     * @return array{data: string, confirm: string, files: string, history: string}
     */
    public function of(string $form): array
    {
        return [
            'data' => $this->urls->generate('api_form_data', ['id' => $form]),
            'confirm' => $this->urls->generate('api_form_confirm', ['id' => $form]),
            'files' => $this->files($form),
            'history' => $this->urls->generate('api_form_history', ['id' => $form]),
        ];
    }

    /** Where this form's uploads go, and the base every one of its files hangs off. */
    public function files(string $form): string
    {
        return $this->urls->generate('api_form_files', ['id' => $form]);
    }

    /** One file of one form: the only address in here that serves bytes. */
    public function file(string $form, string $file): string
    {
        return $this->urls->generate('api_form_file', ['id' => $form, 'fileId' => $file]);
    }
}

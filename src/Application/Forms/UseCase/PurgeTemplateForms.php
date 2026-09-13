<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\FileStore;
use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\Emptied;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * Deletes the forms made from a template, a batch at a time.
 *
 * The most destructive thing this service does: it deletes answers people gave,
 * drafts and closed records alike. Nothing here authorises anybody, so the
 * protection it gets is the one this service can actually offer — an address of
 * its own that a gateway can refuse to everybody.
 *
 * **It is {@see DeleteForm} in a loop, never a bulk statement.** A form does not
 * leave by having its row removed: its files go after the row, its revisions,
 * announcements and one-off documents leave with it, a `form.deleted` is queued
 * and a line is written down. A `DELETE … WHERE` would skip every one of those.
 *
 * **A batch, and it says what is left.** A template may hold fifty thousand
 * forms and a request that tries to take them all is one that times out half way
 * with no way to say what it did. The caller repeats until nothing remains, which
 * makes this resumable and idempotent.
 *
 * An expired form is deleted too, and that is the one thing here worth arguing.
 * `DeleteForm` refuses one — the API treats an expired form as gone everywhere —
 * but a row is a row to a foreign key, so leaving it would make the template
 * undeletable until the reaper next ran. It therefore leaves the way the reaper
 * would have taken it, `form.deleted` carrying `expired`: its disappearance was
 * promised before anybody asked for this, and saying otherwise would be telling a
 * receiver something that is not true.
 */
final class PurgeTemplateForms
{
    /** Forms per call. The caller repeats while anything remains. */
    public const int BATCH = 200;

    public function __construct(
        private readonly FormTemplates $templates,
        private readonly FormTemplateCatalogue $catalogue,
        private readonly DeleteForm $deleteForm,
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        /**
         * Here for the **reaped** forms only. A form that went through
         * `DeleteForm` has already had a worker asked to look; one taken the way
         * the reaper takes it never reached that line, and its news would sit in
         * the queue until something else happened. Once for the batch, because a
         * worker asked to look drains everything owed.
         */
        private readonly Announcer $announcer,
        private readonly Operations $operations,
    ) {}

    /**
     * @throws FormTemplateNotFound
     */
    public function __invoke(FormTemplateId $id, ?Actor $by = null): Emptied
    {
        // Asked first, so an id nothing was created under is told so rather than
        // answered "nothing to do" — an empty template and a missing one are
        // different answers and a caller acts differently on them.
        $this->templates->get($id);

        $deleted = 0;
        $reaped = 0;

        foreach ($this->catalogue->formIdsMadeFrom($id, self::BATCH) as $form) {
            try {
                ($this->deleteForm)($form, $by);
            } catch (FormGone) {
                $this->reap($form);
                ++$reaped;
            } catch (FormNotFound) {
                // Somebody else got there first between the listing and this
                // line, which is not a failure: the form is gone, which is what
                // was asked for.
                continue;
            }

            ++$deleted;
        }

        if ($reaped > 0) {
            $this->announcer->hurry();
        }

        $this->operations->templateFormsPurged($id, $deleted, $by);

        return new Emptied($deleted, $this->catalogue->formsMadeFrom($id));
    }

    /**
     * An expired form, taken the way the reaper takes one: the row first, then
     * the bytes. The other way round can leave a form naming files that are
     * gone, which is the one state this design does not tolerate.
     */
    private function reap(FormId $id): void
    {
        $this->forms->removeExpired($id);
        $this->files->forget($id);
        $this->operations->expired($id);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Tells the schema tool about the constraints the mapping cannot say.
 *
 * Two tables point at `forms.id` with `ON DELETE CASCADE`: what a form used to
 * hold ({@see FormRevisionRecord}, `Version20260824120000`) and what somebody is
 * still owed about it ({@see WebhookAnnouncementRecord}, `Version20260902160000`).
 * That is what makes "these leave with their form" a fact of the database rather
 * than an order of two statements. The ORM has no way to declare either: a
 * foreign key comes with an *association*, and both records deliberately have
 * none — they are rows with public fields and no idea a form exists, and turning
 * that into a `ManyToOne` would make every query about them go through an entity
 * nothing here wants loaded.
 *
 * So the mapping stayed quiet and the database grew a constraint, which left
 * `doctrine:schema:validate` reporting a difference for something that was
 * correct on both sides. Two ways that can go wrong and one of them is bad: a
 * real drift hides among the noise, and — worse — a `schema:update --force`
 * typed in a hurry drops the cascade the history depends on.
 *
 * `postGenerateSchema` is the seam Doctrine provides for exactly this. The
 * constraint is added to the schema the mapping produced, so a comparison sees
 * it on both sides, and it is stated in the same terms the migration used. It
 * fires only when the schema tool builds a schema — validating, diffing, or
 * updating — and never on a request.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class RowsLeaveWithTheirForm
{
    /**
     * The tables that leave with their form, and the name each constraint has in
     * the migration that created it. Two now: what a form used to hold, and what
     * somebody is still owed about it.
     */
    private const array KEYS = [
        'form_revisions' => 'fk_form_revisions_form',
        'webhook_announcements' => 'fk_webhook_announcements_form',
    ];

    public function __invoke(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        // Mapped entities, so normally all here — but a filtered schema is a
        // thing, and a listener that assumes otherwise turns a narrower question
        // into an error.
        if (!$schema->hasTable('forms')) {
            return;
        }

        foreach (self::KEYS as $table => $key) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $rows = $schema->getTable($table);

            if ($rows->hasForeignKey($key)) {
                continue;
            }

            // The name is the migration's, so the two describe one constraint
            // rather than two that happen to look alike. DBAL adds the index the
            // key needs along with it, which is the other half of what the
            // comparison was seeing.
            $rows->addForeignKeyConstraint(
                'forms',
                ['form_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                $key,
            );
        }
    }
}

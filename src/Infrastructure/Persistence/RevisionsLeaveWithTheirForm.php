<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Tells the schema tool about the one constraint the mapping cannot say.
 *
 * `form_revisions.form_id` points at `forms.id` with `ON DELETE CASCADE` — that
 * is what makes "a revision leaves with its form" a fact of the database rather
 * than an order of two statements (see `Version20260824120000`). The ORM has no
 * way to declare it: a foreign key comes with an *association*, and
 * {@see FormRevisionRecord} deliberately has none. It is a row with public
 * fields and no idea a form exists, `form_id` is half of its primary key, and
 * turning that into a `ManyToOne` would make every query about a revision go
 * through an entity nothing here wants loaded.
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
final class RevisionsLeaveWithTheirForm
{
    public function __invoke(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();

        // Both are mapped entities, so both are normally here — but a filtered
        // schema is a thing, and a listener that assumes otherwise turns a
        // narrower question into an error.
        if (!$schema->hasTable('form_revisions') || !$schema->hasTable('forms')) {
            return;
        }

        $revisions = $schema->getTable('form_revisions');

        if ($revisions->hasForeignKey('fk_form_revisions_form')) {
            return;
        }

        // The name is the migration's, so the two describe one constraint rather
        // than two that happen to look alike. DBAL adds the index the key needs
        // along with it, which is the other half of what the comparison was
        // seeing.
        $revisions->addForeignKeyConstraint(
            'forms',
            ['form_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_form_revisions_form',
        );
    }
}

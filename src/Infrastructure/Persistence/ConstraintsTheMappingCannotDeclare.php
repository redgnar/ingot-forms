<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Tells the schema tool about the foreign keys the mapping has no way to say.
 *
 * Every one of them exists because **a row here never has an association**. A
 * foreign key in the ORM comes with one, and an association is an entity
 * Doctrine decides when to load: a revision would be something a form drags
 * along, and a form's definition would arrive when somebody touched it rather
 * than when the form was read — which is precisely the decision that has to stay
 * where it can be seen, since a document is shared and must be read by a query
 * that takes no lock.
 *
 * So the mapping stays quiet and the database grows constraints, which used to
 * leave `doctrine:schema:validate` reporting a difference for something correct
 * on both sides. Two ways that goes wrong and one of them is bad: a real drift
 * hides among the noise, and — worse — a `schema:update --force` typed in a
 * hurry drops the constraint the data depends on. `postGenerateSchema` is the
 * seam Doctrine provides for exactly this; the keys are added to the schema the
 * mapping produced, in the same terms the migrations used, so a comparison sees
 * them on both sides. It fires only when the schema tool builds a schema —
 * validating, diffing, updating — and never on a request.
 *
 * Two kinds, and the difference between them is the whole storage story.
 * **Cascades** hang off `forms.id`: what a form used to hold and what somebody
 * is still owed about it leave with it, so a form can never outlive either, not
 * even for the width of a crash between two statements. **Restrictions** point
 * the other way, at the documents a form or a template is made of: those cannot
 * be deleted while anything is made of them, which is what makes a stored
 * document unable to vanish under the answers that were judged against it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ConstraintsTheMappingCannotDeclare
{
    /**
     * What leaves with a form, and the name each constraint has in the migration
     * that created it.
     *
     * @var array<string, array{string, string}>
     */
    private const array CASCADES = [
        'form_revisions' => ['fk_form_revisions_form', 'form_id'],
        // Not `form_id`: an announcement about a form that was *deleted* has to
        // outlive it, so the cascade hangs off a second, nullable column
        // ({@see WebhookAnnouncementRecord} explains the split).
        'webhook_announcements' => ['fk_webhook_announcements_live_form', 'live_form_id'],
    ];

    /**
     * What cannot be deleted while something is made of it: the table holding
     * the key, and for each key its name, its column and the table it points at.
     *
     * @var array<string, list<array{string, string, string}>>
     */
    private const array RESTRICTIONS = [
        'forms' => [
            ['fk_forms_definition', 'definition_id', 'form_definitions'],
            ['fk_forms_presentation', 'presentation_id', 'form_presentations'],
        ],
        'form_templates' => [
            ['fk_form_templates_definition', 'current_definition_id', 'form_definitions'],
            ['fk_form_templates_presentation', 'current_presentation_id', 'form_presentations'],
        ],
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

        foreach (self::CASCADES as $table => [$key, $column]) {
            $this->state($schema, $table, $key, $column, 'forms', 'CASCADE');
        }

        foreach (self::RESTRICTIONS as $table => $keys) {
            foreach ($keys as [$key, $column, $target]) {
                $this->state($schema, $table, $key, $column, $target, 'RESTRICT');
            }
        }
    }

    private function state(
        \Doctrine\DBAL\Schema\Schema $schema,
        string $table,
        string $key,
        string $column,
        string $target,
        string $onDelete,
    ): void {
        if (!$schema->hasTable($table) || !$schema->hasTable($target)) {
            return;
        }

        $rows = $schema->getTable($table);

        if ($rows->hasForeignKey($key)) {
            return;
        }

        // The name is the migration's, so the two describe one constraint rather
        // than two that happen to look alike. DBAL adds the index the key needs
        // along with it, which is the other half of what the comparison was
        // seeing.
        $rows->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => $onDelete], $key);
    }
}

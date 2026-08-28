<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a form somebody to attribute things to: who created it, who locked it,
 * and — on every accepted save — who entered it.
 *
 * Four columns and no new table, which is what
 * [07](../.claude/plan/07-history.md) predicted when it refused an actor column:
 * identity would arrive as a service-wide decision and land on this table
 * without moving anything else. It did.
 *
 * **The default on `identity_mode` is the whole point of this migration.**
 * Nothing can backfill who filled a form in last year, so the actor columns
 * arrive nullable — and a nullable column with no further rule makes `NULL` mean
 * three things at once: nobody was recorded by design, nobody was recorded
 * because this predates the feature, or a bug. Making the mode `NOT NULL` with
 * every existing row set to `anonymous` fixes that, and it is *truthful*: those
 * forms really did record nobody. From here on a form that records somebody
 * cannot accept a save naming nobody — the aggregate refuses it — so a `NULL`
 * actor means exactly one thing, an anonymous form.
 *
 * The default stays in the schema rather than being dropped afterwards, and the
 * mapping declares it too so the two agree. It is not what the application
 * leans on: the PHP property has no default, so a write that forgot to set the
 * mode fails before it can reach a column.
 */
final class Version20260828100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record who created, locked and filled in a form';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->addColumn('identity_mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'anonymous']);
        $forms->addColumn('author_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        $forms->addColumn('confirmed_by_subject', Types::STRING, ['length' => 255, 'notnull' => false]);

        // One per accepted save. Append-only like the rest of the row: nothing
        // updates a revision, so nothing can change who entered one afterwards.
        $schema->getTable('form_revisions')
            ->addColumn('actor_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->dropColumn('identity_mode');
        $forms->dropColumn('author_subject');
        $forms->dropColumn('confirmed_by_subject');

        $schema->getTable('form_revisions')->dropColumn('actor_subject');
    }
}

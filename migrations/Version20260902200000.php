<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a form report its own disappearance.
 *
 * `form.deleted` is the third thing a form may be told about, and the only one
 * whose subject no longer exists by the time anybody is told. That breaks the
 * arrangement the other two rely on: every announcement points at `forms.id`
 * with ON DELETE CASCADE, because a notification about a form that is gone is
 * worse than none — and this one *is* the notification that the form is gone.
 *
 * A foreign key cannot say "cascade all of these except that one", so the
 * identity and the cascade become two columns. `form_id` stays what every query
 * already uses: which form this is about, always set. `live_form_id` is the
 * mechanism — set for a save and a confirmation, so they leave with their form;
 * null for a deletion, so it survives the row it describes. The alternative was
 * dropping the cascade and sweeping announcements by hand on the way out, which
 * trades a guarantee the database keeps for two statements in the right order.
 *
 * `reason` says which way a form went: `requested` when somebody called
 * `DELETE /api/manage/forms/{id}`, `expired` when `app:forms:purge-expired`
 * reaped it. The second is why the event is worth having at all — nobody asks
 * the purge for anything, so an owner has no other way to learn that a form it
 * was waiting on has stopped existing.
 */
final class Version20260902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Announce a deleted form: the identity and the cascade become two columns, plus why it went';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->addColumn('webhook_deleted_url', Types::TEXT, ['notnull' => false]);

        $announcements = $schema->getTable('webhook_announcements');
        $announcements->addColumn('live_form_id', 'uuid', ['notnull' => false]);
        $announcements->addColumn('reason', Types::STRING, ['length' => 20, 'notnull' => false]);
        // The cascade moves to the new column, and the old one becomes what it
        // reads as: which form this announcement is about.
        $announcements->removeForeignKey('fk_webhook_announcements_form');
        // DBAL adds an index along with a foreign key and leaves it behind when
        // the key goes, so the mapping and the database would disagree about one
        // index for ever after. Found by name rather than by the generated one it
        // happens to carry.
        foreach ($announcements->getIndexes() as $index) {
            if ($index->getColumns() === ['form_id'] && !$index->isPrimary() && !$index->isUnique()) {
                $announcements->dropIndex($index->getName());
            }
        }
        $announcements->addForeignKeyConstraint(
            'forms',
            ['live_form_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_webhook_announcements_live_form',
        );
    }

    public function postUp(Schema $schema): void
    {
        // Everything already queued is about a form that still exists, so it
        // leaves with it, the way it did before this migration. DML, so it is
        // written rather than derived.
        $this->connection->executeStatement('UPDATE webhook_announcements SET live_form_id = form_id');
    }

    public function down(Schema $schema): void
    {
        $announcements = $schema->getTable('webhook_announcements');
        $announcements->removeForeignKey('fk_webhook_announcements_live_form');
        $announcements->addForeignKeyConstraint(
            'forms',
            ['form_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_webhook_announcements_form',
        );
        $announcements->dropColumn('reason');
        $announcements->dropColumn('live_form_id');

        $schema->getTable('forms')->dropColumn('webhook_deleted_url');
    }
}

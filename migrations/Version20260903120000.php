<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * How many saves a form has accepted, so that a caller can say which one it read.
 *
 * The number was always there — every revision has a `seq` — but only in the
 * history, which is not the truth about how many saves there have been:
 * `FORMS_HISTORY_LIMIT` evicts the oldest, so a count over what is kept would
 * renumber saves the moment it did. A column on the form counts what happened
 * instead of what is still stored, and a save reads it to number itself on a row
 * it already holds locked.
 *
 * Backfilled from the history because that is where the answer is today, and it
 * survives eviction for the one row that matters: `seq` only grows, so the
 * newest is the highest whether or not the older ones are still there. A form
 * with values but no revision at all (one saved before the history existed)
 * comes out at `0`, which is the honest answer — nothing recorded a save of it.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Count the accepted saves of a form on the form itself';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('forms')->addColumn('revision', Types::INTEGER, ['notnull' => true, 'default' => 0]);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(
            'UPDATE forms SET revision = COALESCE((SELECT MAX(r.seq) FROM form_revisions r WHERE r.form_id = forms.id), 0)',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('forms')->dropColumn('revision');
    }
}

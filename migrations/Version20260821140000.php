<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Built through the schema API rather than raw SQL, for the same reason as the
 * table beside it: one migration, whatever platform DATABASE_URL points at.
 */
final class Version20260821140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create form_revisions: every accepted save of a form, kept as it was accepted';
    }

    public function up(Schema $schema): void
    {
        $revisions = $schema->createTable('form_revisions');
        $revisions->addColumn('form_id', UuidType::NAME);
        // Per form, allocated under the row lock the save already holds. Ordering
        // by time would not do: two saves in one second are two revisions.
        $revisions->addColumn('seq', Types::INTEGER);
        $revisions->addColumn('saved_at', Types::DATETIME_IMMUTABLE);
        // The exact JSON text that passed validation, exactly as the row keeps
        // the current one: nothing queries inside it, and it goes back to a
        // client as it came.
        $revisions->addColumn('data', Types::TEXT);
        // The natural key, and the only one: a revision is one save of one form,
        // and nothing else ever points at it.
        $revisions->setPrimaryKey(['form_id', 'seq']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('form_revisions');
    }
}

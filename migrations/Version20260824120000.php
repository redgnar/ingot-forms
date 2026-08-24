<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes "a revision leaves with its form" a fact of the database rather than an
 * order of two statements.
 *
 * Until now a delete removed the revisions and then the row, in two separate
 * transactions: a crash between them left a *live* form whose history had been
 * thrown away — append-only right up to the moment it was not. A foreign key
 * with ON DELETE CASCADE removes both the window and the second statement, and
 * it is also the only thing that can keep an orphan out when a row goes any
 * other way.
 *
 * Built through the schema API, like the tables it ties together. The one
 * statement written out is the one a schema cannot express: rows that broke the
 * new constraint before it existed have to go before it can be added.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tie form_revisions.form_id to forms.id with ON DELETE CASCADE';
    }

    public function preUp(Schema $schema): void
    {
        // Revisions of a form that is already gone: nothing can ever look for
        // them again, and the constraint below cannot be created while they are
        // there. DML, so it is written rather than derived — and executed here
        // rather than queued, because it has to have happened by the time the
        // key is added.
        $this->connection->executeStatement(
            'DELETE FROM form_revisions WHERE form_id NOT IN (SELECT id FROM forms)',
        );
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('form_revisions')->addForeignKeyConstraint(
            'forms',
            ['form_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_form_revisions_form',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('form_revisions')->removeForeignKey('fk_form_revisions_form');
    }
}

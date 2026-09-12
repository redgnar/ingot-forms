<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves every definition and presentation into a row of its own.
 *
 * The second of three, and the only one that touches data. It is raw SQL rather
 * than the schema API — the exception `Version20260904090000` already set, and
 * for the same reason: the schema API describes tables and this describes rows.
 * Four statements, all of them portable, and no per-platform UUID generation
 * anywhere, which is the point of the one trick here.
 *
 * **A backfilled document takes the id of the form it came from.** Until now
 * every form has held its own private copy of both documents, so the two are 1:1
 * and the form's id is an identifier already in hand: unique, of the right type
 * on every platform, and free. Without it each row would need a UUID minted
 * where the statement runs, which is `gen_random_uuid()` on one database,
 * `UUID()` on another, and a loop in PHP over a large table on the portable
 * path. The shared id is an artifact of this migration and means nothing:
 * nothing reads it, nothing compares the two, and every document stored after
 * this gets an id of its own.
 *
 * Who stored it is the form's author, which is the truth rather than a guess —
 * whoever created a form is whoever supplied its definition. On a form that
 * records nobody the column is already null, so anonymity carries across without
 * this having to know what anonymity is.
 */
final class Version20260912100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move every form\'s two documents into rows of their own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('INSERT INTO form_definitions (id, document, created_at, created_by_subject) SELECT id, definition, created_at, author_subject FROM forms');
        $this->addSql('INSERT INTO form_presentations (id, document, created_at, created_by_subject) SELECT id, presentation, created_at, author_subject FROM forms WHERE presentation IS NOT NULL');
        $this->addSql('UPDATE forms SET definition_id = id');
        $this->addSql('UPDATE forms SET presentation_id = id WHERE presentation IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Putting the bytes back where they came from, which is possible for
        // exactly as long as the columns are still there — the next migration is
        // what takes them away, so rolling back goes through its own `down()`
        // first and this finds them waiting.
        $this->addSql('UPDATE forms SET definition = (SELECT document FROM form_definitions WHERE form_definitions.id = forms.definition_id)');
        $this->addSql('UPDATE forms SET presentation = (SELECT document FROM form_presentations WHERE form_presentations.id = forms.presentation_id) WHERE presentation_id IS NOT NULL');
        $this->addSql('DELETE FROM form_presentations');
        $this->addSql('DELETE FROM form_definitions');
        // Stated through the schema API and therefore run *after* the four
        // statements above — the same ordering that made this a migration of its
        // own is what lets the column go back to refusing a null once every row
        // holds its bytes again.
        $schema->getTable('forms')->getColumn('definition')->setNotnull(true);
    }
}

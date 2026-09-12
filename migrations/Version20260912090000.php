<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to keep the two documents a form is made of.
 *
 * Today they are columns on `forms`, so a system creating a thousand claim forms
 * stores the same definition a thousand times and has nowhere to keep the one it
 * means. These two tables are where it will be kept — once, with an identity of
 * its own, for however many forms are made of it.
 *
 * **Nothing reads them yet, and that is the whole of this migration.** `forms`
 * keeps its columns and keeps answering from them; the next migration is the one
 * that moves the bytes across, points the rows at them and drops the columns,
 * and it is the risky half. Splitting them means the tables, the mapping and the
 * adapter are already in the database and already exercised before anything
 * depends on them.
 *
 * Two columns each and no more. What is deliberately *not* here is the template:
 * a document belonging to a named, versioned catalogue entry is a later block,
 * and a `template_id` added now would be a column pointing at a table that does
 * not exist — a nullable reference to nothing, which is worse than no column at
 * all. Everything stored until then belongs to exactly one form, which is what
 * every form's definition has always been.
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep definitions and presentations as documents of their own';
    }

    public function up(Schema $schema): void
    {
        foreach (['form_definitions', 'form_presentations'] as $name) {
            $table = $schema->createTable($name);
            $table->addColumn('id', 'uuid');
            // The exact JSON text that passed the gate, as `forms` has always
            // held it: portable, byte for byte, and handed back to clients
            // unchanged.
            $table->addColumn('document', Types::TEXT);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            // Who stored it, as a gateway asserted them. Null where nothing did,
            // which is the ordinary case for a deployment with no proxy in front
            // of whoever writes documents.
            $table->addColumn('created_by_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('form_presentations');
        $schema->dropTable('form_definitions');
    }
}

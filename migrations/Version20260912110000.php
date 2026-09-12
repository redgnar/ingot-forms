<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The catalogue: a named place to keep documents, and the pair of them new forms
 * get.
 *
 * A document already stands on its own; what this adds is a history to sit in.
 * `template_id` and `seq` arrive together and mean nothing apart — a document
 * with both is a published version, a document with neither belongs to the one
 * form it was created with. **That second case is what "one-off" is**, and it
 * needs no flag of its own: a column that says the same thing as `template_id
 * IS NULL` is a second answer that can come to disagree with the first.
 *
 * The unique index is what makes a history a history — one row per number per
 * template — and it leaves one-off documents alone, both platforms this runs on
 * treating repeated nulls as distinct.
 *
 * **`template_id` is not a foreign key, and that is the one thing here worth
 * explaining.** `form_templates` points at the documents in use and the
 * documents would point back at the template numbering them, which is a cycle:
 * neither row could be inserted first, so creating a template would mean an
 * insert, an insert and an update. One of the two has to give, and it is this
 * one — "a template points at a version that exists" is worth having the
 * database enforce, while "a version belongs to a template that exists" buys a
 * cascade we do not want anyway. Deleting a template is refused while any form
 * is made of one of its versions, so what happens to its history is a decision
 * with a use case behind it rather than something the database does on the way
 * past.
 */
final class Version20260912110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A catalogue of templates, and a history for the documents they hold';
    }

    public function up(Schema $schema): void
    {
        foreach (['form_definitions', 'form_presentations'] as $name) {
            $documents = $schema->getTable($name);
            $documents->addColumn('template_id', 'uuid', ['notnull' => false]);
            $documents->addColumn('seq', Types::INTEGER, ['notnull' => false]);
            $documents->addUniqueIndex(['template_id', 'seq'], 'uniq_' . $name . '_version');
        }

        $templates = $schema->createTable('form_templates');
        $templates->addColumn('id', 'uuid');
        $templates->addColumn('name', Types::STRING, ['length' => 255]);
        $templates->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $templates->addColumn('created_by_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        // Which pair new forms are made of. The definition is never null: a
        // template with no pair in use is one nothing can be created from.
        $templates->addColumn('current_definition_id', 'uuid');
        $templates->addColumn('current_presentation_id', 'uuid', ['notnull' => false]);
        $templates->setPrimaryKey(['id']);
        $templates->addForeignKeyConstraint('form_definitions', ['current_definition_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_form_templates_definition');
        $templates->addForeignKeyConstraint('form_presentations', ['current_presentation_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_form_templates_presentation');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('form_templates');

        foreach (['form_definitions', 'form_presentations'] as $name) {
            $documents = $schema->getTable($name);
            $documents->dropIndex('uniq_' . $name . '_version');
            $documents->dropColumn('seq');
            $documents->dropColumn('template_id');
        }
    }
}

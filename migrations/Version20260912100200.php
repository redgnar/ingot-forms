<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the reference the only copy.
 *
 * The last of three. Every form now names a definition and, where it has one, a
 * presentation; this is where naming it becomes the only way it holds one.
 *
 * Two constraints, both `ON DELETE RESTRICT`, and that is the whole guarantee
 * this plan trades a column for. A definition used to be unable to change
 * because it sat on the form's own row and nothing wrote that column; from here
 * it is unable to *vanish* because the database refuses to delete a document
 * some form is made of. Structurally impossible rather than checked, which is
 * stronger than what the copy gave — a copy could be orphaned by a bad
 * migration, a reference cannot.
 *
 * The ORM cannot declare either, for the reason it cannot declare the cascades
 * that already exist here: a foreign key comes with an *association*, and
 * `FormRecord` deliberately has none — a form that lazily loaded an entity for
 * its own definition would be a form whose documents arrive when somebody
 * touches them rather than when it is read, and the row-lock question would stop
 * having one answer. So {@see \App\Infrastructure\Persistence\RowsLeaveWithTheirForm}
 * states them on `postGenerateSchema` and `SchemaInSyncTest` keeps the two sides
 * agreeing.
 */
final class Version20260912100200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the document a form names the only copy it has';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        // Every form is made of a definition — there has never been one that is
        // not, and the backfill has just proved it row by row.
        $forms->getColumn('definition_id')->setNotnull(true);
        $forms->addForeignKeyConstraint('form_definitions', ['definition_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_forms_definition');
        $forms->addForeignKeyConstraint('form_presentations', ['presentation_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_forms_presentation');
        $forms->dropColumn('definition');
        $forms->dropColumn('presentation');
    }

    public function down(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->removeForeignKey('fk_forms_presentation');
        $forms->removeForeignKey('fk_forms_definition');
        $forms->getColumn('definition_id')->setNotnull(false);
        // Both nullable on the way back, because the rows are here and the bytes
        // are not yet: the migration before this one puts them back and restores
        // `definition` to not-null once it has.
        $forms->addColumn('definition', \Doctrine\DBAL\Types\Types::TEXT, ['notnull' => false]);
        $forms->addColumn('presentation', \Doctrine\DBAL\Types\Types::TEXT, ['notnull' => false]);
    }
}

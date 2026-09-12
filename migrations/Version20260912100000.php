<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every form somewhere to point.
 *
 * The first of three, and they are separate on purpose: expand, move, contract.
 * A column is added here while nothing is required to use it, the bytes move in
 * the next one, and only the last makes the reference the single copy. Splitting
 * it that way is not ceremony — the alternative is one migration mixing DDL with
 * DML, and those cannot be ordered inside a single one. `DbalExecutor` runs
 * everything a migration adds through `addSql()` *first* and the schema diff
 * afterwards, so a backfill written beside a column creation would run before
 * the column existed.
 *
 * Both are nullable here and one of them stops being so at the end. Nothing
 * reads either yet.
 */
final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give every form a place to name the documents it is made of';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->addColumn('definition_id', 'uuid', ['notnull' => false]);
        $forms->addColumn('presentation_id', 'uuid', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        $forms->dropColumn('presentation_id');
        $forms->dropColumn('definition_id');
    }
}

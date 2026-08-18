<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Built through the schema API rather than raw SQL: the same migration has to
 * run on whatever platform DATABASE_URL points at, and Doctrine picks the
 * column types each one actually has.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the forms table: one row = one fillable form (definition + at most one data set)';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->createTable('forms');
        $forms->addColumn('id', UuidType::NAME);
        // The documents are stored as the JSON text that passed validation —
        // nothing queries inside them, and the bytes go back to clients as they came.
        $forms->addColumn('definition', Types::TEXT);
        $forms->addColumn('expire_date', Types::DATETIME_IMMUTABLE);
        $forms->addColumn('data', Types::TEXT, ['notnull' => false]);
        $forms->addColumn('data_saved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $forms->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $forms->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $forms->setPrimaryKey(['id']);
        $forms->addIndex(['expire_date'], 'idx_forms_expire');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('forms');
    }
}

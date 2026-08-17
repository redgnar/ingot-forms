<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the forms table: one row = one fillable form (definition + at most one data set)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE forms (
                id            uuid        PRIMARY KEY,
                definition    jsonb       NOT NULL,
                expire_date   timestamptz NOT NULL,
                data          jsonb,
                data_saved_at timestamptz,
                confirmed_at  timestamptz,
                created_at    timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->addSql('CREATE INDEX idx_forms_expire ON forms (expire_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE forms');
    }
}

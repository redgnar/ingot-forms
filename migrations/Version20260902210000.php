<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A form can report that it now exists.
 *
 * `form.created` was refused when the other three were built, on the grounds
 * that whoever creates a form is handed its id in the response. That is true of
 * the creator and says nothing about the *receiver*, who is whoever the creator
 * named: a downstream that mirrors these forms would otherwise meet one for the
 * first time as a `form.saved` for an id it has never seen. The lifecycle a
 * receiver can follow now has no hole at the start.
 */
final class Version20260902210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a form name where it reports that it has come into being';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('forms')->addColumn('webhook_created_url', Types::TEXT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('forms')->dropColumn('webhook_created_url');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records that somebody was told a form exists.
 *
 * `form.created` arrived without this, which left it the only telling about a
 * living thing with nowhere to be written down — a delivered notification would
 * have vanished, and the rule this queue follows is that a success is stamped on
 * the thing it is about while the queue keeps only work.
 *
 * On the form, beside `confirm_notified_at`, and for the same reason: a creation
 * is no revision.
 */
final class Version20260902220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stamp the form when somebody has been told it exists';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('forms')->addColumn('created_notified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('forms')->dropColumn('created_notified_at');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Built through the schema API rather than raw SQL, for the same reason as the
 * table itself: one migration, whatever platform DATABASE_URL points at.
 */
final class Version20260819090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the presentation column: how a form is shown, beside what it asks and what it holds';
    }

    public function up(Schema $schema): void
    {
        // Nullable, because a form is fillable long before anybody says how it
        // should look — and because saying so is not part of creating one.
        $schema->getTable('forms')->addColumn('presentation', Types::TEXT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('forms')->dropColumn('presentation');
    }
}

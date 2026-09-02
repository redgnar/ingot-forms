<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keeps the telling that succeeded, not only the ones that did not.
 *
 * `webhook_announcements` was a queue: a row was deleted the moment somebody had
 * been told, so what outlived a delivery was only what this service gave up on.
 * That asymmetry is the whole reason for this column — a failure was durable and
 * a success left no trace, so the one question the owner of a form actually asks
 * ("were you told about this, and when?") had no answer anywhere.
 *
 * `delivered_at` makes three states out of two columns: both null is owed,
 * this one set is told, `gave_up_at` set is abandoned. A delivery run filters on
 * it, so a told row costs the queue nothing, and
 * `GET /api/manage/forms/{id}/deliveries` reads all three.
 */
final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a notification was delivered, rather than deleting the row that says so';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('webhook_announcements')
            ->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('webhook_announcements')->dropColumn('delivered_at');
    }
}

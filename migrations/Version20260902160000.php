<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A form can say where it is to be reported, and what is owed is a row.
 *
 * Three things, and the order they are described in is the order they matter:
 *
 *   - two columns on `forms`, because where a form reports itself is part of the
 *     form and never changes;
 *   - `webhook_announcements`, the outbox: what happened, written in the same
 *     transaction as the save it is about, holding **no values** — only which
 *     form, which save and where to say so. A queue holds what is still owed, so
 *     a row is deleted once it has been told; what is left is either owed or
 *     given up on;
 *   - `messenger_messages`, for the nudge that asks a worker to get on with it.
 *
 * Built through the schema API like every migration here, including Messenger's
 * own table: `auto_setup` is off, because a deployment whose database user may
 * not issue DDL should not have a transport creating tables at runtime — and a
 * table that arrives with the migrations is a table `doctrine:migrations:status`
 * can talk about.
 */
final class Version20260902160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-form webhook endpoints, the announcement outbox, and the transport table for the nudge';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->getTable('forms');
        // Null means nobody is told about that event, which is the default and
        // costs nothing: a form naming no endpoint queues no announcement.
        $forms->addColumn('webhook_save_url', Types::TEXT, ['notnull' => false]);
        $forms->addColumn('webhook_confirm_url', Types::TEXT, ['notnull' => false]);

        $announcements = $schema->createTable('webhook_announcements');
        $announcements->addColumn('id', 'uuid');
        $announcements->addColumn('form_id', 'uuid');
        // Copied from the form when the announcement was made, so a delivery is
        // whole on its own and can be tried, retried and read without asking a
        // form anything.
        $announcements->addColumn('target', Types::TEXT);
        $announcements->addColumn('event', Types::STRING, ['length' => 40]);
        $announcements->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        // Which save this was; null for a confirmation, which is no revision.
        $announcements->addColumn('revision', Types::INTEGER, ['notnull' => false]);
        $announcements->addColumn('actor_subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        // No database default: the record's own is what a new announcement gets,
        // and a column default would be a second answer to the same question.
        $announcements->addColumn('attempts', Types::INTEGER);
        $announcements->addColumn('next_attempt_at', Types::DATETIME_IMMUTABLE);
        $announcements->addColumn('gave_up_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $announcements->addColumn('last_refusal', Types::TEXT, ['notnull' => false]);
        $announcements->setPrimaryKey(['id']);
        // What a run asks for: what is owed now. Ordering is by `occurred_at`,
        // but the filter is this one and it is what keeps a full queue cheap.
        $announcements->addIndex(['next_attempt_at'], 'idx_webhook_announcements_due');
        // An announcement leaves with its form, the way a revision does: a
        // notification pointing at a form that no longer exists is worse than
        // none, and one statement leaves no window in which a deleted form still
        // owes somebody news about itself.
        $announcements->addForeignKeyConstraint(
            'forms',
            ['form_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_webhook_announcements_form',
        );

        // Messenger's Doctrine transport, stated the way that transport expects
        // it. Nothing in this application reads or writes it.
        $messages = $schema->createTable('messenger_messages');
        $messages->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $messages->addColumn('body', Types::TEXT);
        $messages->addColumn('headers', Types::TEXT);
        $messages->addColumn('queue_name', Types::STRING, ['length' => 190]);
        $messages->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $messages->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $messages->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $messages->setPrimaryKey(['id']);
        $messages->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'idx_messenger_messages_next');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('messenger_messages');
        $schema->dropTable('webhook_announcements');

        $forms = $schema->getTable('forms');
        $forms->dropColumn('webhook_confirm_url');
        $forms->dropColumn('webhook_save_url');
    }
}

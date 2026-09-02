<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the record of a *successful* telling onto the thing it was about, and
 * leaves the queue holding only work: what is still owed, and what was given up
 * on.
 *
 * `Version20260902170000` kept a told row in `webhook_announcements` and marked
 * it. That answered the question — were you told, and when — at the cost of a
 * queue that never drains and that mixes three states, two of which are nobody's
 * work any more. The fact belongs where the thing it is about lives instead: a
 * save was reported, so its revision says when; a confirmation was reported, so
 * the form says when. One row per fact, in the row that fact is about.
 *
 * What follows and is worth knowing: a stamp leaves when the row it sits on
 * leaves. `FORMS_HISTORY_LIMIT` evicts old revisions, and with them the record
 * that those saves were reported — the same rule the rest of this service
 * follows, where a document nobody can restore is a document whose files stopped
 * mattering. The queue, meanwhile, is now exactly what a queue should be: a work
 * list plus the ones nobody could deliver.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stamp a delivered notification on the revision or the form, and drop the marked queue row';
    }

    public function up(Schema $schema): void
    {
        // One accepted save, and when whoever owns the form was told about it.
        // The only column of this table that is ever updated: what a revision
        // *held* is append-only, and this is about the telling rather than about
        // the document.
        $schema->getTable('form_revisions')
            ->addColumn('notified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        // Confirming writes no values, so it is no revision — which is why the
        // form itself carries this one, next to `confirmed_at` and for the same
        // reason `confirmed_by_subject` is there.
        $schema->getTable('forms')
            ->addColumn('confirm_notified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        // The queue keeps what is owed and what was abandoned. A told row is not
        // work, and its fact now lives on the row it is about.
        $schema->getTable('webhook_announcements')->dropColumn('delivered_at');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('webhook_announcements')
            ->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schema->getTable('forms')->dropColumn('confirm_notified_at');
        $schema->getTable('form_revisions')->dropColumn('notified_at');
    }
}

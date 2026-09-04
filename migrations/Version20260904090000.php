<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Takes the author off every form that records nobody.
 *
 * `identity: anonymous` means a form records nobody, and until now it meant a
 * form records nobody *except whoever created it*: the discard was applied to
 * every save and to the confirmation, and not to the creation. Behind a proxy
 * that asserts an identity on every request — which is the arrangement this
 * service is built for — that named the author of every anonymous form ever
 * created.
 *
 * The rule is fixed in the model, and it is fixed in the constructor — which
 * `Form::fromState()` goes through — so a row written under the old rule already
 * reads back with no author, through the API and everywhere else. This is about
 * the **data at rest**: a column still holding somebody on a form that promises
 * to hold nobody is a promise kept only by the code that happens to read it, and
 * the next thing to read that table directly would find what it says is not
 * there. That is the difference between this and the header itself: an identity
 * written under a forgeable header can never be told apart from a good one,
 * while an identity that should never have been kept is one column and one
 * statement.
 *
 * Nothing else needs it. A filler and a confirmer went through the discard from
 * the beginning, so an anonymous form has never had either — nulling them would
 * be a statement about data that cannot exist.
 */
final class Version20260904090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forget the author of every form that records nobody';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE forms SET author_subject = NULL WHERE identity_mode = 'anonymous'");
    }

    public function down(Schema $schema): void
    {
        // There is nothing to put back. That is the point of the change rather
        // than a shortcoming of the migration: the whole operation is forgetting
        // somebody, and a rollback that could undo it would mean it had not
        // happened.
        $this->throwIrreversibleMigrationException('An identity this dropped on purpose cannot be restored.');
    }
}

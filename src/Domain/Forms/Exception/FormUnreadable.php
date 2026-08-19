<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;

/**
 * A form is in the database and today's rules cannot read it back.
 *
 * This is not a client's mistake and not a broken server: it is a document that
 * was accepted under rules that have since changed — a new constraint, a member
 * that stopped being allowed — so the row is intact and unreadable at once.
 *
 * The findings travel with it because they are the whole diagnosis: they say
 * which rule the stored document no longer satisfies, which is what somebody
 * needs to migrate it or to decide the form is not worth keeping. Deleting it
 * still works, since removing a row never has to understand it.
 */
final class FormUnreadable extends \RuntimeException
{
    public function __construct(
        FormId $id,
        public readonly ErrorReport $report,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('The stored form no longer satisfies the rules it is read with. (form "%s")', $id), previous: $previous);
    }
}

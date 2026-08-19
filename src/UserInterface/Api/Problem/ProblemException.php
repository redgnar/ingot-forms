<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Problem;

use Ingot\Error\ErrorReport;

/**
 * An HTTP-level problem raised by controllers (state conflicts, bad request
 * envelopes). Domain validation failures use their own exception types and
 * are mapped by {@see ProblemExceptionListener}.
 */
final class ProblemException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        /** Suffix of the problem "type" URN, e.g. "form-locked". */
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?ErrorReport $report = null,
    ) {
        parent::__construct($title);
    }
}

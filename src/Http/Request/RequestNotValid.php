<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Error\ErrorReport;

/**
 * A request that does not match its DTO contract: wrong types, missing or
 * unexpected keys, broken formats, or a semantic rule from a
 * {@see RequestRule}. Mapped to problem+json by
 * {@see \App\Http\Problem\ProblemExceptionListener} — 400 when the body was
 * not even JSON, 422 otherwise.
 */
final class RequestNotValid extends \RuntimeException
{
    public function __construct(
        public readonly ErrorReport $report,
    ) {
        parent::__construct('Request is not valid.');
    }
}

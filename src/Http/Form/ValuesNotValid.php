<?php

declare(strict_types=1);

namespace App\Http\Form;

use Ingot\Error\ErrorReport;

/**
 * Submitted values the form they belong to refuses. Mapped to problem+json by
 * {@see \App\Http\Problem\ProblemExceptionListener}, like every other report.
 */
final class ValuesNotValid extends \RuntimeException
{
    public function __construct(
        public readonly ErrorReport $report,
    ) {
        parent::__construct('Form values are not valid.');
    }
}

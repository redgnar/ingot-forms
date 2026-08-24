<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use Ingot\Error\ErrorReport;

final class PresentationNotValid extends \RuntimeException implements CarriesFindings
{
    public function __construct(
        public readonly ErrorReport $report,
    ) {
        parent::__construct('Form presentation is not valid.');
    }
}

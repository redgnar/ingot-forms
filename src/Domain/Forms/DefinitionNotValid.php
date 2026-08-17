<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use Ingot\Error\ErrorReport;

final class DefinitionNotValid extends \RuntimeException
{
    public function __construct(
        public readonly ErrorReport $report,
    ) {
        parent::__construct('Form definition is not valid.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\ValuesValidator;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * Accepts everything, unless told to refuse — and remembers in which mode it
 * was asked, because that is the whole difference between saving and
 * confirming.
 */
final class StubValues implements ValuesValidator
{
    /** @var list<DeriveMode> */
    public array $modes = [];

    public function __construct(
        private readonly bool $refuse = false,
    ) {}

    public function assertFit(FormDefinition $definition, mixed $values, DeriveMode $mode, FormId $formId): void
    {
        $this->modes[] = $mode;

        if ($this->refuse) {
            throw new ValuesNotValid(ErrorReport::of(
                new MappingError(JsonPointer::fromString('/age'), 'schema.minimum', 'Too small.'),
            ));
        }
    }
}

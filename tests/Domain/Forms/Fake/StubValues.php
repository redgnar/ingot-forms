<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Fake;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * Accepts everything, unless told to refuse — and remembers what it was asked
 * about: the mode, because that is the whole difference between saving and
 * confirming, and the form, because a form must only ever be judged against
 * its own definition.
 */
final class StubValues implements ValuesValidator
{
    /** @var list<DeriveMode> */
    public array $modes = [];

    /** @var list<array{FormId, FormDefinition, mixed}> what it was handed, in order */
    public array $asked = [];

    public function __construct(
        private readonly bool $refuse = false,
    ) {}

    public function assertFit(FormDefinition $definition, mixed $values, DeriveMode $mode, FormId $formId): void
    {
        $this->modes[] = $mode;
        $this->asked[] = [$formId, $definition, $values];

        if ($this->refuse) {
            throw new ValuesNotValid(ErrorReport::of(
                new MappingError(JsonPointer::fromString('/age'), 'schema.minimum', 'Too small.'),
            ));
        }
    }
}

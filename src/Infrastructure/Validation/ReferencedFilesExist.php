<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileReference;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;

/**
 * The third gate: a file a values document names has to be a file this form
 * actually holds, described exactly as the store describes it.
 *
 * This is the one place in this codebase where the server is stricter than its
 * published contract, and it is worth saying out loud. No schema can state "this
 * id exists" — that is a question about the world, the same category as "this
 * form has expired", which the contract has never covered either. Two things
 * keep it from being a trap: a client that echoes back what the upload answered
 * with can never trip it, and it lives here rather than inside the form stage,
 * so what the two publishable gates promise stays exactly what they promise.
 *
 * It runs last because it is the only gate that talks to another store: nothing
 * pays for it until the document is otherwise perfect.
 */
final class ReferencedFilesExist
{
    public function __construct(
        private readonly FileReferences $references,
        private readonly FileStore $files,
    ) {}

    public function validate(FormDefinition $definition, \stdClass $values, FormId $formId): ErrorReport
    {
        $errors = [];

        foreach ($this->references->named($definition, $values) as $reference) {
            $stored = $this->files->describe($formId, $reference->descriptor->id);

            if ($stored === null) {
                $errors[] = new MappingError(
                    $reference->pointer->append('id'),
                    'form.file.unknown',
                    'This form holds no such file. Upload it first, and send back what the upload answered with.',
                    (string) $reference->descriptor->id,
                );

                continue;
            }

            $errors = [...$errors, ...self::disagreements($reference, $stored)];
        }

        return ErrorReport::of(...$errors);
    }

    /**
     * What the client says about the file against what the store recorded, member
     * by member — one finding per member that differs, because a page marks a
     * control and not a document.
     *
     * The claim is what makes `maxSize` and `accept` statable in the published
     * schema at all; holding it to what was measured is what makes those two
     * rules true rather than decorative.
     *
     * @return list<MappingError>
     */
    private static function disagreements(FileReference $reference, FileDescriptor $stored): array
    {
        $claim = $reference->descriptor;
        $errors = [];

        if ($claim->name !== $stored->name) {
            $errors[] = self::mismatch($reference, 'name', $stored->name, $claim->name);
        }

        if ($claim->size !== $stored->size) {
            $errors[] = self::mismatch($reference, 'size', (string) $stored->size, $claim->size);
        }

        if (!$claim->type->equals($stored->type)) {
            $errors[] = self::mismatch($reference, 'type', (string) $stored->type, (string) $claim->type);
        }

        return $errors;
    }

    private static function mismatch(FileReference $reference, string $member, string $recorded, string|int $claimed): MappingError
    {
        return new MappingError(
            $reference->pointer->append($member),
            'form.file.mismatch',
            \sprintf('The stored file says "%s". Send back what the upload answered with.', $recorded),
            $claimed,
        );
    }
}

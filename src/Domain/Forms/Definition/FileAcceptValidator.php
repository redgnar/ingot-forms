<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use App\Domain\Forms\ValueObject\MediaType;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Every entry of `accept` has to be a media type, and the finding points at the
 * one that is not — a list is a scope like any other.
 *
 * The rule lives here rather than as a pattern on the member because the answer
 * to "is that a media type" belongs in one place ({@see MediaType}), where the
 * description of a stored file asks it too. A definition and a descriptor that
 * disagreed about it would accept documents nothing could ever satisfy.
 *
 * @implements ObjectValidator<FileField>
 */
final class FileAcceptValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        foreach ($object->accept as $index => $accepted) {
            if (MediaType::isOne($accepted)) {
                continue;
            }

            $context->addError(
                \sprintf('/accept/%d', $index),
                'form.file.not-a-media-type',
                \sprintf('"%s" is not a media type. Write them out — "application/pdf", "image/png" — wildcards are not accepted.', $accepted),
                $accepted,
            );
        }
    }
}

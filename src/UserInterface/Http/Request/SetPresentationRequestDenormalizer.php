<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request;

use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Maps a request body that is a document rather than a set of named members,
 * the way {@see SaveFormDataRequestDenormalizer} does for values.
 *
 * A body that is not a JSON object is refused here, which puts it in the same
 * violation list as every other envelope problem (pointer `""`, code
 * `request.type`).
 */
final class SetPresentationRequestDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if ($data instanceof \stdClass) {
            return new SetPresentationRequest($data);
        }

        $path = $context['deserialization_path'] ?? null;
        $failure = NotNormalizableValueException::createForUnexpectedDataType(
            'A presentation must be a JSON object.',
            $data,
            ['object'],
            \is_string($path) ? $path : null,
            true,
        );

        $collected = &$context['not_normalizable_value_exceptions'];

        if (!\is_array($collected)) {
            throw $failure;
        }

        $collected[] = $failure;

        return null;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === SetPresentationRequest::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [SetPresentationRequest::class => true];
    }
}

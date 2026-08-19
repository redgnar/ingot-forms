<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Maps a request body that is a document rather than a set of named members:
 * the values of a form are keyed by whatever its definition declares, so the
 * payload goes into {@see SaveFormDataRequest} whole instead of being matched
 * against DTO properties.
 *
 * A body that is not a JSON object is refused here, which puts it in the same
 * violation list as every other envelope problem (pointer `""`, code
 * `request.type`).
 */
final class SaveFormDataRequestDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if ($data instanceof \stdClass) {
            return new SaveFormDataRequest($data);
        }

        $path = $context['deserialization_path'] ?? null;
        $failure = NotNormalizableValueException::createForUnexpectedDataType(
            'Form values must be a JSON object keyed by field name.',
            $data,
            ['object'],
            \is_string($path) ? $path : null,
            true,
        );

        // When the caller collects mapping failures, hand this one over so it
        // joins the other envelope problems instead of aborting the request.
        // The slot is a reference into the serializer's own list.
        $collected = &$context['not_normalizable_value_exceptions'];

        if (!\is_array($collected)) {
            throw $failure;
        }

        $collected[] = $failure;

        return null;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === SaveFormDataRequest::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [SaveFormDataRequest::class => true];
    }
}

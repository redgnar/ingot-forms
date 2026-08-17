<?php

declare(strict_types=1);

namespace App\Http\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Turns `#[MapRequest]` arguments into hydrated DTOs, so a controller never
 * touches raw input: by the time its body runs, the request either matched its
 * contract or a {@see RequestNotValid} report was already raised.
 */
final readonly class MapRequestResolver implements ValueResolverInterface
{
    public function __construct(
        private RequestMapper $mapper,
    ) {}

    /**
     * @return list<object>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attributes = $argument->getAttributes(MapRequest::class, ArgumentMetadata::IS_INSTANCEOF);
        $attribute = $attributes[0] ?? null;

        if (!$attribute instanceof MapRequest) {
            return [];
        }

        $target = $argument->getType();

        if ($target === null || !class_exists($target)) {
            throw new \LogicException(\sprintf(
                'Argument "$%s" is #[MapRequest] but its type is not a class.',
                $argument->getName(),
            ));
        }

        return [match ($attribute->from) {
            RequestPart::Body => $this->mapper->fromBody($target, $request->getContent()),
            RequestPart::Query => $this->mapper->fromQuery($target, $request->query->all()),
        }];
    }
}

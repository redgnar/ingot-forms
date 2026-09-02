<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\Domain\Forms\ValueObject\Webhooks;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where a form is to report itself, as it arrives.
 *
 * Four members, all optional, each judged here so a client is told *which* of
 * them is wrong (`/webhooks/save`) rather than that something about the request
 * was. The value object judges them again on the way into the model, which is
 * the same split `expireDate` and the definition already make: the envelope
 * reports, the model refuses.
 */
final readonly class WebhooksRequest
{
    public function __construct(
        #[OA\Property(
            description: 'Told when the form comes into being, as an absolute http(s) URL. Whoever creates a form is handed its id in the response, so this is for a *receiver* that is not the creator — a downstream that mirrors these forms would otherwise meet one for the first time as a `form.saved` for an id it has never seen.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://example.test/forms/created',
        )]
        #[Assert\Url(
            protocols: ['http', 'https'],
            message: 'A webhook must be an absolute http or https URL.',
            payload: ['code' => 'form.webhook.not_a_url'],
        )]
        #[Assert\Length(
            max: Webhooks::MAX_LENGTH,
            maxMessage: 'A webhook URL may be at most {{ limit }} characters long.',
            payload: ['code' => 'form.webhook.too_long'],
        )]
        public ?string $created = null,
        #[OA\Property(
            description: 'Told when a draft save is accepted, as an absolute http(s) URL.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://example.test/forms/saved',
        )]
        #[Assert\Url(
            protocols: ['http', 'https'],
            message: 'A webhook must be an absolute http or https URL.',
            payload: ['code' => 'form.webhook.not_a_url'],
        )]
        #[Assert\Length(
            max: Webhooks::MAX_LENGTH,
            maxMessage: 'A webhook URL may be at most {{ limit }} characters long.',
            payload: ['code' => 'form.webhook.too_long'],
        )]
        public ?string $save = null,
        #[OA\Property(
            description: 'Told when the form is confirmed, as an absolute http(s) URL.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://example.test/forms/confirmed',
        )]
        #[Assert\Url(
            protocols: ['http', 'https'],
            message: 'A webhook must be an absolute http or https URL.',
            payload: ['code' => 'form.webhook.not_a_url'],
        )]
        #[Assert\Length(
            max: Webhooks::MAX_LENGTH,
            maxMessage: 'A webhook URL may be at most {{ limit }} characters long.',
            payload: ['code' => 'form.webhook.too_long'],
        )]
        public ?string $confirm = null,
        #[OA\Property(
            description: 'Told when the form stops existing — deleted, or reaped for having expired — as an absolute http(s) URL. The notification says which in `reason`.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://example.test/forms/deleted',
        )]
        #[Assert\Url(
            protocols: ['http', 'https'],
            message: 'A webhook must be an absolute http or https URL.',
            payload: ['code' => 'form.webhook.not_a_url'],
        )]
        #[Assert\Length(
            max: Webhooks::MAX_LENGTH,
            maxMessage: 'A webhook URL may be at most {{ limit }} characters long.',
            payload: ['code' => 'form.webhook.too_long'],
        )]
        public ?string $deleted = null,
    ) {}
}

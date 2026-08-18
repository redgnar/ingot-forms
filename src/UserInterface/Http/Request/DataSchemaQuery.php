<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request;

use App\Domain\Forms\DeriveMode;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of `GET /api/forms/{id}/schema`, mapped by Symfony
 * (`#[MapQueryString]`).
 *
 * The mode arrives as its wire value and is validated against the enum's
 * cases, so a wrong value is answered with the accepted list instead of a
 * mapping message naming internal types; {@see mode()} hands the controller
 * the enum itself.
 */
final readonly class DataSchemaQuery
{
    /** The wire values of {@see DeriveMode}, which is also what the contract publishes. */
    public const array MODES = ['strict', 'draft'];

    public function __construct(
        #[OA\Property(
            description: 'Which contract the returned schema enforces: `strict` is the confirmation contract, `draft` relaxes what would block storing partial progress.',
            example: 'draft',
        )]
        #[Assert\Choice(
            choices: self::MODES,
            message: 'mode must be one of: strict, draft.',
            payload: ['code' => 'request.choice'],
        )]
        public string $mode = 'strict',
    ) {}

    public function mode(): DeriveMode
    {
        return DeriveMode::from($this->mode);
    }
}

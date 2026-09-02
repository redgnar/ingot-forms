<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\CreateForm;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\Webhooks;
use App\UserInterface\Api\Problem\ProblemException;
use App\UserInterface\Api\Request\CreateFormRequest;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Creates a form. The definition is immutable afterwards: changing it
 * means deleting this form and creating a new one.
 */
final class CreateFormAction
{
    public function __construct(
        private readonly CreateForm $createForm,
        private readonly PresentationProcessor $presentations,
    ) {}

    /**
     * A document is judged on its own terms and points inside itself; the client
     * sent it as one member of this request, so every finding about it is rooted
     * where the client put it.
     */
    private static function rootedAt(string $member, ErrorReport $report): ErrorReport
    {
        $rerooted = [];

        foreach ($report as $error) {
            $rerooted[] = new MappingError(
                JsonPointer::fromString($member . $error->pointer->toString()),
                $error->code,
                $error->message,
                $error->input,
            );
        }

        return ErrorReport::of(...$rerooted);
    }

    #[Route('/api/manage/forms', methods: ['POST'])]
    #[OA\Post(
        operationId: 'createForm',
        summary: 'Create a form',
        description: 'Both documents a form is made of arrive here: what it asks, and optionally how it is shown. Both are immutable afterwards — changing either means delete and recreate. A form may also be born holding values a client already knows (`data`), judged under the draft contract, which makes it a draft from the start. Problems inside a document are reported with JSON Pointers rooted at `/definition`, `/presentation` or `/data`.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Form created. The body carries the new id and nothing else — everything else the client already sent, or can read back from `Location`.',
        headers: [new OA\Header(header: 'Location', description: 'Path of the created form, `/api/manage/forms/{id}`.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: '#/components/schemas/CreatedForm'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/MalformedJson')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(
        response: 422,
        description: 'The request envelope, the definition, the presentation or the values the form would be born with are not valid.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'request-not-valid',
                    summary: 'The body does not match the request DTO, or the form expires in the past',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/expireDate', 'code' => 'form.expire_date.past', 'message' => 'expireDate must be in the future.', 'input' => '2020-01-01T00:00:00+00:00'],
                            ['pointer' => '/bogus', 'code' => 'request.unexpected_key', 'message' => 'Unexpected member "bogus".'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'definition-not-valid',
                    summary: 'The definition breaks the meta-schema or a semantic rule',
                    value: [
                        'type' => 'urn:problem:ingot-forms:definition-not-valid',
                        'title' => 'Form definition is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/definition/items/1/name', 'code' => 'form.field.duplicate-name', 'message' => 'Field name "email" is not unique.', 'input' => 'email'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'data-not-valid',
                    summary: 'A value the form is asked to be born with does not fit the item it belongs to',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/data/age', 'code' => 'schema.minimum', 'message' => 'The number must be at least 18.', 'input' => 7],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'presentation-not-valid',
                    summary: 'The presentation shows an item the definition does not declare',
                    value: [
                        'type' => 'urn:problem:ingot-forms:presentation-not-valid',
                        'title' => 'Form presentation is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/presentation/items/0/name', 'code' => 'presentation.item.unknown', 'message' => 'This form declares no item named "nickname".', 'input' => 'nickname'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function __invoke(
        // JSON only, and a closed contract: a media type this API does not speak
        // is refused before mapping, and a member the DTO does not declare is a
        // client bug worth reporting — the published schema says both.
        #[MapRequestPayload(
            acceptFormat: 'json',
            // Decoded as JSON meant it: both documents keep their objects, so an
            // empty translation catalogue stays `{}` instead of turning into a list.
            serializationContext: [
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                JsonDecode::ASSOCIATIVE => false,
            ],
        )]
        CreateFormRequest $request,
        // Who is creating this, as the gateway asserted it — resolved by
        // IdentityIntake, never claimed in the body. There is no `author` member
        // for a client to send, and the closed envelope is what enforces that
        // without a line of new code.
        ?Actor $author,
    ): JsonResponse {
        try {
            $id = ($this->createForm)(
                // Judged twice on purpose, and it must stay that way: the
                // constraint on the DTO parses it so its findings arrive in the
                // same report as the rest of the envelope's — a client should not
                // have to fix `expireDate` before being told about its
                // definition — and the aggregate parses what it is actually
                // built from, because nothing may reach it unproved. Making
                // either side trust the other's work would cost one of those two.
                $request->definition,
                self::expiring($request->expireDate),
                $request->presentation === null
                    ? null
                    : $this->presentations->document($this->presentations->parse($request->presentation)),
                $request->data,
                IdentityMode::from($request->identity),
                $author,
                // Judged twice for the same reason the two documents are: the DTO
                // says which member is wrong, the value object refuses to let a
                // form exist reporting itself somewhere unusable.
                $request->webhooks === null
                    ? null
                    : Webhooks::of(
                        $request->webhooks->save,
                        $request->webhooks->confirm,
                        $request->webhooks->deleted,
                    ),
            );
        } catch (DefinitionNotValid $exception) {
            // Only reachable if the constraint and the aggregate ever disagree
            // about the same document. Rooted where the client put it, like the
            // other two, rather than answering about a document nobody sent.
            throw new DefinitionNotValid(self::rootedAt('/definition', $exception->report));
        } catch (PresentationNotValid $exception) {
            throw new PresentationNotValid(self::rootedAt('/presentation', $exception->report));
        } catch (ValuesNotValid $exception) {
            // Refused before the form exists: a form is never created holding
            // something it would not have accepted later.
            throw new ValuesNotValid(self::rootedAt('/data', $exception->report));
        }

        // The id is the one thing the client cannot know yet; the definition and
        // the expire date came from this very request, so echoing them back would
        // only invite a client to trust a copy instead of the resource.
        return new JsonResponse(
            ['id' => (string) $id],
            201,
            ['Location' => \sprintf('/api/manage/forms/%s', $id)],
        );
    }

    /**
     * The expire date as the model insists on having it: in the future.
     *
     * The DTO's own constraint has already said so, and this is not that check
     * again — it is the same check a moment later, which is a moment in which a
     * date one microsecond ahead stops being ahead. Left to itself the model's
     * refusal would be a 500; answered here it is the finding the constraint
     * would have produced, so a client reads one contract either way.
     */
    private static function expiring(\DateTimeImmutable $moment): ExpireDate
    {
        try {
            return ExpireDate::future($moment);
        } catch (\InvalidArgumentException) {
            throw new ProblemException(422, 'request-not-valid', 'Request is not valid.', report: ErrorReport::of(
                new MappingError(
                    JsonPointer::fromString('/expireDate'),
                    'form.expire_date.past',
                    'expireDate must be in the future.',
                    $moment->format(\DateTimeInterface::ATOM),
                ),
            ));
        }
    }
}

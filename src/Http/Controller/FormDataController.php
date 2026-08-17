<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\FormDataValidator;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Http\FormEnvelope;
use App\Http\Problem\ProblemException;
use App\Infrastructure\Persistence\FormRepository;
use App\Infrastructure\Persistence\FormStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The data lifecycle of a form: save a draft (repeatable), confirm (locks
 * the form for good), read the values back. All transitions run inside a
 * row-locking transaction so validation and state checks cannot race.
 */
final class FormDataController extends AbstractController
{
    public function __construct(
        private readonly FormRepository $repository,
        private readonly FormDefinitionProcessor $processor,
        private readonly FormDataValidator $validator,
        private readonly FormEnvelope $envelope,
    ) {}

    #[Route('/api/forms/{id}/data', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function save(string $id, Request $request): JsonResponse
    {
        $json = $request->getContent();

        $this->repository->transactional(function () use ($id, $json): void {
            $record = $this->repository->getForUpdate($id);

            if ($record->status() === FormStatus::Confirmed) {
                throw new ProblemException(409, 'form-locked', 'Form data is confirmed and can no longer be edited.');
            }

            $definition = $this->processor->fromStored($record->definition);
            $this->validator->validateDraft($definition, $json);
            $this->repository->updateDraft($id, $json);
        });

        return new JsonResponse($this->envelope->build($this->repository->get($id)));
    }

    #[Route('/api/forms/{id}/confirm', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function confirm(string $id): JsonResponse
    {
        $this->repository->transactional(function () use ($id): void {
            $record = $this->repository->getForUpdate($id);

            if ($record->status() === FormStatus::Confirmed) {
                throw new ProblemException(409, 'form-already-confirmed', 'Form data is already confirmed.');
            }

            if ($record->data === null) {
                throw new ProblemException(409, 'form-data-empty', 'There is no data to confirm.');
            }

            $definition = $this->processor->fromStored($record->definition);
            $this->validator->validateFinal($definition, $record->data);
            $this->repository->confirm($id);
        });

        return new JsonResponse($this->envelope->build($this->repository->get($id)));
    }

    #[Route('/api/forms/{id}/data', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function read(string $id): JsonResponse
    {
        $record = $this->repository->get($id);

        if ($record->data === null) {
            throw new ProblemException(404, 'form-data-empty', 'The form has no data yet.');
        }

        return JsonResponse::fromJsonString($record->data);
    }
}

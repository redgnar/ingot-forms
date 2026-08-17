<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Ingot\Error\ErrorReport;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds RFC 9457 application/problem+json responses. Ingot error reports
 * are carried in an "errors" extension member: one {pointer, code, message,
 * input?} entry per {@see \Ingot\Error\MappingError}.
 */
final class ProblemResponseFactory
{
    private const string TYPE_PREFIX = 'urn:problem:ingot-forms:';

    public function simple(int $status, string $type, string $title, ?string $detail = null): JsonResponse
    {
        return $this->respond($status, $this->body($status, $type, $title, $detail));
    }

    public function fromReport(int $status, string $type, string $title, ErrorReport $report, ?string $detail = null): JsonResponse
    {
        $body = $this->body($status, $type, $title, $detail);
        $errors = [];

        foreach ($report as $error) {
            $entry = [
                'pointer' => $error->pointer->toString(),
                'code' => $error->code,
                'message' => $error->message,
            ];

            // Echo the offending value only when it is a scalar — reflecting
            // arbitrary submitted structures back inflates responses.
            if (\is_scalar($error->input)) {
                $entry['input'] = $error->input;
            }

            $errors[] = $entry;
        }

        $body['errors'] = $errors;

        return $this->respond($status, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $status, string $type, string $title, ?string $detail): array
    {
        $body = [
            'type' => self::TYPE_PREFIX . $type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): JsonResponse
    {
        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}

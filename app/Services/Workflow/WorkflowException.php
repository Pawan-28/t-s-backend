<?php

namespace App\Services\Workflow;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A DRF-shaped business-rule failure, e.g. HTTP 400 {"status": "Cannot change status ..."}.
 * (DRF keeps plain strings, not lists, for errors raised as ValidationError({"field": "msg"}).)
 */
class WorkflowException extends RuntimeException
{
    /** @param  array<string, mixed>  $body */
    public function __construct(public readonly array $body, public readonly int $httpStatus = 400)
    {
        parent::__construct((string) json_encode($body));
    }

    public static function field(string $field, string $message, int $status = 400): self
    {
        return new self([$field => $message], $status);
    }

    /** Expected client errors are not logged as server errors (true = already handled, skip default reporting). */
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json($this->body, $this->httpStatus);
    }
}

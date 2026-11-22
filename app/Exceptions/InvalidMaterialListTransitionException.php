<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidMaterialListTransitionException extends RuntimeException
{
    /**
     * @param  list<string>  $allowed
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $allowed,
    ) {
        parent::__construct("Cannot change status from {$from} to {$to}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'from' => $this->from,
            'to' => $this->to,
            'allowed' => $this->allowed,
        ], 422);
    }
}

<?php

namespace App\Exceptions;

use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ShiftConcurrencyException extends RuntimeException
{
    public function __construct(public readonly Shift $current)
    {
        parent::__construct('The shift changed. Refresh the calendar and try again.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current' => new ShiftResource($this->current->load(ShiftResource::relations())),
        ], 409);
    }
}

<?php

namespace App\Rules;

use App\Enums\ShiftStatus;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ShiftNoOverlapRule implements ValidationRule
{
    public function __construct(
        private readonly int $workerId,
        private readonly CarbonInterface $start,
        private readonly CarbonInterface $end,
        private readonly ?int $ignoreShiftId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $overlap = Shift::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('user_id', $this->workerId)
            ->where('status', '!=', ShiftStatus::Cancelled->value)
            ->when($this->ignoreShiftId, fn ($query, $id) => $query->whereKeyNot($id))
            ->overlapping($this->start, $this->end)
            ->exists();

        if ($overlap) {
            $fail('The worker already has an overlapping shift.');
        }
    }
}

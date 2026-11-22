<?php

namespace App\Services;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Http\Requests\StoreShiftRequest;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use App\Rules\ShiftNoOverlapRule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ShiftService
{
    public function eligibleWorkers(int $siteId, ShiftRole $role): Builder
    {
        return User::operational()->active()->role($role->logisticsRole()->value)
            ->whereHas('jobSites', fn (Builder $sites) => $sites->whereKey($siteId));
    }

    public function save(User $actor, ?Shift $shift, array $input): Shift
    {
        $data = Validator::make($input, StoreShiftRequest::fields())->validate();
        $start = Carbon::parse($data['starts_at'], 'UTC')->utc();
        $end = Carbon::parse($data['ends_at'], 'UTC')->utc();
        $role = ShiftRole::from($data['role_required']);

        return DB::transaction(function () use ($actor, $shift, $data, $start, $end, $role): Shift {
            // Serializes scheduling for the same worker, including across job sites.
            User::query()->lockForUpdate()->find($data['user_id']);
            $gate = Gate::forUser($actor);
            $record = $shift ? Shift::forUser($actor)->lockForUpdate()->findOrFail($shift->id) : new Shift;
            $gate->authorize($shift ? 'update' : 'create', $shift ? $record : Shift::class);
            $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($data['job_site_id']);
            $gate->authorize('assignToSite', [Shift::class, $site]);

            if (! $this->eligibleWorkers($site->id, $role)->whereKey($data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'The worker must be active, assigned to the job site and have the required role.']);
            }

            if (isset($data['job_scope_id']) && ! JobScope::where('job_site_id', $site->id)->whereKey($data['job_scope_id'])->exists()) {
                throw ValidationException::withMessages(['job_scope_id' => 'The scope must belong to the selected job site.']);
            }

            if ($shift && $record->attendance()->withoutGlobalScopes()->exists()
                && ($record->job_site_id !== $site->id || $record->user_id !== (int) $data['user_id'])) {
                throw ValidationException::withMessages(['user_id' => 'A shift with attendance keeps its original worker and job site.']);
            }

            $this->ensureNoOverlap((int) $data['user_id'], $start, $end, $shift?->id);

            $record->fill($data + ['job_scope_id' => null, 'notes' => null]);
            $record->forceFill([
                'starts_at' => $start,
                'ends_at' => $end,
                'required_role_id' => Role::findByName($role->logisticsRole()->value, 'web')->id,
                'updated_by' => $actor->id,
            ]);

            if (! $shift) {
                $record->created_by = $actor->id;
            }

            $record->save();

            return $record;
        }, attempts: 3);
    }

    public function ensureNoOverlap(int $workerId, CarbonInterface $start, CarbonInterface $end, ?int $shiftId, string $field = 'starts_at'): void
    {
        Validator::make([$field => $start], [
            $field => [new ShiftNoOverlapRule($workerId, $start, $end, $shiftId)],
        ])->validate();
    }

    public function cancel(User $actor, Shift $shift, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();

        DB::transaction(function () use ($actor, $shift, $reason): void {
            $record = Shift::forUser($actor)->lockForUpdate()->findOrFail($shift->id);
            Gate::forUser($actor)->authorize('cancel', $record);
            $record->forceFill([
                'status' => ShiftStatus::Cancelled,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
        }, attempts: 3);
    }

    public function assignMany(User $actor, array $data, array $workers): array
    {
        Validator::make(['workers' => $workers], [
            'workers' => ['required', 'array', 'max:100'],
            'workers.*' => ['required', 'integer', 'distinct'],
        ])->validate();

        $result = [];

        foreach ($workers as $worker) {
            try {
                $this->save($actor, null, ['user_id' => $worker] + $data);
                $result[$worker] = null;
            } catch (ValidationException $exception) {
                $result[$worker] = $exception->validator->errors()->first();
            }
        }

        return $result;
    }
}

<?php

namespace App\Actions;

use App\Http\Requests\SaveWorkerRequest;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ManageWorker
{
    public function save(User $actor, ?User $worker, array $data): User
    {
        return DB::transaction(function () use ($actor, $worker, $data): User {
            $record = $worker ? User::query()->lockForUpdate()->findOrFail($worker->getKey()) : new User;
            $gate = Gate::forUser($actor);
            $gate->authorize($worker ? 'update' : 'create', $worker ? $record : User::class);
            $managesProfile = ! $worker || $gate->allows('manageWorkerProfile', $record);

            if (! $managesProfile) {
                $gate->authorize('updateContactInfo', $record);
                $contactFields = SaveWorkerRequest::contactRules();
                $record->update(Validator::make(Arr::only($data, array_keys($contactFields)), $contactFields)->validate());

                return $record;
            }

            $data['email'] = Str::lower(trim((string) ($data['email'] ?? '')));
            $validated = Validator::make($data, SaveWorkerRequest::profileRules($worker, ! $worker))->validate();
            $role = Role::query()->where('guard_name', 'web')->whereIn('name', User::operationalRoles())
                ->findOrFail($validated['role_id']);

            $record->fill(Arr::except($validated, ['role_id']));
            if ($record->isDirty('email')) {
                $record->email_verified_at = null;
            }
            $record->save();
            $record->syncRoles([$role]);

            if ($record->primary_job_site_id) {
                $record->jobSites()->syncWithoutDetaching([$record->primary_job_site_id]);
            }

            return $record;
        });
    }

    public function setActive(User $actor, User $worker, bool $active): void
    {
        DB::transaction(function () use ($actor, $worker, $active): void {
            $record = User::query()->lockForUpdate()->findOrFail($worker->getKey());
            Gate::forUser($actor)->authorize('manageWorkerProfile', $record);
            $record->forceFill(['is_active' => $active])->save();
        });
    }
}

<?php

namespace App\Filament\Resources\Workers\Pages;

use App\Actions\ManageWorker;
use App\Filament\Resources\Workers\WorkerResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EditWorker extends EditRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $worker */
        $worker = $this->getRecord();
        $contact = [
            'phone' => $worker->phone,
            'address' => $worker->address,
            'emergency_contact' => $worker->emergency_contact,
        ];

        if (! Gate::allows('manageWorkerProfile', $worker)) {
            return $contact;
        }

        return $contact + $data + [
            'role_id' => $worker->roles->first()?->id,
            'hourly_rate' => $worker->hourly_rate,
            'insurance_reference' => $worker->insurance_reference,
            'identity_documents' => $worker->identity_documents ?? [],
            'osha_documents' => $worker->osha_documents ?? [],
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ManageWorker::class)->save(Auth::user(), $record, $data);
    }
}

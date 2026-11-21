<?php

namespace App\Filament\Resources\Workers\Pages;

use App\Actions\ManageWorker;
use App\Filament\Resources\Workers\WorkerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateWorker extends CreateRecord
{
    protected static string $resource = WorkerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ManageWorker::class)->save(Auth::user(), null, $data);
    }
}

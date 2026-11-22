<?php

namespace App\Filament\Resources\MaterialLists\Pages;

use App\Filament\Resources\MaterialLists\MaterialListResource;
use App\Models\JobScope;
use App\Services\MaterialListService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMaterialList extends CreateRecord
{
    protected static string $resource = MaterialListResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $scope = JobScope::query()->findOrFail($data['job_scope_id']);

        return app(MaterialListService::class)->create(Auth::user(), $scope, $data);
    }

    protected function getRedirectUrl(): string
    {
        return MaterialListResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}

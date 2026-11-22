<?php

namespace App\Filament\Resources\ChangeOrders\Pages;

use App\Filament\Resources\ChangeOrders\ChangeOrderResource;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Services\ChangeOrderAttachmentService;
use App\Services\ChangeOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateChangeOrder extends CreateRecord
{
    protected static string $resource = ChangeOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $files = $data['uploads'] ?? [];
        unset($data['uploads']);
        $site = JobSite::query()->findOrFail($data['job_site_id']);
        $scope = filled($data['job_scope_id'] ?? null) ? JobScope::query()->findOrFail($data['job_scope_id']) : null;
        $order = app(ChangeOrderService::class)->create(Auth::user(), $site, $scope, $data);
        app(ChangeOrderAttachmentService::class)->storeMany($order, Auth::user(), is_array($files) ? $files : [$files]);

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        return ChangeOrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}

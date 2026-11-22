<?php

namespace App\Filament\Resources\ChangeOrders\Pages;

use App\Filament\Resources\ChangeOrders\ChangeOrderResource;
use App\Models\ChangeOrder;
use App\Services\ChangeOrderAttachmentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditChangeOrder extends EditRecord
{
    protected static string $resource = ChangeOrderResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $files = $data['uploads'] ?? [];
        unset($data['uploads'], $data['job_site_id'], $data['job_scope_id']);
        /** @var ChangeOrder $record */
        $record->fill($data)->recordedBy(Auth::user())->save();
        app(ChangeOrderAttachmentService::class)->storeMany($record, Auth::user(), is_array($files) ? $files : [$files]);

        return $record->refresh();
    }
}

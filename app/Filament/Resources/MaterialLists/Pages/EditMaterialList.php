<?php

namespace App\Filament\Resources\MaterialLists\Pages;

use App\Filament\Resources\MaterialLists\MaterialListResource;
use App\Models\MaterialList;
use App\Services\MaterialListService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditMaterialList extends EditRecord
{
    protected static string $resource = MaterialListResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->getRecord()->items()->get()->map(fn ($item): array => [
            'material_id' => $item->material_id,
            'budgeted_quantity' => $item->budgeted_quantity,
            'requested_quantity' => $item->requestedQuantityForDisplay(),
            'unit_cost_cents' => $item->unit_cost_cents,
            'notes' => $item->notes,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MaterialList $record */
        return app(MaterialListService::class)->update($record, Auth::user(), $data);
    }
}

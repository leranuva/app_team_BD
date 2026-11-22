<?php

namespace App\Observers;

use App\Models\Material;
use Illuminate\Support\Arr;

class MaterialObserver
{
    public function updated(Material $material): void
    {
        if (! Material::auditsManualEdits()) {
            return;
        }

        $changes = Arr::only($material->getChanges(), ['name', 'unit', 'category', 'is_active']);
        if ($changes === []) {
            return;
        }

        $activity = activity('materials')
            ->performedOn($material)
            ->event('updated')
            ->withProperties([
                'old' => Arr::only($material->getPrevious(), array_keys($changes)),
                'attributes' => $changes,
            ]);

        if ($user = auth()->user()) {
            $activity->causedBy($user);
        }

        $activity->log('material.updated');
    }
}

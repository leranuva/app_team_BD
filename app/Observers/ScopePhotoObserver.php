<?php

namespace App\Observers;

use App\Models\ScopePhoto;
use DomainException;
use Illuminate\Support\Arr;

class ScopePhotoObserver
{
    public function updating(ScopePhoto $photo): void
    {
        if (Arr::except($photo->getDirty(), ['caption', 'archived_at', 'updated_at']) !== []) {
            throw new DomainException('Photo metadata cannot be changed after upload');
        }
    }
}

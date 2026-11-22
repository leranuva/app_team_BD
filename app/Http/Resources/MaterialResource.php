<?php

namespace App\Http\Resources;

use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Material $material */
        $material = $this->resource;

        return [
            'id' => $material->id,
            'code' => $material->code,
            'name' => $material->name,
            'unit' => $material->unit,
            'category' => $material->category,
            'is_active' => $material->is_active,
            'notes' => filled($material->notes) ? $material->notes : null,
            'list_items_count' => (int) ($material->list_items_count ?? 0),
        ];
    }
}

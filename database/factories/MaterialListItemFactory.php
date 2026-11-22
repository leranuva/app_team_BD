<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialListItemFactory extends Factory
{
    protected $model = MaterialListItem::class;

    public function definition(): array
    {
        return [
            'material_list_id' => MaterialList::factory(),
            'material_id' => Material::factory(),
            'budgeted_quantity' => 1,
            'requested_quantity' => 2,
            'used_quantity' => 0,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialResource;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MaterialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);

        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'in:0,1,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $active = $filters['active'] ?? '1';
        $materials = Material::query()
            ->withCount('listItems')
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->byCategory($filters['category']))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->search($filters['search']))
            ->when($active === '1', fn ($query) => $query->active())
            ->when($active === '0', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return MaterialResource::collection($materials)->response();
    }

    public function show(int $material): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);

        $record = Material::query()->withCount('listItems')->find($material);
        abort_if($record === null, 404);
        Gate::authorize('view', $record);

        return (new MaterialResource($record))->response();
    }

    public function categories(): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);

        return response()->json(['data' => $this->distinctActive('category')]);
    }

    public function units(): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);

        return response()->json(['data' => $this->distinctActive('unit')]);
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    private function distinctActive(string $column): array
    {
        return Material::query()
            ->active()
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn (Material $material): array => [
                'value' => (string) $material->getAttribute($column),
                'label' => (string) $material->getAttribute($column),
                'count' => (int) $material->getAttribute('aggregate'),
            ])
            ->values()
            ->all();
    }
}

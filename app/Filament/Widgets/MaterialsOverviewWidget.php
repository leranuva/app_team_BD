<?php

namespace App\Filament\Widgets;

use App\Models\Material;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MaterialsOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Catálogo de materiales';

    protected int|array|null $columns = 4;

    public static function canView(): bool
    {
        return Auth::user()?->can('viewAny', Material::class) ?? false;
    }

    protected function getStats(): array
    {
        $top = DB::table('materials')
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->orderBy('category')
            ->limit(5)
            ->get()
            ->map(fn (object $row): string => $row->category.' ('.$row->total.')')
            ->implode(', ');

        return [
            Stat::make('Materiales', Material::query()->count()),
            Stat::make('Activos', Material::query()->active()->count()),
            Stat::make('Referenciados por listas', Material::query()->has('listItems')->count()),
            Stat::make('Top 5 categorías', $top !== '' ? $top : '—'),
        ];
    }
}

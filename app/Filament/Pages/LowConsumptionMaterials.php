<?php

namespace App\Filament\Pages;

use App\Exports\LowConsumptionMaterialsExport;
use App\Filament\Pages\Concerns\BuildsReportFilters;
use App\Models\JobScope;
use App\Models\User;
use App\Services\ReportExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class LowConsumptionMaterials extends Page
{
    use BuildsReportFilters;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|UnitEnum|null $navigationGroup = 'Reportes';

    protected static ?string $navigationLabel = 'Materiales con bajo consumo';

    protected static ?string $title = 'Materiales con bajo consumo';

    protected static ?int $navigationSort = 20;

    public function mount(): void
    {
        $this->filters = ['from' => null, 'until' => null, 'job_site_id' => null, 'job_scope_id' => null];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                Select::make('job_site_id')->label('Obra')->placeholder('Todas las obras visibles')
                    ->options(fn (): array => $this->siteOptions())->searchable()->live(),
                Select::make('job_scope_id')->label('Alcance')->placeholder('Todos')
                    ->options(fn (): array => $this->scopeOptions())->searchable(),
                DatePicker::make('from')->label('Entrega desde')->native(false),
                DatePicker::make('until')->label('Entrega hasta')->native(false),
            ])->statePath('filters')->columns(4)->live(),
            View::make('filament.reports.low-consumption')
                ->viewData(fn (): array => ['rows' => $this->rows()]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Descargar Excel')->action(function (): void {
                $this->notifyExport(app(ReportExportService::class)->exportExcel(
                    LowConsumptionMaterialsExport::class,
                    $this->filters,
                ));
            }),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        return (new LowConsumptionMaterialsExport(['actor_id' => $user->id] + $this->filters))
            ->query()
            ->limit(50)
            ->get()
            ->map(fn ($item): array => (new LowConsumptionMaterialsExport(['actor_id' => $user->id]))->map($item))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function scopeOptions(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        $siteId = $this->nullableInt($this->filters['job_site_id'] ?? null);

        return JobScope::query()->visibleTo($user)
            ->when($siteId !== null, fn ($query) => $query->where('job_site_id', $siteId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

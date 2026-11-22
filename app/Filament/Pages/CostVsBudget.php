<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsReportFilters;
use App\Models\User;
use App\Services\ReportExportService;
use App\Services\ReportQueryService;
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

class CostVsBudget extends Page
{
    use BuildsReportFilters;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Actual vs. budgeted cost';

    protected static ?string $title = 'Actual vs. budgeted cost';

    protected static ?int $navigationSort = 40;

    public function mount(): void
    {
        $this->filters = ['from' => null, 'until' => null, 'job_site_id' => null];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                Select::make('job_site_id')->label('Job site')->placeholder('All visible job sites')
                    ->options(fn (): array => $this->siteOptions())->searchable(),
                DatePicker::make('from')->label('From')->native(false),
                DatePicker::make('until')->label('To')->native(false),
            ])->statePath('filters')->columns(3)->live(),
            View::make('filament.reports.cost-vs-budget')
                ->viewData(fn (): array => ['report' => $this->report()]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Download PDF')->action(function (): void {
                $report = $this->report();
                $this->notifyExport(app(ReportExportService::class)->exportPdf(
                    'reports.cost-vs-budget',
                    $report,
                    'cost-vs-budget-'.$report['generated_at']->toDateString().'.pdf',
                ));
            }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return ['sites' => [], 'totals' => ['budget' => '0.00', 'real_cost' => '0.00', 'difference' => '0.00'], 'unpriced_items' => 0, 'range_label' => '', 'generated_at' => now()];
        }

        return app(ReportQueryService::class)->costVsBudget($user, $this->filters);
    }
}

<?php

namespace App\Filament\Pages;

use App\Enums\ChangeOrderStatus;
use App\Exports\PendingChangeOrdersExport;
use App\Filament\Pages\Concerns\BuildsReportFilters;
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

class PendingChangeOrders extends Page
{
    use BuildsReportFilters;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Pending change orders';

    protected static ?string $title = 'Pending change orders';

    protected static ?int $navigationSort = 30;

    public function mount(): void
    {
        $this->filters = ['from' => null, 'until' => null, 'job_site_id' => null, 'status' => null];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                Select::make('job_site_id')->label('Job site')->placeholder('All visible job sites')
                    ->options(fn (): array => $this->siteOptions())->searchable(),
                Select::make('status')->label('Status')->placeholder('Submitted and under review')->options([
                    ChangeOrderStatus::Submitted->value => ChangeOrderStatus::Submitted->label(),
                    ChangeOrderStatus::UnderReview->value => ChangeOrderStatus::UnderReview->label(),
                ]),
                DatePicker::make('from')->label('Submitted from')->native(false),
                DatePicker::make('until')->label('Submitted through')->native(false),
            ])->statePath('filters')->columns(4)->live(),
            View::make('filament.reports.pending-change-orders')
                ->viewData(fn (): array => ['rows' => $this->rows()]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Download Excel')->action(function (): void {
                $this->notifyExport(app(ReportExportService::class)->exportExcel(
                    PendingChangeOrdersExport::class,
                    $this->filters,
                ));
            }),
        ];
    }

    /**
     * @return list<array<int, string|int>>
     */
    public function rows(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        $export = new PendingChangeOrdersExport(['actor_id' => $user->id] + $this->filters);

        return $export->query()->limit(50)->get()
            ->map(fn ($order): array => $export->map($order))
            ->all();
    }
}

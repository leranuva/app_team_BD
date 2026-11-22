<?php

use App\Enums\LogisticsRole;
use App\Exports\LowConsumptionMaterialsExport;
use App\Exports\PendingChangeOrdersExport;
use App\Filament\Pages\CostVsBudget;
use App\Filament\Pages\LowConsumptionMaterials;
use App\Filament\Pages\PendingChangeOrders;
use App\Filament\Widgets\HoursThisWeekWidget;
use App\Filament\Widgets\LowConsumptionWidget;
use App\Filament\Widgets\PendingApprovalsWidget;
use App\Jobs\GenerateReportExport;
use App\Models\Attendance;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\ReportExport;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ReportReadyNotification;
use App\Services\ReportExportService;
use App\Services\ReportQueryService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\SheetHeadingImport;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'America/New_York'));
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->carpenter = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->site = JobSite::factory()->create(['name' => 'Queens', 'client' => 'Acme']);
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Encofrado']);
    $this->outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->outsider->jobSites()->attach(JobSite::factory()->create(['name' => 'Bronx ajena']));
    $this->actingAs($this->admin);
});

it('exports materials under the consumption threshold and hides other sites', function (): void {
    $list = MaterialList::factory()->delivered()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'requested_by' => $this->admin->id,
    ]);
    reportMaterial($list, 'CEM', 'Cemento', '4.000');
    reportMaterial($list, 'ARE', 'Arena', '0.000');
    reportMaterial($list, 'LAD', 'Ladrillo', '8.000');

    $rows = app(ReportQueryService::class)->lowConsumptionMaterials($this->admin, [])->get();
    expect($rows)->toHaveCount(2)
        ->and(app(ReportQueryService::class)->lowConsumptionMaterials($this->outsider, [])->count())->toBe(0);

    Storage::fake('exports');
    $stored = app(ReportExportService::class)->exportExcel(LowConsumptionMaterialsExport::class, ['job_site_id' => $this->site->id], $this->admin);
    $sheet = Excel::toArray(new SheetHeadingImport, $stored['path'], 'exports')[0];
    expect(collect($sheet)->pluck('material')->all())->toBe(['CEM Cemento', 'ARE Arena'])
        ->and($stored['url'])->not->toBe('');

    Livewire::test(LowConsumptionMaterials::class)->assertSee('Cemento')->assertDontSee('Ladrillo')->callAction('export')->assertNotified('Report ready');
    $this->actingAs($this->carpenter);
    Livewire::test(LowConsumptionMaterials::class)->assertForbidden();
});

it('exports pending change orders and leaves drafts out', function (): void {
    ChangeOrder::factory()->submitted()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'created_by' => $this->admin->id,
        'code' => 'CO-1', 'amount_cents' => -2500,
    ]);
    ChangeOrder::factory()->underReview()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'created_by' => $this->admin->id, 'code' => 'CO-2',
    ]);
    ChangeOrder::factory()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'created_by' => $this->admin->id, 'code' => 'CO-3',
    ]);

    expect(app(ReportQueryService::class)->pendingChangeOrders($this->admin, [])->count())->toBe(2)
        ->and(app(ReportQueryService::class)->pendingChangeOrders($this->admin, ['status' => 'submitted'])->count())->toBe(1)
        ->and(app(ReportQueryService::class)->pendingChangeOrders($this->outsider, [])->count())->toBe(0);

    Storage::fake('exports');
    $stored = app(ReportExportService::class)->exportExcel(PendingChangeOrdersExport::class, [], $this->admin);
    $sheet = Excel::toArray(new SheetHeadingImport, $stored['path'], 'exports')[0];
    expect(collect($sheet)->pluck('code')->sort()->values()->all())->toBe(['CO-1', 'CO-2'])
        ->and((string) collect($sheet)->firstWhere('code', 'CO-1')['amount'])->toBe('-25');

    Livewire::test(PendingChangeOrders::class)->assertSee('CO-1')->assertDontSee('CO-3')->callAction('export')->assertNotified('Report ready');
    $this->actingAs($this->carpenter);
    Livewire::test(PendingChangeOrders::class)->assertForbidden();
});

it('compares real cost with the site budget and notes missing prices', function (): void {
    $bronx = JobSite::factory()->create(['name' => 'Bronx', 'client' => 'North', 'base_budget' => 2000]);
    $soho = JobSite::factory()->create(['name' => 'Soho', 'client' => 'South', 'base_budget' => 0]);
    $this->site->update(['base_budget' => 1000]);
    foreach ([[$this->site, $this->scope, 150000], [$bronx, null, 50000], [$soho, null, 10000]] as [$site, $scope, $cents]) {
        ChangeOrder::factory()->approved()->create([
            'job_site_id' => $site->id,
            'job_scope_id' => $scope?->id ?? JobScope::query()->create(['job_site_id' => $site->id, 'name' => 'Scope'])->id,
            'created_by' => $this->admin->id,
            'amount_cents' => $cents,
        ]);
    }
    ChangeOrder::factory()->approved()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'created_by' => $this->admin->id,
        'amount_cents' => 999999,
        'approved_at' => now()->subMonth(),
    ]);
    $list = MaterialList::factory()->delivered()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'requested_by' => $this->admin->id,
    ]);
    reportMaterial($list, 'CEM', 'Cemento', '0.000', '2.000', 2500);
    reportMaterial($list, 'SIN', 'Sin precio', '0.000', '5.000', null);

    $report = app(ReportQueryService::class)->costVsBudget($this->admin, ['from' => '2026-11-23', 'until' => '2026-11-23']);
    $byName = collect($report['sites'])->keyBy('name');
    expect($byName['Queens']['real_cost'])->toBe('1550.00')
        ->and($byName['Queens']['difference'])->toBe('550.00')
        ->and($byName['Queens']['percent_executed'])->toBe('155.00')
        ->and($byName['Bronx']['real_cost'])->toBe('500.00')
        ->and($byName['Bronx']['difference'])->toBe('-1500.00')
        ->and($byName['Soho']['percent_executed'])->toBeNull()
        ->and($byName['Soho']['budget'])->toBeNull()
        ->and($report['totals'])->toBe(['budget' => '3000.00', 'real_cost' => '2150.00', 'difference' => '-850.00'])
        ->and($report['unpriced_items'])->toBe(1)
        ->and(collect(app(ReportQueryService::class)->costVsBudget($this->outsider, [])['sites'])->pluck('name'))->not->toContain('Queens');

    $html = view('reports.cost-vs-budget', $report)->render();
    expect($html)->toContain('Queens')->toContain('1550.00')->toContain('No budget')->toContain('1 item was excluded');

    Storage::fake('exports');
    $stored = app(ReportExportService::class)->exportPdf('reports.cost-vs-budget', $report, 'costo.pdf', $this->admin);
    expect(Storage::disk('exports')->get($stored['path']))->toStartWith('%PDF')
        ->and(strlen(Storage::disk('exports')->get($stored['path'])))->toBeGreaterThan(100);

    Livewire::test(CostVsBudget::class)->assertSee('Queens')->callAction('export')->assertNotified('Report ready');
    $this->actingAs($this->carpenter);
    Livewire::test(CostVsBudget::class)->assertForbidden();
});

it('queues an excel export above the threshold and serves a signed download', function (): void {
    $list = MaterialList::factory()->delivered()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'requested_by' => $this->admin->id,
    ]);
    reportMaterial($list, 'CEM', 'Cemento', '1.000');
    config(['reports.sync_threshold' => 0]);
    Bus::fake();
    $queued = app(ReportExportService::class)->exportExcel(LowConsumptionMaterialsExport::class, [], $this->admin);
    expect($queued)->toHaveKey('job_id');
    Bus::assertDispatched(GenerateReportExport::class, fn (GenerateReportExport $job): bool => $job->queue === 'reports' && $job->jobId === $queued['job_id']);

    config(['reports.sync_threshold' => 1000]);
    Storage::fake('exports');
    Notification::fake();
    $job = new GenerateReportExport($this->admin->id, LowConsumptionMaterialsExport::class, ['actor_id' => $this->admin->id], (string) Str::uuid());
    app()->call([$job, 'handle']);
    Notification::assertSentTo($this->admin, ReportReadyNotification::class);
    $record = ReportExport::query()->firstOrFail();

    $this->getJson(route('api.reports.download', $record))->assertOk()->assertJsonStructure(['url']);
    $this->actingAs($this->outsider)->getJson(route('api.reports.download', $record))->assertForbidden();
    $this->actingAs($this->admin)->getJson(route('api.reports.download', (string) Str::uuid()))->assertNotFound();
    $record->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->getJson(route('api.reports.download', $record))->assertNotFound();
});

it('deletes expired report files and keeps the fresh ones', function (): void {
    Storage::fake('exports');
    Storage::disk('exports')->put('old.xlsx', 'old');
    Storage::disk('exports')->put('new.xlsx', 'new');
    $old = ReportExport::query()->create([
        'user_id' => $this->admin->id, 'path' => 'old.xlsx', 'type' => 'excel',
        'report_class' => LowConsumptionMaterialsExport::class, 'filters' => [], 'expires_at' => now()->subDay(),
    ]);
    $fresh = ReportExport::query()->create([
        'user_id' => $this->admin->id, 'path' => 'new.xlsx', 'type' => 'excel',
        'report_class' => LowConsumptionMaterialsExport::class, 'filters' => [], 'expires_at' => now()->addDays(6),
    ]);

    $this->artisan('reports:cleanup')->assertSuccessful();

    Storage::disk('exports')->assertMissing('old.xlsx');
    Storage::disk('exports')->assertExists('new.xlsx');
    expect(ReportExport::query()->find($old->id))->toBeNull()
        ->and(ReportExport::query()->find($fresh->id))->not->toBeNull();
});

it('renders the report widgets from the same queries', function (): void {
    $list = MaterialList::factory()->submitted()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'requested_by' => $this->admin->id,
    ]);
    $delivered = MaterialList::factory()->delivered()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'requested_by' => $this->admin->id,
    ]);
    reportMaterial($delivered, 'CEM', 'Cemento', '1.000');
    ChangeOrder::factory()->submitted()->create([
        'job_site_id' => $this->site->id, 'job_scope_id' => $this->scope->id, 'created_by' => $this->admin->id, 'code' => 'CO-9',
    ]);
    $shift = Shift::factory()->for($this->site, 'jobSite')->for($this->carpenter, 'worker')->create([
        'starts_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-11-23 16:00:00', 'America/New_York'),
    ]);
    Attendance::factory()->for($shift, 'shift')->create([
        'clocked_out_at' => $shift->ends_at, 'regular_minutes' => 480, 'overtime_minutes' => 60, 'double_time_minutes' => 0,
    ]);

    Livewire::test(HoursThisWeekWidget::class)->assertSee('Queens')->assertSee('8.00')->assertSee('1.00');
    Livewire::test(PendingApprovalsWidget::class)->assertSee('Pending change orders')->assertSee('Pending lists');
    Livewire::test(LowConsumptionWidget::class)->assertSee('Cemento')->assertSee('View report');
    expect($list->status->value)->toBe('submitted');
});

function reportMaterial(MaterialList $list, string $code, string $name, string $consumed, string $delivered = '10.000', ?int $cents = 1000): MaterialListItem
{
    return MaterialListItem::factory()->create([
        'material_list_id' => $list->id,
        'material_id' => Material::factory()->create(['code' => $code, 'name' => $name, 'unit' => 'bolsa'])->id,
        'delivered_quantity' => $delivered,
        'consumed_quantity' => $consumed,
        'requested_quantity' => $delivered,
        'unit_cost_cents' => $cents,
    ]);
}

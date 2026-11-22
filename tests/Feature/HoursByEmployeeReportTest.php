<?php

use App\Enums\LogisticsRole;
use App\Exports\HoursByEmployeeExport;
use App\Filament\Pages\HoursByEmployee;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportQueryService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\SheetHeadingImport;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'America/New_York'));
    $this->site = JobSite::factory()->create(['name' => 'Queens']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->outsider->jobSites()->attach(JobSite::factory()->create());
});

it('exports closed hours for three workers and skips an open punch', function (): void {
    hoursReportPunch('Luis Peña', 8, 0, null);
    hoursReportPunch('Marta Díaz', 8, 60, null);
    hoursReportPunch('Pedro Cruz', 8, 0, '25.00', corrected: true);
    hoursReportPunch('Nora Vega', 8, 0, null, at: '2026-11-16 08:00:00');
    $open = hoursReportPunch('Luis Peña', 8, 0, null);
    $open->forceFill(['clocked_out_at' => null, 'regular_minutes' => 480])->save();

    $from = CarbonImmutable::parse('2026-11-23', 'America/New_York');
    $until = CarbonImmutable::parse('2026-11-29', 'America/New_York');
    $rows = app(ReportQueryService::class)->hoursByEmployee($this->admin, $from, $until, null, null);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('worker')->all())->toBe(['Luis Peña', 'Marta Díaz', 'Pedro Cruz'])
        ->and($rows->firstWhere('worker', 'Marta Díaz')['overtime_hours'])->toBe('1.00')
        ->and($rows->firstWhere('worker', 'Marta Díaz')['total_hours'])->toBe('9.00')
        ->and($rows->firstWhere('worker', 'Pedro Cruz')['cost'])->toBe('200.00')
        ->and($rows->firstWhere('worker', 'Luis Peña')['cost'])->toBe('—')
        ->and($rows->firstWhere('worker', 'Luis Peña')['regular_hours'])->toBe('8.00')
        ->and(app(ReportQueryService::class)->hoursByEmployee($this->outsider, $from, $until, null, null))->toHaveCount(0);

    Excel::store(new HoursByEmployeeExport($rows), 'reports/hours.xlsx', 'local');
    $sheet = Excel::toArray(new SheetHeadingImport, 'reports/hours.xlsx', 'local')[0];
    Storage::disk('local')->delete('reports/hours.xlsx');

    expect($sheet)->toHaveCount(3)
        ->and(collect($sheet)->pluck('trabajador')->all())->toBe(['Luis Peña', 'Marta Díaz', 'Pedro Cruz'])
        ->and((string) collect($sheet)->firstWhere('trabajador', 'Marta Díaz')['horas_extra'])->toBe('1')
        ->and((string) collect($sheet)->firstWhere('trabajador', 'Pedro Cruz')['costo_usd'])->toBe('200');

    $this->actingAs($this->admin);
    Livewire::test(HoursByEmployee::class)
        ->assertSee('Luis Peña')
        ->assertSee('Marta Díaz')
        ->assertSee('Pedro Cruz')
        ->assertActionExists('export');
});

function hoursReportPunch(string $name, int $hours, int $overtimeMinutes, ?string $rate, string $at = '2026-11-23 08:00:00', bool $corrected = false): Attendance
{
    $worker = User::query()->where('name', $name)->first()
        ?? User::factory()->create(['name' => $name, 'hourly_rate' => $rate])->assignRole(LogisticsRole::Carpenter->value);
    $start = CarbonImmutable::parse($at, 'America/New_York');
    $shift = Shift::factory()->for(test()->site, 'jobSite')->for($worker, 'worker')->create([
        'starts_at' => $start,
        'ends_at' => $start->addHours($hours),
    ]);

    return Attendance::factory()->for($shift, 'shift')->create([
        'clocked_out_at' => $start->addHours($hours),
        'regular_minutes' => $hours * 60,
        'overtime_minutes' => $overtimeMinutes,
        'double_time_minutes' => 0,
        'duration_minutes' => ($hours * 60) + $overtimeMinutes,
        'corrected_by' => $corrected ? test()->admin->id : null,
        'correction_reason' => $corrected ? 'Cierre corregido por el supervisor' : null,
    ]);
}

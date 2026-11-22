<?php

namespace Database\Seeders;

use App\Enums\LogisticsRole;
use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\Attendance;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\ScopePhoto;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceCalculatorService;
use App\Services\AttendanceService;
use App\Services\ChangeOrderAttachmentService;
use App\Services\ChangeOrderService;
use App\Services\MaterialListService;
use App\Services\ScopePhotoService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class DemoOperationsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || config('filesystems.disks.s3.driver') !== 'local') {
            throw new RuntimeException('Demo data requires a local or testing environment with JOB_FILES_DRIVER=local.');
        }

        $this->call(DatabaseSeeder::class);
        $password = getenv('BLUE_DRAFT_DEMO_PASSWORD') ?: Str::password(24);
        $now = CarbonImmutable::now(config('app.business_timezone'))->startOfMinute();
        $admin = $this->account('admin', 'Demo Operations Manager', LogisticsRole::Administrator, $password);

        $materials = [
            Material::firstOrCreate(['code' => 'DEMO-MAT-001'], ['name' => 'Fire-rated drywall, 5/8 in', 'unit' => 'Sheet', 'category' => 'Drywall', 'is_active' => true]),
            Material::firstOrCreate(['code' => 'DEMO-MAT-002'], ['name' => 'Metal stud, 3-5/8 in', 'unit' => 'pcs', 'category' => 'Steel & Metal', 'is_active' => true]),
            Material::firstOrCreate(['code' => 'DEMO-MAT-003'], ['name' => 'Drywall screws, #6', 'unit' => 'box', 'category' => 'Fasteners', 'is_active' => true]),
            Material::firstOrCreate(['code' => 'DEMO-MAT-004'], ['name' => 'Joint compound, 5 gal', 'unit' => 'pail', 'category' => 'Drywall', 'is_active' => true]),
        ];

        $mail = config('mail.default');
        $queue = config('queue.default');
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        try {
            $sites = [
                'brooklyn' => ['Brooklyn Fit-Out', '358 Amboy Street, Brooklyn, NY', 40.6624, -73.9138, 185000, 'active'],
                'manhattan' => ['Financial District Renovation', '120 Broadway, New York, NY 10271', 40.708233, -74.010542, 320000, 'active'],
                'queens' => ['Long Island City Workshop', '21-00 49th Avenue, Queens, NY', 40.742, -73.948, 97000, 'planned'],
            ];

            foreach ($sites as $slug => [$name, $address, $latitude, $longitude, $budget, $status]) {
                $site = JobSite::firstOrCreate(['name' => "Demo | {$name}"], [
                    'client' => 'Demo construction client',
                    'address' => $address, 'latitude' => $latitude, 'longitude' => $longitude,
                    'base_budget' => $budget, 'status' => $status, 'geofence_meters' => 150,
                    'start_date' => $now->subWeeks(3)->toDateString(),
                    'estimated_end_date' => $now->addWeeks(8)->toDateString(),
                    'notes' => 'Fictional project for application testing.',
                ]);
                $supervisor = $this->account("{$slug}.supervisor", "{$name} Supervisor", LogisticsRole::Supervisor, $password, $site, '48.00');
                $carpenter = $this->account("{$slug}.carpenter", "{$name} Carpenter", LogisticsRole::Carpenter, $password, $site, '37.50');
                $helper = $this->account("{$slug}.helper", "{$name} Helper", LogisticsRole::Helper, $password, $site, '25.00');
                $site->users()->syncWithoutDetaching([$supervisor->id, $carpenter->id, $helper->id]);

                $scope = $this->scope($site, $supervisor, 'Interior framing', 'in_progress', 45, $now);
                $this->scope($site, $supervisor, 'Site preparation', 'completed', 100, $now);
                $this->scope($site, $supervisor, 'Finish and punch list', 'pending', 0, $now);
                $this->schedule($site, $scope, $admin, $supervisor, $carpenter, $helper, $now);
                $this->materialLists($scope, $supervisor, $admin, $carpenter, $materials);
                $this->changeOrders($site, $scope, $supervisor, $admin);
                $this->photos($scope, $supervisor, $now);
            }
        } finally {
            config(['mail.default' => $mail, 'queue.default' => $queue]);
        }
    }

    private function account(string $key, string $name, LogisticsRole $role, string $password, ?JobSite $site = null, ?string $rate = null): User
    {
        $user = User::firstOrCreate(['email' => "demo.{$key}@bluedraft.example"], [
            'name' => $name,
            'password' => $password,
            'primary_job_site_id' => $site?->id,
            'hourly_rate' => $rate,
        ]);

        if ($user->wasRecentlyCreated) {
            $user->assignRole($role->value);
        }

        return $user;
    }

    private function scope(JobSite $site, User $supervisor, string $name, string $status, int $progress, CarbonImmutable $now): JobScope
    {
        return JobScope::firstOrCreate(['job_site_id' => $site->id, 'name' => $name], [
            'supervisor_id' => $supervisor->id, 'status' => $status, 'progress' => $progress,
            'description' => "Demo scope: {$name}.",
            'start_date' => $now->subWeek()->toDateString(),
            'estimated_end_date' => $now->addWeeks(3)->toDateString(),
        ]);
    }

    private function schedule(JobSite $site, JobScope $scope, User $admin, User $supervisor, User $carpenter, User $helper, CarbonImmutable $now): void
    {
        $history = [];
        $calculator = app(AttendanceCalculatorService::class);
        for ($daysAgo = 8; $daysAgo >= 1; $daysAgo--) {
            $start = $now->subDays($daysAgo)->setTime(7, 30);
            $end = $start->addHours(9);
            $shift = $this->shift($site, $scope, $admin, $carpenter, ShiftRole::Carpenter, ShiftStatus::Completed, $start, $end, 'Historical shift');
            $attendance = Attendance::firstOrNew(['shift_id' => $shift->id], [
                'user_id' => $carpenter->id, 'job_site_id' => $site->id,
                'clocked_in_at' => $start->utc(), 'clocked_out_at' => $end->utc(),
                'in_latitude' => $site->latitude, 'in_longitude' => $site->longitude,
                'out_latitude' => $site->latitude, 'out_longitude' => $site->longitude,
            ]);
            if (! $attendance->exists) {
                $attendance->recordedBy($carpenter)->forceFill($calculator->calculate($start, $end, $history))->save();

                if ($daysAgo === 2) {
                    $attendance = app(AttendanceService::class)->flagManualCorrection(
                        $attendance, $supervisor, $start->utc(), $end->subMinutes(15)->utc(), 'Demo correction: verified early departure.',
                    );
                }
            }
            $history[] = $attendance;
        }

        $this->shift($site, $scope, $admin, $carpenter, ShiftRole::Carpenter, ShiftStatus::Confirmed, $now, $now->addHours(8), 'Ready to clock in');
        if (! Attendance::query()->where('user_id', $helper->id)->whereNull('clocked_out_at')->exists()) {
            $start = $now->subHours(7);
            $open = $this->shift($site, $scope, $admin, $helper, ShiftRole::Helper, ShiftStatus::Confirmed, $start, $now->addMinutes(10), 'Ready to clock out');
            Attendance::firstOrCreate(['shift_id' => $open->id], [
                'user_id' => $helper->id, 'job_site_id' => $site->id, 'clocked_in_at' => $open->starts_at,
                'in_latitude' => $site->latitude, 'in_longitude' => $site->longitude,
            ]);
        }
        $next = $now->addDays(2)->setTime(8, 0);
        $this->shift($site, $scope, $admin, $supervisor, ShiftRole::Supervisor, ShiftStatus::Programmed, $next, $next->addHours(8), 'Upcoming supervision');
        $canceled = $this->shift($site, $scope, $admin, $helper, ShiftRole::Helper, ShiftStatus::Programmed, $next, $next->addHours(4), 'Canceled delivery shift');
        if ($canceled->status !== ShiftStatus::Cancelled) {
            app(ShiftService::class)->cancel($admin, $canceled, 'Demo cancellation: delivery moved.');
        }
    }

    private function shift(JobSite $site, JobScope $scope, User $admin, User $worker, ShiftRole $role, ShiftStatus $status, CarbonImmutable $start, CarbonImmutable $end, string $label): Shift
    {
        $notes = 'Demo | '.$label.' | '.$start->toDateString();

        return Shift::query()->where('job_site_id', $site->id)->where('user_id', $worker->id)->where('notes', $notes)->first()
            ?? app(ShiftService::class)->save($admin, null, [
                'job_site_id' => $site->id, 'job_scope_id' => $scope->id, 'user_id' => $worker->id,
                'starts_at' => $start->utc()->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String(),
                'role_required' => $role->value, 'status' => $status->value, 'notes' => $notes,
            ]);
    }

    private function materialLists(JobScope $scope, User $supervisor, User $admin, User $worker, array $materials): void
    {
        $service = app(MaterialListService::class);
        foreach (['Draft', 'Submitted', 'Approved', 'Ordered', 'Delivered', 'Partially consumed', 'Consumed', 'Canceled'] as $stage) {
            $title = "Demo | {$stage} material list";
            if (MaterialList::query()->whereBelongsTo($scope, 'jobScope')->where('title', $title)->exists()) {
                continue;
            }

            $list = $service->create($supervisor, $scope, [
                'title' => $title,
                'items' => array_map(fn (Material $material, int $index): array => [
                    'material_id' => $material->id, 'budgeted_quantity' => 24, 'requested_quantity' => 20,
                    'unit_cost_cents' => [1875, 790, 1450, null][$index],
                ], $materials, array_keys($materials)),
            ]);
            $list->forceFill(['assigned_to' => $worker->id])->save();
            if ($stage === 'Draft') {
                continue;
            }
            if ($stage === 'Canceled') {
                $service->cancel($list, $admin, 'Demo cancellation: material already on site.');

                continue;
            }

            $list = $service->submit($list, $supervisor);
            if ($stage === 'Submitted') {
                continue;
            }
            $list = $service->approve($list, $admin);
            if ($stage === 'Approved') {
                continue;
            }
            $list = $service->markOrdered($list, $admin);
            if ($stage === 'Ordered') {
                continue;
            }
            $quantities = $list->items()->pluck('approved_quantity', 'id')->all();
            $list = $service->registerDelivery($list, $admin, $quantities);
            if ($stage !== 'Delivered') {
                $service->registerConsumption($list, $admin, $stage === 'Consumed' ? $quantities : array_fill_keys(array_keys($quantities), 5));
            }
        }
    }

    private function changeOrders(JobSite $site, JobScope $scope, User $supervisor, User $admin): void
    {
        $service = app(ChangeOrderService::class);
        foreach (['Draft', 'Submitted', 'Under review', 'Approved', 'Rejected', 'Canceled'] as $stage) {
            $title = "Demo | {$stage} change order";
            if (ChangeOrder::query()->where('job_site_id', $site->id)->where('title', $title)->exists()) {
                continue;
            }

            $order = $service->create($supervisor, $site, $scope, [
                'title' => $title, 'description' => 'Demo relocation of a non-structural interior partition.',
                'amount_cents' => 125000, 'time_impact_days' => 2,
            ]);
            if ($stage === 'Draft') {
                app(ChangeOrderAttachmentService::class)->storeMany($order, $supervisor, [
                    new UploadedFile(public_path('brand/icon-192.png'), 'demo-reference.png', 'image/png', null, true),
                ]);

                continue;
            }
            if ($stage === 'Canceled') {
                $service->cancel($order, $admin, 'Demo cancellation: original scope retained.');

                continue;
            }
            $order = $service->submit($order, $supervisor);
            if ($stage === 'Submitted') {
                continue;
            }
            $order = $service->startReview($order, $admin);
            match ($stage) {
                'Approved' => $service->approve($order, $admin, 'Demo approval.'),
                'Rejected' => $service->reject($order, $admin, 'Demo rejection: change outside approved budget.'),
                default => null,
            };
        }
    }

    private function photos(JobScope $scope, User $supervisor, CarbonImmutable $now): void
    {
        $service = app(ScopePhotoService::class);
        foreach (['Demo | Progress reference', 'Demo | Archived reference'] as $caption) {
            $photo = ScopePhoto::query()->where('job_scope_id', $scope->id)->where('caption', $caption)->first();
            if ($photo !== null) {
                continue;
            }
            $photo = $service->store($scope, $supervisor, new UploadedFile(
                resource_path('images/worksite.webp'), 'demo-worksite.webp', 'image/webp', null, true,
            ), ['caption' => $caption, 'taken_at' => $now->subDay()->toIso8601String()]);
            if ($caption === 'Demo | Archived reference') {
                $service->archive($photo, $supervisor, 'Demo archived photo.');
            }
        }
    }
}

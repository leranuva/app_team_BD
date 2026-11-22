<?php

use App\Enums\ChangeOrderStatus;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderAttachment;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use App\Services\ChangeOrderAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls the change order migration back and forward', function (): void {
    $migration = require database_path('migrations/2026_11_21_250000_extend_change_orders.php');
    $migration->down();

    expect(Schema::hasTable('change_order_attachments'))->toBeFalse()
        ->and(Schema::hasTable('change_order_counters'))->toBeFalse()
        ->and(Schema::hasColumn('change_orders', 'amount_cents'))->toBeFalse()
        ->and(Schema::hasColumn('job_scopes', 'approved_change_orders_cents'))->toBeFalse()
        ->and(Schema::hasColumn('job_sites', 'approved_time_impact_days'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('change_orders', 'code'))->toBeTrue()
        ->and(Schema::hasColumn('job_scopes', 'approved_change_orders_cents'))->toBeTrue()
        ->and(Schema::hasTable('change_order_counters'))->toBeTrue();
});

it('casts the order and limits editing to a draft', function (): void {
    $order = ChangeOrder::factory()->create(['amount_cents' => -2500, 'time_impact_days' => -1]);

    expect($order->status)->toBe(ChangeOrderStatus::Draft)
        ->and($order->isEditable())->toBeTrue()
        ->and($order->amountInDollars())->toBe(-25.0)
        ->and($order->isFinancialImpactPositive())->toBeFalse()
        ->and($order->fresh()->amount_cents)->toBe(-2500);

    $order->status = ChangeOrderStatus::Submitted;
    expect($order->isEditable())->toBeFalse()
        ->and($order->canBeSubmitted())->toBeFalse()
        ->and(ChangeOrder::query()->pendingReview()->whereKey($order->id)->exists())->toBeFalse();

    $order->save();
    expect(ChangeOrder::query()->forSite($order->job_site_id)->forScope($order->job_scope_id)->pendingReview()->count())->toBe(1);
});

it('hides the attachment when the order is deleted and keeps the object', function (): void {
    $disk = Storage::fake('s3');
    $disk->buildTemporaryUrlsUsing(fn (string $path): string => 'https://files.test/'.$path);
    $order = ChangeOrder::factory()->create();
    $user = User::factory()->create();
    $attachment = app(ChangeOrderAttachmentService::class)->storeMany(
        $order,
        $user,
        [UploadedFile::fake()->create('firmada.pdf', 20, 'application/pdf')],
    )[0];

    expect($attachment)->toBeInstanceOf(ChangeOrderAttachment::class)
        ->and($attachment->temporaryUrl())->toContain('change-orders/'.$order->id)
        ->and($disk->exists($attachment->path))->toBeTrue();

    $order->delete();

    expect($attachment->fresh()->temporaryUrl())->toBeNull()
        ->and($disk->exists($attachment->path))->toBeTrue()
        ->and(ChangeOrderAttachment::query()->count())->toBe(1);
});

it('scopes orders to the site supervisor and hides them from a worker', function (): void {
    $this->seed();
    $site = JobSite::factory()->create();
    $order = ChangeOrder::factory()->create(['job_site_id' => $site->id, 'job_scope_id' => JobScope::query()->create(['job_site_id' => $site->id, 'name' => 'Muro'])->id]);
    $supervisor = User::factory()->create()->assignRole('Supervisor');
    $supervisor->jobSites()->attach($site);
    $worker = User::factory()->create()->assignRole('Carpintero');
    $worker->jobSites()->attach($site);

    expect(ChangeOrder::query()->forUser($supervisor)->pluck('id')->all())->toBe([$order->id])
        ->and(ChangeOrder::query()->forUser($worker)->count())->toBe(0)
        ->and(ChangeOrder::query()->approved()->count())->toBe(0);
});

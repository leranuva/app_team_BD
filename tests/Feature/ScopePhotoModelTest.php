<?php

use App\Models\JobSite;
use App\Models\ScopePhoto;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls back and reapplies the photo migration with its indexes', function (): void {
    $migration = require database_path('migrations/2026_11_21_230000_create_scope_photos_table.php');
    $migration->down();
    expect(Schema::hasTable('scope_photos'))->toBeFalse();

    $migration->up();
    expect(Schema::hasIndex('scope_photos', ['job_scope_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasIndex('scope_photos', ['job_site_id']))->toBeTrue()
        ->and(Schema::hasIndex('scope_photos', ['uploaded_by']))->toBeTrue();
    expect(ScopePhoto::factory()->create()->exists)->toBeTrue();
});

it('derives the site and prevents cross-site associations at both layers', function (): void {
    $photo = ScopePhoto::factory()->create(['lat' => 40.7128, 'lng' => -74.006]);
    expect($photo->jobScope->job_site_id)->toBe($photo->job_site_id)
        ->and($photo->jobSite->id)->toBe($photo->job_site_id)
        ->and($photo->uploadedBy->id)->toBe($photo->uploaded_by)
        ->and($photo->fresh()->lat)->toBe('40.7128000')
        ->and($photo->jobScope->photos()->active()->forScope($photo->job_scope_id)->forSite($photo->job_site_id)->count())->toBe(1);

    $otherSite = JobSite::factory()->create();
    $fields = $photo->getAttributes();
    unset($fields['id']);
    $fields['job_site_id'] = $otherSite->id;
    expect(fn () => ScopePhoto::create($fields))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('scope_photos')->insert($fields)))
        ->toThrow(QueryException::class);
});

it('signs only active existing photos for fifteen minutes and retains the archived object', function (): void {
    $this->freezeTime();
    $disk = Storage::fake('s3');
    $photo = ScopePhoto::factory()->create(['caption' => 'Progress']);
    $signedRequest = null;
    $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiresAt) use (&$signedRequest): string {
        $signedRequest = [$path, $expiresAt->getTimestamp()];

        return 'https://example.invalid/signed-photo';
    });

    expect($photo->temporaryUrl())->toBeNull();
    $disk->put($photo->path, 'test');
    expect($photo->temporaryUrl())->toBe('https://example.invalid/signed-photo')
        ->and($signedRequest)->toBe([$photo->path, now()->addMinutes(15)->timestamp])
        ->and($photo->toArray())->not->toHaveKey('path');

    $photo->forceFill(['archived_at' => now()])->save();
    expect($photo->temporaryUrl())->toBeNull()
        ->and(ScopePhoto::active()->count())->toBe(0);
    $disk->assertExists($photo->path);
    expect(fn () => $photo->delete())->toThrow(ValidationException::class);

    $audit = Activity::forSubject($photo)->where('event', 'updated')->sole();
    expect($audit->log_name)->toBe('scope_photos')
        ->and($audit->getExtraProperty('attributes.archived_at'))->not->toBeNull();
    $photo->update(['caption' => 'Otra descripción']);
    expect($photo->fresh()->caption)->toBe('Otra descripción');
});

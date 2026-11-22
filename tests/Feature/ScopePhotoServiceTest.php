<?php

use App\Enums\LogisticsRole;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\ScopePhoto;
use App\Models\User;
use App\Services\ScopePhotoService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->disk = Storage::fake('s3');
    $this->scope = JobScope::create(['job_site_id' => JobSite::factory()->create()->id, 'name' => 'Encofrado']);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->scope->job_site_id);
    $this->service = app(ScopePhotoService::class);
});

it('stores a private UUID object without using the original name in its path', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21T14:00:00Z'));
    $file = UploadedFile::fake()->image('turno-gerencia.png');
    $photo = $this->service->store($this->scope, $this->supervisor, $file, ['caption' => 'Avance']);

    expect($photo->path)->toMatch("#^scopes/{$this->scope->job_site_id}/{$this->scope->id}/[0-9a-f-]{36}\.png$#D")
        ->and($photo->original_name)->toBe('turno-gerencia.png')
        ->and($photo->mime_type)->toBe('image/png')
        ->and($photo->size_bytes)->toBe($file->getSize())
        ->and($photo->lat)->toBeNull()
        ->and($photo->taken_at->toIso8601String())->toBe('2026-11-21T14:00:00+00:00');
    $this->disk->assertExists($photo->path);

    $geo = $this->service->store($this->scope, $this->supervisor, $file, [
        'lat' => 40.7128, 'lng' => -74.006, 'taken_at' => '2026-11-20T14:00:00-05:00',
    ]);
    expect($geo->lat)->toBe('40.7128000')
        ->and($geo->lng)->toBe('-74.0060000')
        ->and($geo->taken_at->toIso8601String())->toBe('2026-11-20T19:00:00+00:00')
        ->and($geo->path)->not->toBe($photo->path);
});

it('uses JPEG EXIF time when a device timestamp is absent', function (): void {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('EXIF extension unavailable.');
    }

    $jpeg = UploadedFile::fake()->image('avance.jpg')->getContent();
    $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVV', 0x0132, 2, 20, 26)
        .pack('V', 0)."2026:07:15 08:15:00\0";
    $exif = "Exif\0\0".$tiff;
    $file = UploadedFile::fake()->createWithContent('avance.jpg', substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));

    $photo = $this->service->store($this->scope, $this->supervisor, $file, []);
    expect($photo->taken_at->toIso8601String())->toBe('2026-07-15T12:15:00+00:00');
});

it('archives idempotently and makes the existing object signable after restoring', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21T14:00:00Z'));
    $this->disk->buildTemporaryUrlsUsing(fn () => 'https://example.invalid/signed');
    $photo = $this->service->store($this->scope, $this->supervisor, UploadedFile::fake()->image('avance.jpg'), []);
    $archived = $this->service->archive($photo, $this->supervisor, 'Duplicada');
    expect($archived->archived_at)->not->toBeNull()
        ->and($archived->temporaryUrl())->toBeNull();
    $this->disk->assertExists($archived->path);

    $this->travel(10)->minutes();
    $again = $this->service->archive($photo, $this->supervisor, 'Segundo motivo');
    expect($again->archived_at->equalTo($archived->archived_at))->toBeTrue()
        ->and($again->updated_at->equalTo($archived->updated_at))->toBeTrue();
    $audit = Activity::forSubject($photo)->where('event', 'updated')->sole();
    expect($audit->causer_id)->toBe($this->supervisor->id)
        ->and($audit->description)->toBe('scope_photo.archived')
        ->and($audit->getExtraProperty('reason'))->toBe('Duplicada');

    $admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $restored = $this->service->restore($photo, $admin);
    expect($restored->archived_at)->toBeNull()->and($restored->temporaryUrl())->toBe('https://example.invalid/signed');
    $latest = Activity::forSubject($photo)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($latest->causer_id)->toBe($admin->id)
        ->and($latest->description)->toBe('scope_photo.restored')
        ->and($latest->properties->has('reason'))->toBeFalse();
    $this->travel(10)->minutes();
    $again = $this->service->restore($photo, $admin);
    expect($again->archived_at)->toBeNull()
        ->and($again->updated_at->equalTo($restored->updated_at))->toBeTrue();
    expect(Activity::forSubject($photo)->where('event', 'updated')->count())->toBe(2);
});

it('keeps a photo archived when its original object is missing', function (): void {
    $photo = $this->service->store($this->scope, $this->supervisor, UploadedFile::fake()->image('avance.jpg'));
    $archived = $this->service->archive($photo, $this->supervisor);
    $this->disk->assertExists($archived->path);
    expect($this->disk->exists($archived->path))->toBeTrue();
    $this->disk->delete($archived->path);
    $this->disk->assertMissing($archived->path);

    Log::spy();
    expect(fn () => $this->service->restore($photo, $this->supervisor))
        ->toThrow(RuntimeException::class, 'El archivo original ya no está disponible');
    Log::shouldHaveReceived('warning')->once()->with('El archivo original ya no está disponible', [
        'photo_id' => $photo->id,
        'path' => $photo->path,
    ]);
    expect($photo->fresh()->archived_at->equalTo($archived->archived_at))->toBeTrue();
    expect(Activity::forSubject($photo)->where('event', 'updated')->count())->toBe(1);
});

it('updates the caption once and ignores an unchanged value', function (): void {
    $photo = $this->service->store($this->scope, $this->supervisor, UploadedFile::fake()->image('avance.jpg'), ['caption' => 'Muro']);
    $updated = $this->service->updateCaption($photo, $this->supervisor, '  Losa  ');
    expect($updated->caption)->toBe('Losa');
    $cleared = $this->service->updateCaption($updated, $this->supervisor, '   ');
    expect($cleared->caption)->toBeNull();
    $again = $this->service->updateCaption($cleared, $this->supervisor, null);
    expect($again->updated_at->equalTo($cleared->updated_at))->toBeTrue()
        ->and(Activity::forSubject($photo)->where('description', 'updated')->count())->toBe(2);

    $outside = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    expect(fn () => $this->service->updateCaption($photo, $outside, 'Ajena'))->toThrow(AuthorizationException::class);
    expect($photo->fresh()->caption)->toBeNull();
});

it('rejects force-filled changes to photo metadata', function (string $attribute, mixed $value): void {
    $photo = ScopePhoto::factory()->create();
    expect(fn () => $photo->forceFill([$attribute => $value])->save())
        ->toThrow(DomainException::class, 'Los metadatos de la foto son inmutables tras la creación');
})->with([
    ['path', 'other/photo.jpg'], ['lat', 40.6], ['lng', -72.2], ['taken_at', '2026-01-01 00:00:00'],
    ['size_bytes', 1], ['mime_type', 'image/png'],
]);

it('rejects unauthorized uploads and always derives the site from the scope', function (): void {
    $file = UploadedFile::fake()->image('avance.jpg');
    $outside = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    expect(fn () => $this->service->store($this->scope, $outside, $file, []))->toThrow(AuthorizationException::class);
    expect($this->disk->allFiles())->toBe([]);
    $photo = $this->service->store($this->scope, $this->supervisor, $file, [
        'job_site_id' => JobSite::factory()->create()->id,
    ]);
    expect($photo->job_site_id)->toBe($this->scope->job_site_id);
    $this->disk->assertExists($photo->path);
});

it('rejects invalid, unsupported and oversized uploads before contacting storage', function (): void {
    $invalid = new UploadedFile(__FILE__, 'partial.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);
    $unsupported = UploadedFile::fake()->image('advance.gif');
    $oversized = UploadedFile::fake()->image('advance.png')->size(10241);

    foreach ([$invalid, $unsupported, $oversized] as $file) {
        expect(fn () => $this->service->store($this->scope, $this->supervisor, $file))
            ->toThrow(ValidationException::class);
    }
    expect($this->disk->allFiles())->toBe([]);
    $this->assertDatabaseCount('scope_photos', 0);
});

it('removes a partially stored object if the S3 upload throws', function (): void {
    $fake = $this->disk;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name, $options) use ($fake) {
        expect($options)->toBe(['visibility' => 'private']);
        $fake->putFileAs($directory, $file, $name, $options);
        throw new RuntimeException('S3 failed');
    });
    $disk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $fake->delete($path));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    expect(fn () => $this->service->store($this->scope, $this->supervisor, UploadedFile::fake()->image('advance.jpg')))
        ->toThrow(RuntimeException::class, 'S3 failed');
    expect($fake->allFiles())->toBe([]);
    $this->assertDatabaseCount('scope_photos', 0);
});

it('removes the uploaded object when the model cannot be persisted', function (): void {
    ScopePhoto::creating(fn () => throw new RuntimeException('Database write failed'));

    expect(fn () => $this->service->store($this->scope, $this->supervisor, UploadedFile::fake()->image('advance.jpg')))
        ->toThrow(RuntimeException::class, 'Database write failed');
    expect($this->disk->allFiles())->toBe([]);
    $this->assertDatabaseCount('scope_photos', 0);
});

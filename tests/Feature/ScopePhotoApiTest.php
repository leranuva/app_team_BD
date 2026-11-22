<?php

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use App\Http\Resources\ScopePhotoResource;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\ScopePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->disk = Storage::fake('s3');
    $this->disk->buildTemporaryUrlsUsing(fn () => 'https://example.invalid/signed');
    $this->site = JobSite::factory()->create(['name' => 'Brooklyn Heights']);
    $this->scope = JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Encofrado', 'status' => 'active']);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->worker = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->worker->jobSites()->attach($this->site);
});

it('serializes active and archived photos with policy flags and without the storage path', function (): void {
    $this->disk->put('scopes/photo.jpg', 'jpeg');
    $active = ScopePhoto::factory()->create([
        'job_scope_id' => $this->scope->id,
        'uploaded_by' => $this->supervisor->id,
        'path' => 'scopes/photo.jpg',
        'caption' => 'Encofrado terminado',
        'lat' => 40.7128,
        'lng' => -74.006,
    ]);
    $archived = ScopePhoto::factory()->create([
        'job_scope_id' => $this->scope->id,
        'uploaded_by' => $this->supervisor->id,
        'path' => 'scopes/photo.jpg',
    ]);
    $archived->forceFill(['archived_at' => now()])->save();
    $this->actingAs($this->supervisor);
    $activePayload = (new ScopePhotoResource($active->load('uploadedBy')))->resolve(request());
    $archivedPayload = (new ScopePhotoResource($archived->fresh()->load('uploadedBy')))->resolve(request());
    expect($activePayload)->toMatchArray([
        'job_scope_id' => $this->scope->id,
        'caption' => 'Encofrado terminado',
        'url' => 'https://example.invalid/signed',
        'thumbnail_url' => 'https://example.invalid/signed',
        'lat' => 40.7128,
        'lng' => -74.006,
        'can_archive' => true,
        'can_restore' => false,
    ])->and($activePayload)->not->toHaveKey('path')
        ->and($archivedPayload['url'])->toBeNull()
        ->and($archivedPayload['thumbnail_url'])->toBeNull()
        ->and($archivedPayload['can_archive'])->toBeFalse()
        ->and($archivedPayload['can_restore'])->toBeTrue();

    $this->actingAs($this->worker);
    $workerPayload = (new ScopePhotoResource($active))->resolve(request());
    expect($workerPayload['can_archive'])->toBeFalse()->and($workerPayload['can_restore'])->toBeFalse();
});

it('lists, uploads, archives and restores photos for the authorized scope', function (): void {
    $outside = JobScope::create(['job_site_id' => JobSite::factory()->create()->id, 'name' => 'Ajeno']);
    $this->actingAs($this->worker)->getJson('/api/job-scopes/'.$this->scope->id.'/photos')->assertForbidden();
    $this->post('/api/job-scopes/'.$this->scope->id.'/photos', [
        'file' => UploadedFile::fake()->image('avance.jpg'),
    ], ['Accept' => 'application/json'])->assertForbidden();
    $this->get('/job-scopes/'.$this->scope->id)->assertForbidden();

    $this->actingAs($this->supervisor);
    $this->getJson('/api/job-scopes/'.$outside->id.'/photos')->assertForbidden();
    $this->get('/job-scopes/'.$this->scope->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('JobScopes/Show')
        ->where('scope.id', $this->scope->id)
        ->where('canCreate', true)
        ->where('canViewAll', false)
        ->where('timeZone', 'America/New_York'));
    $this->actingAs(User::factory()->create()->assignRole(LogisticsRole::Administrator->value))
        ->get('/job-scopes/'.$this->scope->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAll', true));

    $this->actingAs($this->supervisor);
    $created = $this->post('/api/job-scopes/'.$this->scope->id.'/photos', [
        'file' => UploadedFile::fake()->image('muro.jpg'),
        'caption' => 'Muro norte',
        'lat' => 40.7128,
        'lng' => -74.006,
        'taken_at' => '2026-11-21T14:30:00-05:00',
    ], ['Accept' => 'application/json']);
    $created->assertCreated()
        ->assertJsonPath('data.caption', 'Muro norte')
        ->assertJsonPath('data.url', 'https://example.invalid/signed')
        ->assertJsonPath('data.can_archive', true)
        ->assertJsonMissingPath('data.path');
    $photoId = $created->json('data.id');
    $this->disk->assertExists(ScopePhoto::findOrFail($photoId)->path);

    $this->post('/api/job-scopes/'.$this->scope->id.'/photos', [
        'file' => UploadedFile::fake()->image('avance.gif'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post('/api/job-scopes/'.$this->scope->id.'/photos', [
        'file' => UploadedFile::fake()->image('grande.jpg')->size(10241),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    ScopePhoto::factory()->count(20)->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => $this->supervisor->id]);
    $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos')
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos?page=2')->assertOk()->assertJsonCount(1, 'data');

    $this->patchJson('/api/scope-photos/'.$photoId.'/archive', ['reason' => 'Duplicada'])
        ->assertOk()->assertJsonPath('data.can_restore', true)->assertJsonPath('data.url', null);
    $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos?status=active')->assertOk()->assertJsonPath('meta.total', 20);
    $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos?status=archived')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $photoId);

    $this->disk->delete(ScopePhoto::findOrFail($photoId)->path);
    $this->patchJson('/api/scope-photos/'.$photoId.'/restore')->assertUnprocessable()
        ->assertJsonPath('message', 'El archivo original ya no está disponible');
    expect(ScopePhoto::findOrFail($photoId)->archived_at)->not->toBeNull();

    $this->disk->put(ScopePhoto::findOrFail($photoId)->path, 'jpeg');
    $this->patchJson('/api/scope-photos/'.$photoId.'/restore')->assertOk()->assertJsonPath('data.can_archive', true);
    expect($this->worker->can(LogisticsPermission::GlobalAccess->value))->toBeFalse();
});

it('limits photo reads to 60 per minute and writes to 30', function (): void {
    $this->actingAs($this->supervisor);
    $photo = ScopePhoto::factory()->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => $this->supervisor->id]);
    $this->disk->put($photo->path, 'jpeg');

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos')->assertOk();
    }
    $this->getJson('/api/job-scopes/'.$this->scope->id.'/photos')->assertTooManyRequests();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->patchJson('/api/scope-photos/'.$photo->id.'/archive', ['reason' => 'Repetida'])->assertOk();
    }
    $this->patchJson('/api/scope-photos/'.$photo->id.'/archive', ['reason' => 'Repetida'])->assertTooManyRequests();
});

it('offers the supervisor dashboard a direct link to the scope gallery', function (): void {
    ScopePhoto::factory()->count(2)->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => $this->supervisor->id]);

    $this->actingAs($this->supervisor)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('photoScope.id', $this->scope->id)
        ->where('photoScope.name', 'Encofrado')
        ->where('photoScope.active_photos_count', 2));
    $this->actingAs($this->worker)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('photoScope', null));
});

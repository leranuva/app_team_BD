<?php

use App\Enums\LogisticsRole;
use App\Filament\Resources\JobScopes\Pages\ListJobScopes;
use App\Filament\Resources\JobScopes\Pages\ViewJobScope;
use App\Filament\Resources\JobScopes\RelationManagers\ScopePhotosRelationManager;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\ScopePhoto;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->disk = Storage::fake('s3');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Encofrado']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->actingAs($this->admin);
});

it('shows the relation manager and the active photo count on the scope resource', function (): void {
    $active = ScopePhoto::factory()->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => $this->admin->id, 'caption' => 'Muro visible']);
    $archived = ScopePhoto::factory()->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => $this->admin->id]);
    $archived->forceFill(['archived_at' => now()])->save();

    Livewire::test(ListJobScopes::class)
        ->assertCanSeeTableRecords([$this->scope])
        ->assertTableColumnStateSet('photos_count', '1', $this->scope);

    Livewire::test(ViewJobScope::class, ['record' => $this->scope->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('ScopePhotosRelationManager');

    Livewire::test(ScopePhotosRelationManager::class, [
        'ownerRecord' => $this->scope,
        'pageClass' => ViewJobScope::class,
    ])->assertCanSeeTableRecords([$active])
        ->callTableAction('archive', $active, ['reason' => 'Duplicada']);

    expect($active->fresh()->archived_at)->not->toBeNull();
});

it('persists a photo from the upload action through the service', function (): void {
    Livewire::test(ScopePhotosRelationManager::class, [
        'ownerRecord' => $this->scope,
        'pageClass' => ViewJobScope::class,
    ])->callTableAction('uploadPhoto', null, [
        'path' => UploadedFile::fake()->image('muro.jpg'),
        'caption' => 'Muro norte',
    ])->assertHasNoTableActionErrors();

    $photo = ScopePhoto::query()->sole();
    expect($photo->caption)->toBe('Muro norte')
        ->and($photo->job_site_id)->toBe($this->scope->job_site_id)
        ->and($photo->mime_type)->toBe('image/jpeg')
        ->and($photo->path)->toMatch("#^scopes/{$this->scope->job_site_id}/{$this->scope->id}/[0-9a-f-]{36}\\.jpg$#D");
    $this->disk->assertExists($photo->path);
});

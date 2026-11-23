<?php

use App\Enums\LogisticsRole;
use App\Filament\Pages\ImportMaterials;
use App\Filament\Resources\Materials\Pages\CreateMaterial;
use App\Filament\Resources\Materials\Pages\EditMaterial;
use App\Filament\Resources\Materials\Pages\ListMaterials;
use App\Filament\Resources\Materials\Pages\ViewMaterial;
use App\Filament\Widgets\MaterialsOverviewWidget;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
});

it('shows the Sheetrock reference photo on the catalog and product pages', function (): void {
    $this->actingAs($this->admin);
    $material = Material::factory()->create(['code' => 'SR-FIR-001', 'category' => 'Drywall']);
    $photoPath = '/material-photos/sheetrock/firecode-x.jpg';

    $this->get('/admin/materials')->assertOk()->assertSee($photoPath, false);
    $this->get('/admin/materials/'.$material->id)->assertOk()->assertSee($photoPath, false);
    $this->get('/admin/materials/'.$material->id.'/edit')->assertOk()->assertSee($photoPath, false);
});

it('creates, filters and deactivates materials for an administrator', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateMaterial::class)
        ->fillForm([
            'code' => 'MAT-NY-1',
            'name' => 'Tornillo de obra',
            'unit_choice' => 'pcs',
            'category_choice' => 'Fasteners',
            'is_active' => true,
            'notes' => 'Especificación NY',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Material::query()->where('code', 'MAT-NY-1')->firstOrFail();
    $other = Material::factory()->create([
        'name' => 'Pintura',
        'category' => 'Paint & Coatings',
        'is_active' => true,
    ]);

    Livewire::test(EditMaterial::class, ['record' => $created->id])
        ->fillForm(['name' => 'Tornillo inoxidable'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->refresh()->name)->toBe('Tornillo inoxidable')
        ->and($created->code)->toBe('MAT-NY-1')
        ->and($created->notes)->toBe('Especificación NY')
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(1);

    Livewire::test(ListMaterials::class)
        ->searchTable('Tornillo')
        ->assertCanSeeTableRecords([$created])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListMaterials::class)
        ->filterTable('category', 'Paint & Coatings')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$created]);

    Livewire::test(ListMaterials::class)
        ->filterTable('is_active', false)
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListMaterials::class)->callTableBulkAction('deactivate', [$other]);

    expect($other->refresh()->is_active)->toBeFalse();

    Livewire::test(ListMaterials::class)->callTableAction('delete', $created);
    expect(Material::query()->whereKey($created->id)->exists())->toBeFalse();
});

it('lets a supervisor read a material and blocks create, edit and bulk changes', function (): void {
    $material = Material::factory()->create(['name' => 'Viga']);
    $this->actingAs($this->supervisor);

    Livewire::test(ListMaterials::class)
        ->assertCanSeeTableRecords([$material])
        ->assertActionHidden('create')
        ->assertTableBulkActionHidden('deactivate');

    Livewire::test(ViewMaterial::class, ['record' => $material->id])->assertOk();
    Livewire::test(CreateMaterial::class)->assertForbidden();
    Livewire::test(EditMaterial::class, ['record' => $material->id])->assertForbidden();
});

it('keeps a referenced material from being deleted', function (): void {
    $material = Material::factory()->create();
    $list = MaterialList::query()->create([
        'job_site_id' => JobSite::factory()->create()->id,
        'name' => 'Lista',
        'status' => 'pending',
    ]);
    MaterialListItem::query()->create([
        'material_list_id' => $list->id,
        'material_id' => $material->id,
        'budgeted_quantity' => 1,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ListMaterials::class)
        ->callTableAction('delete', $material)
        ->assertNotified('This material is used in a list. Deactivate it instead of deleting it.');

    expect(Material::query()->whereKey($material->id)->exists())->toBeTrue();
});

it('imports a csv from Filament and reports an invalid file', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ImportMaterials::class)
        ->callAction('import', data: [
            'file' => UploadedFile::fake()->createWithContent(
                'cwicr.csv',
                file_get_contents(database_path('data/cwicr_materials_sample.csv')),
            ),
        ])
        ->assertNotified('Imported: 10 · Updated: 0 · Skipped: 0');

    expect(Material::query()->count())->toBe(10);

    Livewire::test(ImportMaterials::class)
        ->callAction('import', data: [
            'file' => UploadedFile::fake()->createWithContent('malo.csv', "code,name,category\nMAT-1,Arena,General\n"),
        ])
        ->assertNotified('The CSV must include code, name, category and unit. Columns found: code, name, category.');

    $this->actingAs($this->supervisor);
    Livewire::test(ImportMaterials::class)->assertForbidden();
});

it('renders the catalog summary widget', function (): void {
    Material::factory()->count(2)->create(['category' => 'Steel & Metal', 'is_active' => true]);
    Material::factory()->create(['category' => 'Electrical', 'is_active' => false]);

    $this->actingAs($this->supervisor);

    Livewire::test(MaterialsOverviewWidget::class)
        ->assertSee('Materials')
        ->assertSee('3')
        ->assertSee('Active')
        ->assertSee('2')
        ->assertSee('Used in lists')
        ->assertSee('Steel & Metal (2)');
});

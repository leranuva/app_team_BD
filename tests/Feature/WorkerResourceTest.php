<?php

namespace Tests\Feature;

use App\Actions\ManageWorker;
use App\Enums\LogisticsRole;
use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Models\JobSite;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkerResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Storage::fake('s3');
    }

    public function test_administrator_creates_worker_and_adds_primary_site_membership(): void
    {
        $this->actingAs($this->worker(LogisticsRole::Administrator));
        $site = $this->site('Queens');

        Livewire::test(CreateWorker::class)
            ->assertFormFieldExists('identity_documents', fn (FileUpload $field): bool => $field->getVisibility() === 'private' && $field->getDiskName() === 's3')
            ->fillForm([
                'name' => 'Field Worker',
                'email' => 'FIELD@example.com',
                'password' => 'a-strong-test-password',
                'role_id' => Role::findByName('Carpintero')->id,
                'primary_job_site_id' => $site->id,
                'phone' => '555-0100',
                'hourly_rate' => 25.50,
                'emergency_contact' => ['name' => 'Contact', 'phone' => '555-0101'],
            ])
            ->set('data.identity_documents', [UploadedFile::fake()->create('id.pdf', 20, 'application/pdf')])
            ->call('create')
            ->assertHasNoFormErrors();

        $worker = User::where('email', 'field@example.com')->sole();
        $this->assertTrue($worker->hasExactRoles(['Carpintero']));
        $this->assertTrue($worker->primaryJobSite->is($site));
        $this->assertTrue($worker->jobSites()->whereKey($site->id)->exists());
        $this->assertSame('25.50', $worker->hourly_rate);
        $this->assertSame('Contact', $worker->emergency_contact['name']);
        $this->assertCount(1, $worker->identity_documents);
        Storage::disk('s3')->assertExists($worker->identity_documents[0]);
    }

    public function test_administrator_can_select_and_change_the_primary_site_from_the_edit_form(): void
    {
        $this->actingAs($this->worker(LogisticsRole::Administrator));
        $worker = $this->worker(LogisticsRole::Carpenter);
        $previous = $this->site('Queens');
        $next = $this->site('Brooklyn');
        $worker->update(['primary_job_site_id' => $previous->id]);
        $worker->jobSites()->attach($previous);

        Livewire::test(EditWorker::class, ['record' => $worker->id])
            ->assertFormFieldExists('primary_job_site_id', fn (Select $field): bool => $field->isPreloaded()
                && ($field->getOptions()[$next->id] ?? null) === $next->name)
            ->fillForm(['primary_job_site_id' => $next->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($worker->fresh()->primaryJobSite->is($next));
        $this->assertTrue($worker->jobSites()->whereKey($previous->id)->exists());
        $this->assertTrue($worker->jobSites()->whereKey($next->id)->exists());
    }

    public function test_supervisor_can_edit_only_contact_fields_and_only_for_their_team(): void
    {
        $supervisor = $this->worker(LogisticsRole::Supervisor);
        $worker = $this->worker(LogisticsRole::Helper);
        $outside = $this->worker(LogisticsRole::Carpenter);
        $this->site('Queens')->users()->attach([$supervisor->id, $worker->id]);
        $worker->update(['hourly_rate' => 42, 'insurance_reference' => 'secret-reference']);

        $this->actingAs($supervisor)->get('/admin/workers')->assertOk()
            ->assertSee($worker->email)->assertDontSee($outside->email);
        $this->get('/admin/workers/'.$outside->id.'/edit')->assertNotFound();
        $this->get('/admin/workers/create')->assertForbidden();
        $this->get('/admin/workers/'.$worker->id.'/edit')->assertOk()->assertDontSee('secret-reference');

        Livewire::test(EditWorker::class, ['record' => $worker->id])
            ->fillForm(['phone' => '555-0102'])
            ->set('data.hourly_rate', 999)
            ->call('save')
            ->assertHasNoFormErrors();

        $worker->refresh();
        $this->assertSame('555-0102', $worker->phone);
        $this->assertSame('42.00', $worker->hourly_rate);
        $this->assertFalse($supervisor->can('delete', $worker));
        $this->assertTrue($supervisor->can('updateContactInfo', $worker));
        $this->assertFalse($supervisor->can('updateContactInfo', $outside));

        $this->actingAs($outside)->get('/admin/workers')->assertForbidden();
    }

    public function test_only_active_non_deleted_workers_are_assignable(): void
    {
        $active = $this->worker(LogisticsRole::Helper);
        $this->worker(LogisticsRole::Helper)->forceFill(['is_active' => false])->save();
        $this->worker(LogisticsRole::Carpenter)->delete();
        $this->worker(LogisticsRole::Administrator);

        $this->assertSame([$active->id], User::withTrashed()->operational()->active()->pluck('id')->all());
    }

    public function test_worker_document_links_expire_in_ten_minutes(): void
    {
        $this->freezeTime();
        $file = 'worker-documents/identity/example.pdf';
        $worker = $this->worker(LogisticsRole::Helper);
        $worker->update(['identity_documents' => [$file]]);
        $disk = Storage::disk('s3');
        $disk->put($file, 'test');
        $signedUrl = 'https://example.invalid/private?signature=test';
        $signedRequest = null;
        $disk->buildTemporaryUrlsUsing(function (string $path, \DateTimeInterface $expiresAt) use (&$signedRequest, $signedUrl): string {
            $signedRequest = [$path, $expiresAt->getTimestamp()];

            return $signedUrl;
        });

        $this->actingAs($this->worker(LogisticsRole::Administrator));
        $form = Livewire::test(EditWorker::class, ['record' => $worker->id])->instance()->form;
        $documents = $form->getFlatFields()['identity_documents']->getUploadedFiles();

        $this->assertSame([$file, now()->addMinutes(10)->getTimestamp()], $signedRequest);
        $this->assertSame($signedUrl, array_values($documents)[0]['url']);
    }

    public function test_only_operational_roles_can_be_assigned(): void
    {
        $admin = $this->worker(LogisticsRole::Administrator);

        $this->expectException(ValidationException::class);

        app(ManageWorker::class)->save($admin, null, [
            'name' => 'Invalid worker', 'email' => 'invalid@example.com', 'password' => 'a-strong-test-password',
            'role_id' => Role::findByName('Super Admin')->id,
        ]);
    }

    public function test_deactivation_blocks_login_and_existing_sessions(): void
    {
        $worker = $this->worker(LogisticsRole::Helper);
        $admin = $this->worker(LogisticsRole::Administrator);

        $this->actingAs($admin);
        Livewire::test(ListWorkers::class)
            ->callAction(TestAction::make('deactivate')->table($worker))
            ->assertHasNoActionErrors();
        $this->assertFalse($worker->refresh()->is_active);
        $this->post('/logout');
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->actingAs($worker->refresh())->get('/dashboard')->assertForbidden();
        $this->assertGuest();

        app(ManageWorker::class)->setActive($admin, $worker, true);
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_administrator_can_soft_delete_a_worker(): void
    {
        $worker = $this->worker(LogisticsRole::Helper);
        $this->actingAs($this->worker(LogisticsRole::Administrator));

        Livewire::test(EditWorker::class, ['record' => $worker->id])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted($worker);
    }

    public function test_privileged_accounts_cannot_be_edited_through_worker_resource(): void
    {
        $admin = $this->worker(LogisticsRole::Administrator);
        $superAdmin = $this->worker(LogisticsRole::SuperAdmin);

        $this->actingAs($admin)->get('/admin/workers/'.$superAdmin->id.'/edit')->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(ManageWorker::class)->setActive($admin, $superAdmin, false);
    }

    private function worker(LogisticsRole $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }

    private function site(string $name): JobSite
    {
        return JobSite::create([
            'name' => $name, 'address' => 'New York, NY', 'client' => 'Customer',
            'latitude' => 40.75, 'longitude' => -73.9,
        ]);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Enums\LogisticsRole;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as PanelResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_offers_password_recovery(): void
    {
        $this->seed();
        Notification::fake();
        $admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
        $panel = Filament::getPanel('admin');

        $this->get('/admin/login')->assertOk()->assertSee($panel->getRequestPasswordResetUrl(), false);
        $this->get($panel->getRequestPasswordResetUrl())->assertOk();

        Filament::setCurrentPanel($panel);
        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($admin, PanelResetPassword::class, function (PanelResetPassword $notification): bool {
            $this->get($notification->url)->assertOk();

            return true;
        });
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}

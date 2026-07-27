<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_settings_service_returns_config_fallback_when_table_empty(): void
    {
        $settings = app(SettingsService::class);

        $this->assertTrue($settings->get('password_login_enabled'));
        $this->assertFalse($settings->get('google_sso_enabled'));
        $this->assertSame(['tcsavant.com'], $settings->get('allowed_email_domains'));
        $this->assertSame(3, $settings->get('sla_review_days'));
        $this->assertSame(10240, $settings->get('attachment_max_kb'));
    }

    public function test_settings_service_returns_database_value_after_set(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('sla_review_days', 7);

        $this->assertSame(7, $settings->get('sla_review_days'));
        $this->assertDatabaseHas('settings', [
            'key' => 'sla_review_days',
            'value' => '7',
        ]);
    }

    public function test_login_route_is_available_when_password_login_disabled_in_settings(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('password_login_enabled', false);
        $settings->set('google_sso_enabled', true);

        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSee(__('Sign in with Google'))
            ->assertSee(__('Password login is disabled. Please sign in with Google.'))
            ->assertDontSee('wire:model="form.password"', false);
    }

    public function test_login_form_rejects_authentication_when_password_login_disabled(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('password_login_enabled', false);
        $settings->set('google_sso_enabled', true);

        $user = User::factory()->create([
            'email' => 'password-disabled@tcsavant.com',
            'email_verified_at' => now(),
        ]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
    }

    public function test_admin_can_access_settings_page(): void
    {
        $admin = User::factory()->create([
            'email' => 'settings-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee(__('System settings'));
    }

    public function test_regular_user_cannot_access_settings_page(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/admin/settings')
            ->assertForbidden();
    }

    public function test_saving_settings_writes_audit_log(): void
    {
        $admin = User::factory()->create([
            'email' => 'settings-audit@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.admin.settings')
            ->set('googleSsoEnabled', true)
            ->set('passwordLoginEnabled', true)
            ->set('allowedEmailDomains', 'tcsavant.com, example.com')
            ->set('slaReviewDays', 5)
            ->set('attachmentMaxKb', 2048)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.updated',
            'actor_id' => $admin->id,
        ]);

        $log = AuditLog::query()->where('action', 'settings.updated')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('sla_review_days', $log->old_values);
        $this->assertSame(5, $log->new_values['sla_review_days']);
        $this->assertSame(['tcsavant.com', 'example.com'], $log->new_values['allowed_email_domains']);
    }

    public function test_cannot_disable_both_sign_in_methods(): void
    {
        $admin = User::factory()->create([
            'email' => 'settings-guard@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.admin.settings')
            ->set('googleSsoEnabled', false)
            ->set('passwordLoginEnabled', false)
            ->set('allowedEmailDomains', 'tcsavant.com')
            ->call('save')
            ->assertHasErrors('passwordLoginEnabled');
    }
}

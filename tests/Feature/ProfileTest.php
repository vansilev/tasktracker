<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.notification-preferences')
            ->assertDontSee(__('Delete Account'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
    }

    public function test_email_is_not_changed_when_profile_is_updated(): void
    {
        $user = User::factory()->create([
            'email' => 'original@tcsavant.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'Updated Name')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('original@tcsavant.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_delete_account_form_is_not_available(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user);

        $this->expectException(ComponentNotFoundException::class);

        Volt::test('profile.delete-user-form');
    }
}

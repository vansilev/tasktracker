<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\KommoReadKey;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KommoReadKeyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithKommoRead(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Sales head'], ['is_active' => true]);
        $role->syncPermissions([Permission::ViewTask->value, Permission::KommoRead->value]);

        $user = User::factory()->create();
        $user->syncRoles([$role->id]);

        return $user;
    }

    public function test_profile_hides_the_card_without_the_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertDontSeeVolt('profile.kommo-read-keys')
            ->assertDontSee(__('kommo_read.title'));
    }

    public function test_profile_shows_the_card_with_the_permission_and_to_admins(): void
    {
        $this->actingAs($this->userWithKommoRead())
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.kommo-read-keys')
            ->assertSee(__('kommo_read.title'))
            ->assertSee(__('kommo_read.empty'));

        $admin = User::factory()->create(['system_type' => SystemType::Admin]);

        $this->actingAs($admin)
            ->get('/profile')
            ->assertSeeVolt('profile.kommo-read-keys');
    }

    public function test_user_issues_a_key_that_is_shown_once_and_stored_as_a_hash(): void
    {
        $user = $this->userWithKommoRead();
        $this->actingAs($user);

        $component = Volt::test('profile.kommo-read-keys')
            ->set('name', '  laptop  ')
            ->call('createKey')
            ->assertHasNoErrors()
            ->assertSet('name', '');

        $plain = $component->get('plainKey');
        $html = $component->effects['html'] ?? '';

        $this->assertStringStartsWith('kr_', $plain);
        $this->assertStringContainsString($plain, $html);
        $this->assertStringContainsString('cursor://anysphere.cursor-deeplink/mcp/install?name=kommo', $html);
        $this->assertStringContainsString('https://kommo-read.tcsavant.com/mcp', $html);
        $this->assertStringNotContainsString('@js(', $html);

        $key = $user->kommoReadKeys()->sole();
        $this->assertSame('laptop', $key->name);
        $this->assertSame(hash('sha256', $plain), $key->token_hash);
        $this->assertDatabaseMissing('kommo_read_keys', ['token_hash' => $plain]);

        $config = json_decode($component->instance()->fullCursorConfig(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Bearer '.$plain, $config['mcpServers']['kommo']['headers']['Authorization']);

        parse_str((string) parse_url($component->instance()->installLink(), PHP_URL_QUERY), $query);
        $this->assertSame(
            ['url' => 'https://kommo-read.tcsavant.com/mcp', 'headers' => ['Authorization' => 'Bearer '.$plain]],
            json_decode(base64_decode($query['config']), true),
        );

        $component->call('hideKey')->assertSet('plainKey', null);
        $this->assertStringNotContainsString($plain, $component->effects['html'] ?? '');
    }

    public function test_user_without_the_permission_cannot_issue_a_key(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('profile.kommo-read-keys')
            ->set('name', 'laptop')
            ->call('createKey')
            ->assertForbidden();

        $this->assertDatabaseCount('kommo_read_keys', 0);
    }

    public function test_key_limit_is_enforced(): void
    {
        config(['tasktracker.kommo_read_key_limit' => 1]);
        $user = $this->userWithKommoRead();
        KommoReadKey::issue($user, 'first');
        $this->actingAs($user);

        Volt::test('profile.kommo-read-keys')
            ->set('name', 'second')
            ->call('createKey')
            ->assertHasErrors('name');

        $this->assertSame(1, $user->kommoReadKeys()->count());
    }

    public function test_user_revokes_only_their_own_key(): void
    {
        $user = $this->userWithKommoRead();
        $other = $this->userWithKommoRead();
        [$own] = KommoReadKey::issue($user, 'own');
        [$foreign] = KommoReadKey::issue($other, 'foreign');
        $this->actingAs($user);

        Volt::test('profile.kommo-read-keys')
            ->call('revoke', $foreign->id)
            ->call('revoke', $own->id);

        $this->assertModelMissing($own);
        $this->assertModelExists($foreign);
    }

    public function test_whoami_accepts_a_valid_key_and_records_use(): void
    {
        $user = $this->userWithKommoRead();
        [$key, $plain] = KommoReadKey::issue($user, 'laptop');

        $this->withToken($plain)
            ->getJson('/api/v1/kommo-read/whoami')
            ->assertOk()
            ->assertJsonPath('data.user', $user->name)
            ->assertJsonPath('data.key', 'laptop');

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_whoami_rejects_unknown_revoked_and_missing_keys(): void
    {
        $user = $this->userWithKommoRead();
        [$key, $plain] = KommoReadKey::issue($user, 'laptop');
        $key->delete();

        $this->getJson('/api/v1/kommo-read/whoami')->assertUnauthorized();
        $this->withToken('kr_'.str_repeat('a', 48))->getJson('/api/v1/kommo-read/whoami')->assertUnauthorized();
        $this->withToken($plain)->getJson('/api/v1/kommo-read/whoami')->assertUnauthorized();
    }

    public function test_whoami_rejects_a_key_after_the_permission_or_account_is_gone(): void
    {
        $user = $this->userWithKommoRead();
        [, $plain] = KommoReadKey::issue($user, 'laptop');

        $user->roles()->first()->syncPermissions([Permission::ViewTask->value]);
        $this->withToken($plain)->getJson('/api/v1/kommo-read/whoami')->assertForbidden();

        $admin = User::factory()->create(['system_type' => SystemType::Admin]);
        [, $adminPlain] = KommoReadKey::issue($admin, 'admin laptop');
        $admin->update(['is_active' => false]);
        $this->withToken($adminPlain)->getJson('/api/v1/kommo-read/whoami')->assertForbidden();
    }

    public function test_kommo_key_does_not_open_the_tracker_api_and_tracker_token_does_not_pass_whoami(): void
    {
        $user = $this->userWithKommoRead();
        [, $plain] = KommoReadKey::issue($user, 'laptop');

        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $trackerToken = $user->createToken('cursor')->plainTextToken;
        $this->withToken($trackerToken)->getJson('/api/v1/kommo-read/whoami')->assertUnauthorized();
    }
}

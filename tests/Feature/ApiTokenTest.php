<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_token_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.api-tokens')
            ->assertSee(__('api_token.guide_title'))
            ->assertSee(__('api_token.token_placeholder'))
            ->assertSee('https://task.avant.od.ua/mcp')
            ->assertSee('"task-avant"')
            ->assertSee(__('api_token.title'))
            ->assertSee(__('api_token.empty'));
    }

    public function test_guest_cannot_open_profile(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    private function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_user_can_create_a_token_and_use_it_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.api-tokens')
            ->set('name', '  cursor-laptop  ')
            ->call('createToken')
            ->assertHasNoErrors()
            ->assertSet('name', '');

        $plain = $component->get('plainToken');
        $tokenId = $component->get('issuedTokenId');
        $html = $component->effects['html'] ?? '';

        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);
        $this->assertStringContainsString($plain, $html);
        $this->assertStringContainsString('>cursor-laptop<', $html);
        $this->assertStringContainsString(__('api_token.never_used'), $html);

        $stored = DB::table('personal_access_tokens')->where('id', $tokenId)->first();
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->tokenable_id);
        $this->assertSame('cursor-laptop', $stored->name);
        $this->assertNotSame($plain, $stored->token);
        $this->assertSame(64, strlen($stored->token));

        $this->forgetAuthenticatedUser();

        $this->withToken($plain)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->actingAs($user);

        $revoked = Volt::test('profile.api-tokens')
            ->call('revoke', $tokenId);

        $this->assertStringNotContainsString('>cursor-laptop<', $revoked->effects['html'] ?? '');

        $this->forgetAuthenticatedUser();

        $this->withToken($plain)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_token_secret_can_be_hidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.api-tokens')
            ->set('name', 'cursor')
            ->call('createToken');

        $plain = $component->get('plainToken');

        $hidden = $component->call('hideToken');

        $this->assertNull($hidden->get('plainToken'));
        $this->assertStringNotContainsString($plain, $hidden->effects['html'] ?? '');
        $this->assertStringContainsString('>cursor<', $hidden->effects['html'] ?? '');
    }

    public function test_user_cannot_revoke_someone_elses_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->createToken('foreign-token');

        $this->actingAs($owner);

        $component = Volt::test('profile.api-tokens')
            ->call('revoke', $foreign->accessToken->getKey());

        $this->assertStringNotContainsString('foreign-token', $component->effects['html'] ?? '');
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $foreign->accessToken->getKey(),
            'name' => 'foreign-token',
        ]);
    }

    public function test_user_cannot_exceed_token_limit(): void
    {
        $user = User::factory()->create();
        $limit = (int) config('tasktracker.api_token_limit');

        for ($i = 0; $i < $limit; $i++) {
            $user->createToken('token-'.$i);
        }

        $this->actingAs($user);

        Volt::test('profile.api-tokens')
            ->set('name', 'one-more')
            ->call('createToken')
            ->assertHasErrors(['name'])
            ->assertSet('plainToken', null);

        $this->assertSame($limit, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'one-more',
        ]);
    }

    public function test_blank_token_name_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('profile.api-tokens')
            ->set('name', '   ')
            ->call('createToken')
            ->assertHasErrors(['name']);

        $this->assertSame(0, $user->tokens()->count());
    }
}

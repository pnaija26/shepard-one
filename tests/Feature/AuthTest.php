<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the login page is accessible.
     *
     * @return void
     */
    public function test_login_page_is_accessible()
    {
        $response = $this->get('/login');
        
        $response->assertStatus(200);
        $response->assertSee('id="app"', false);
    }

    public function test_dashboard_spa_shell_is_available_for_token_auth_refresh()
    {
        $response = $this->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="app"', false);
    }

    public function test_dashboard_bootstraps_the_authenticated_user_for_the_spa()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('window.__AUTH_USER__ =', false);
        $response->assertSee($user->email);
    }

    public function test_api_login_returns_a_bearer_token_for_valid_credentials()
    {
        $user = User::factory()->create([
            'password' => 'password',
            'roles' => [],
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_api_user_and_logout_require_and_revoke_a_bearer_token()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Successfully logged out');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_auth_routes_reject_unauthenticated_requests()
    {
        $this->getJson('/api/auth/user')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    /**
     * Test that logout route redirects properly.
     *
     * @return void
     */
    public function test_logout_route_redirects()
    {
        $response = $this->post('/logout');
        
        $response->assertStatus(302);
        $response->assertRedirect('/');
    }

    /**
     * Test that auth redirect route redirects properly.
     *
     * @return void
     */
    public function test_auth_redirect_route_redirects()
    {
        $response = $this->get('/auth/redirect');
        
        $response->assertStatus(302);
        // Should redirect to the identity provider
        $response->assertRedirect();
    }
}
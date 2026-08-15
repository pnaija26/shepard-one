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
    }

    public function test_dashboard_bootstraps_the_authenticated_user_for_the_spa()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('window.__AUTH_USER__ =', false);
        $response->assertSee($user->email);
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
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonMissing(['password']);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_incorrect_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_guests_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    /**
     * Regression: sin "Accept: application/json" (p.ej. Postman sin configurar
     * headers, o un navegador), el middleware de auth intentaba redirigir a
     * route('login') -que no existe en esta API- y tronaba con un 500 en vez
     * de devolver un 401 limpio.
     */
    public function test_guests_get_a_clean_401_even_without_an_accept_json_header(): void
    {
        $this->get('/api/dashboard')->assertStatus(401);
    }
}

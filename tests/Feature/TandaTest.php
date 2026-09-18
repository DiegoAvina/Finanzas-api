<?php

namespace Tests\Feature;

use App\Models\Tanda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TandaTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_tanda_sets_rounds_total_and_next_payment_date(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tandas', [
            'name' => 'Tanda de la oficina',
            'contribution_amount' => 200,
            'num_members' => 5,
            'frequency' => 'weekly',
            'start_date' => '2026-01-05',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('rounds_total', 5);

        $this->assertStringStartsWith(
            '2026-01-05',
            $response->json('next_payment_date'),
        );
    }

    public function test_registering_a_payment_advances_the_round_and_next_payment_date(): void
    {
        $user = User::factory()->create();

        $tanda = Tanda::create([
            'user_id' => $user->id,
            'name' => 'Tanda de la oficina',
            'contribution_amount' => 200,
            'num_members' => 2,
            'rounds_total' => 2,
            'pot_amount' => 400,
            'frequency' => 'weekly',
            'start_date' => '2026-01-05',
            'current_round' => 1,
            'next_payment_date' => '2026-01-05',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/tandas/{$tanda->id}/payments", ['amount' => 200])
            ->assertStatus(200);

        $tanda->refresh();
        $this->assertEquals(2, $tanda->current_round);
        $this->assertEquals('2026-01-12', $tanda->next_payment_date->toDateString());
        $this->assertEquals('active', $tanda->status);

        $this->postJson("/api/tandas/{$tanda->id}/payments", ['amount' => 200])
            ->assertStatus(200);

        $tanda->refresh();
        $this->assertEquals('completed', $tanda->status);
        $this->assertNull($tanda->next_payment_date);
    }

    public function test_a_stranger_cannot_register_a_payment(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $tanda = Tanda::create([
            'user_id' => $owner->id,
            'name' => 'Tanda privada',
            'contribution_amount' => 200,
            'num_members' => 2,
            'rounds_total' => 2,
            'pot_amount' => 400,
            'frequency' => 'weekly',
            'start_date' => '2026-01-05',
            'current_round' => 1,
            'next_payment_date' => '2026-01-05',
            'status' => 'active',
        ]);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/tandas/{$tanda->id}/payments", ['amount' => 200])
            ->assertStatus(403);
    }
}

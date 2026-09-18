<?php

namespace Tests\Feature;

use App\Models\SavingGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavingGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_contribute_to_their_own_goal(): void
    {
        $owner = User::factory()->create();
        $goal = SavingGoal::create([
            'user_id' => $owner->id,
            'name' => 'Vacaciones',
            'target_amount' => 1000,
            'current_amount' => 0,
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/saving-goals/{$goal->id}/contribute", [
            'amount' => 100,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(100, $goal->fresh()->current_amount);
    }

    /**
     * Regression: contribute() no validaba pertenencia, permitiendo a cualquier
     * usuario autenticado modificar el ahorro de una meta ajena (IDOR).
     */
    public function test_a_stranger_cannot_contribute_to_someone_elses_goal(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $goal = SavingGoal::create([
            'user_id' => $owner->id,
            'name' => 'Vacaciones',
            'target_amount' => 1000,
            'current_amount' => 0,
            'status' => 'active',
        ]);

        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/saving-goals/{$goal->id}/contribute", [
            'amount' => 100,
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, $goal->fresh()->current_amount);
    }

    public function test_a_participant_can_contribute_to_a_group_goal(): void
    {
        $owner = User::factory()->create();
        $participant = User::factory()->create();

        $goal = SavingGoal::create([
            'user_id' => $owner->id,
            'name' => 'Viaje en grupo',
            'target_amount' => 1000,
            'current_amount' => 0,
            'is_group' => true,
            'status' => 'active',
        ]);

        $goal->participants()->attach($participant->id, ['role' => 'member']);

        Sanctum::actingAs($participant);

        $this->postJson("/api/saving-goals/{$goal->id}/contribute", ['amount' => 50])
            ->assertStatus(200);
    }
}

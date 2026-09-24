<?php

namespace Tests\Feature;

use App\Models\IncomeDistributionRule;
use App\Models\IncomeSource;
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

    private function makeGoal(User $owner, array $attributes = []): SavingGoal
    {
        return SavingGoal::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Vacaciones',
            'target_amount' => 1000,
            'current_amount' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_owner_can_edit_their_goal(): void
    {
        $owner = User::factory()->create();
        $goal = $this->makeGoal($owner);

        Sanctum::actingAs($owner);

        $this->putJson("/api/saving-goals/{$goal->id}", [
            'name' => 'Viaje a la playa',
            'target_amount' => 2500,
            'category' => 'Viajes',
            'deadline' => '2027-01-15',
        ])->assertStatus(200)->assertJsonPath('goal.name', 'Viaje a la playa');

        $goal->refresh();
        $this->assertEquals('Viaje a la playa', $goal->name);
        $this->assertEquals(2500, $goal->target_amount);
        $this->assertEquals('Viajes', $goal->category);
    }

    public function test_lowering_the_target_below_the_saved_amount_completes_the_goal(): void
    {
        $owner = User::factory()->create();
        $goal = $this->makeGoal($owner, ['current_amount' => 800]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/saving-goals/{$goal->id}", ['target_amount' => 500])->assertStatus(200);
        $this->assertEquals('completed', $goal->fresh()->status);

        $this->putJson("/api/saving-goals/{$goal->id}", ['target_amount' => 5000])->assertStatus(200);
        $this->assertEquals('active', $goal->fresh()->status);
    }

    public function test_a_participant_cannot_edit_or_delete_a_group_goal(): void
    {
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $goal = $this->makeGoal($owner, ['is_group' => true]);
        $goal->participants()->attach($participant->id, ['role' => 'member']);

        Sanctum::actingAs($participant);

        $this->putJson("/api/saving-goals/{$goal->id}", ['name' => 'Hackeada'])->assertStatus(403);
        $this->deleteJson("/api/saving-goals/{$goal->id}")->assertStatus(403);

        $this->assertEquals('Vacaciones', $goal->fresh()->name);
    }

    public function test_owner_can_delete_their_goal_and_its_distribution_rules(): void
    {
        $owner = User::factory()->create();
        $goal = $this->makeGoal($owner, ['current_amount' => 100]);
        $goal->movements()->create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'amount' => 100,
            'type' => 'deposit',
        ]);

        $source = IncomeSource::create([
            'user_id' => $owner->id,
            'name' => 'Sueldo',
            'type' => 'salary',
        ]);
        $rule = IncomeDistributionRule::create([
            'income_source_id' => $source->id,
            'user_id' => $owner->id,
            'target_type' => 'saving_goal',
            'target_id' => $goal->id,
            'mode' => 'percent',
            'value' => 10,
            'order' => 1,
            'active' => true,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/saving-goals/{$goal->id}")->assertStatus(200);

        $this->assertDatabaseMissing('saving_goals', ['id' => $goal->id]);
        $this->assertDatabaseMissing('saving_goal_movements', ['saving_goal_id' => $goal->id]);
        $this->assertDatabaseMissing('income_distribution_rules', ['id' => $rule->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\IncomeOccurrence;
use App\Models\IncomeSource;
use App\Models\SavingGoal;
use App\Models\User;
use App\Models\WeeklyIncome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_recurring_source_with_default_amount_creates_its_first_occurrence(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/income-sources', [
            'name' => 'Nómina',
            'type' => 'salary',
            'default_amount' => 8200,
            'frequency' => 'biweekly',
            'is_recurring' => true,
            'start_date' => '2026-09-30',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('income_sources', ['user_id' => $user->id, 'name' => 'Nómina']);

        $occurrence = IncomeOccurrence::where('user_id', $user->id)->first();
        $this->assertNotNull($occurrence);
        $this->assertEquals(8200, (float) $occurrence->expected_amount);
        $this->assertEquals('2026-09-30', $occurrence->expected_date->toDateString());
        $this->assertEquals('expected', $occurrence->status);
    }

    public function test_a_variable_source_without_default_amount_has_no_automatic_occurrence(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/income-sources', [
            'name' => 'Freelance',
            'type' => 'freelance',
            'is_recurring' => false,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('income_occurrences', 0);
    }

    public function test_can_add_a_manual_occurrence_to_an_existing_source(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Freelance', 'type' => 'freelance']);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/income-sources/{$source->id}/occurrences", [
            'expected_amount' => 3000,
            'expected_date' => '2026-10-15',
        ]);

        $response->assertStatus(201)->assertJsonPath('status', 'expected');
    }

    /**
     * Un ingreso "expected" no debe modificar el saldo (WeeklyIncome) al crearse.
     */
    public function test_an_expected_occurrence_does_not_modify_the_balance(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        $this->assertDatabaseCount('weekly_incomes', 0);
    }

    public function test_receiving_an_occurrence_increases_the_weekly_balance_and_links_it(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", [
            'amount' => 8200,
            'date' => '2026-09-30',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'received');

        $occurrence->refresh();
        $this->assertEquals('received', $occurrence->status);
        $this->assertNotNull($occurrence->weekly_income_id);

        $weeklyIncome = WeeklyIncome::find($occurrence->weekly_income_id);
        $this->assertEquals(8200, (float) $weeklyIncome->amount);
    }

    /**
     * Regression: recibir dos veces la misma ocurrencia no debe duplicar el
     * dinero en el saldo semanal.
     */
    public function test_receiving_the_same_occurrence_twice_does_not_duplicate_the_balance(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 8200, 'date' => '2026-09-30'])
            ->assertStatus(200);
        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 8200, 'date' => '2026-09-30'])
            ->assertStatus(200);

        $occurrence->refresh();
        $weeklyIncome = WeeklyIncome::find($occurrence->weekly_income_id);
        $this->assertEquals(8200, (float) $weeklyIncome->amount);
    }

    public function test_a_partial_receipt_only_applies_the_partial_amount_and_leaves_the_occurrence_partial(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/income-occurrences/{$occurrence->id}/partial", [
            'amount' => 7500,
            'date' => '2026-09-30',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'partial');

        $occurrence->refresh();
        $weeklyIncome = WeeklyIncome::find($occurrence->weekly_income_id);
        $this->assertEquals(7500, (float) $weeklyIncome->amount);
        $this->assertEquals(700, $occurrence->remaining_amount);
    }

    public function test_marking_an_occurrence_as_missed_does_not_touch_the_balance(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Freelance', 'type' => 'freelance']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 3000,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/miss")
            ->assertStatus(200)->assertJsonPath('status', 'missed');

        $this->assertDatabaseCount('weekly_incomes', 0);
    }

    public function test_cancelling_an_occurrence_does_not_touch_the_balance(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Freelance', 'type' => 'freelance']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 3000,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/cancel")
            ->assertStatus(200)->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseCount('weekly_incomes', 0);

        // Y ya no se puede recibir.
        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 3000])
            ->assertStatus(422);
    }

    public function test_receiving_a_recurring_occurrence_generates_the_next_expected_occurrence(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create([
            'user_id' => $user->id,
            'name' => 'Nómina',
            'type' => 'salary',
            'default_amount' => 8200,
            'frequency' => 'biweekly',
            'is_recurring' => true,
        ]);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 8200, 'date' => '2026-09-30'])
            ->assertStatus(200);

        $next = IncomeOccurrence::where('income_source_id', $source->id)
            ->where('status', 'expected')
            ->first();

        $this->assertNotNull($next);
        $this->assertEquals('2026-10-14', $next->expected_date->toDateString());
    }

    public function test_a_user_cannot_see_or_receive_another_users_income_occurrence(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $source = IncomeSource::create(['user_id' => $owner->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $owner->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/income-occurrences/{$occurrence->id}")->assertStatus(403);
        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 8200])->assertStatus(403);
    }

    public function test_cannot_distribute_an_income_into_another_users_saving_goal(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $strangerGoal = SavingGoal::create([
            'user_id' => $stranger->id,
            'name' => 'Meta ajena',
            'target_amount' => 1000,
            'current_amount' => 0,
            'status' => 'active',
        ]);

        $source = IncomeSource::create(['user_id' => $owner->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $owner->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 8200, 'date' => '2026-09-30'])
            ->assertStatus(200);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/distribute", [
            'allocations' => [
                ['target_type' => 'saving_goal', 'target_id' => $strangerGoal->id, 'amount' => 500],
            ],
        ])->assertStatus(404);

        $this->assertEquals(0, (float) $strangerGoal->fresh()->current_amount);
    }

    public function test_cannot_receive_more_than_the_expected_amount(): void
    {
        $user = User::factory()->create();
        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 8200,
            'expected_date' => '2026-09-30',
            'status' => 'expected',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/income-occurrences/{$occurrence->id}/receive", ['amount' => 9000])
            ->assertStatus(422);
    }
}

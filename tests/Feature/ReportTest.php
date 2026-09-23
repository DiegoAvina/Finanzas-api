<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\IncomeOccurrence;
use App\Models\IncomeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_summary_totals_this_months_data_correctly(): void
    {
        $user = User::factory()->create();
        $today = now();

        Expense::create([
            'user_id' => $user->id,
            'date' => $today->toDateString(),
            'amount' => 250,
            'type' => 'food',
        ]);

        $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Nómina', 'type' => 'salary']);
        $occurrence = IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $user->id,
            'expected_amount' => 1000,
            'received_amount' => 1000,
            'applied_amount' => 1000,
            'expected_date' => $today->toDateString(),
            'received_date' => $today->toDateString(),
            'status' => 'received',
        ]);

        Bill::create([
            'user_id' => $user->id,
            'name' => 'Luz',
            'amount' => 400,
            'due_date' => $today->toDateString(),
            'status' => 'paid',
            'paid_at' => $today,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/monthly-summary?months=3');

        $response->assertStatus(200);
        $months = $response->json('months');

        $this->assertCount(3, $months);

        $current = end($months);
        $this->assertSame($today->format('Y-m'), $current['month']);
        $this->assertEquals(250, $current['expenses_total']);
        $this->assertEquals(1000, $current['income_received']);
        $this->assertEquals(400, $current['bills_paid']);

        // Un mes anterior sin datos debe venir en 0, no ausente.
        $this->assertEquals(0, $months[0]['expenses_total']);

        $this->assertNotNull($occurrence->id);
    }

    public function test_monthly_summary_is_isolated_per_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Expense::create([
            'user_id' => $otherUser->id,
            'date' => now()->toDateString(),
            'amount' => 999,
            'type' => 'other',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports/monthly-summary?months=1');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('months.0.expenses_total'));
    }
}

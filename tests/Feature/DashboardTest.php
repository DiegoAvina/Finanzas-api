<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: /api/dashboard consultaba tandas.next_payment_date, una columna
     * que fue eliminada por una migración y tumbaba este endpoint con un 500.
     */
    public function test_dashboard_loads_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(200)->assertJsonStructure([
            'savings' => ['total', 'monthly_change'],
            'bills' => ['pending_count', 'paid_this_month', 'next'],
            'goals',
            'tandas' => ['active_count', 'next_payment'],
            'calendar' => ['upcoming_events', 'daily_expenses'],
            'income' => ['weekly_income', 'spent_this_week', 'available_this_week'],
        ]);
    }

    public function test_calendar_endpoints_load_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/calendar')->assertStatus(200);
        $this->getJson('/api/calendar/events')->assertStatus(200);
    }
}

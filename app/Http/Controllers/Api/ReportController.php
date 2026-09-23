<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\IncomeOccurrence;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * GET /api/reports/monthly-summary?months=6
     *
     * Totales por mes (los últimos N meses, incluyendo el actual) para
     * graficar tendencias en el panel web. Reutiliza exactamente las mismas
     * queries que ya usa DashboardController para "este mes", solo que
     * agregadas mes por mes en vez de una sola vez.
     */
    public function monthlySummary(Request $request)
    {
        $user = $request->user();
        $months = max(1, min(24, (int) $request->get('months', 6)));

        $today = now();
        $summary = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = $today->copy()->subMonths($i);
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd = $monthDate->copy()->endOfMonth();

            $expensesTotal = (float) Expense::where('user_id', $user->id)
                ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount');

            $incomeReceived = (float) IncomeOccurrence::where('user_id', $user->id)
                ->whereIn('status', ['received', 'partial'])
                ->whereBetween('received_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('received_amount');

            $billsPaid = (float) Bill::where('user_id', $user->id)
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])
                ->sum('amount');

            $summary[] = [
                // "label" se arma en el cliente (ahí ya vive el formateo de
                // fechas en español) para no depender del locale del server.
                'month' => $monthDate->format('Y-m'),
                'expenses_total' => $expensesTotal,
                'income_received' => $incomeReceived,
                'bills_paid' => $billsPaid,
            ];
        }

        return response()->json(['months' => $summary]);
    }
}

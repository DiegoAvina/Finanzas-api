<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\WeeklyIncome;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * GET /api/expenses
     * Opcional:
     *   ?scope=week|month (default: week)
     *   ?date=YYYY-MM-DD  (default: hoy)
     *   ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD (ignora scope/date si se
     *     mandan ambos — para rangos largos, ej. el panel web pidiendo los
     *     últimos 6 meses para una gráfica de tendencia)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->get('start_date'))->startOfDay();
            $end = Carbon::parse($request->get('end_date'))->endOfDay();
            $scope = 'range';
        } else {
            $scope = $request->get('scope', 'week'); // week | month
            $dateParam = $request->get('date');
            $baseDate = $dateParam ? Carbon::parse($dateParam) : now();

            if ($scope === 'month') {
                $start = $baseDate->copy()->startOfMonth();
                $end = $baseDate->copy()->endOfMonth();
            } else {
                // semana por defecto (lunes a domingo)
                $start = $baseDate->copy()->startOfWeek(Carbon::MONDAY);
                $end = $baseDate->copy()->endOfWeek(Carbon::SUNDAY);
            }
        }

        $expenses = Expense::where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Expense $e) => $this->transformExpense($e));

        return response()->json([
            'scope' => $scope,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'expenses' => $expenses,
        ]);
    }

    /**
     * POST /api/expenses
     * Body:
     *  - date (opcional, default hoy)
     *  - amount
     *  - type: bill|tanda|saving|purchase|other...
     *  - source_id (id del recibo/tanda/meta, opcional)
     *  - description (opcional)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'string', 'max:50'],
            'source_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $date = isset($data['date'])
            ? Carbon::parse($data['date'])
            : now();

        // Determinar semana (lunes a domingo) según la fecha del gasto
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        // Buscar/crear registro de sueldo semanal para esa semana
        $weeklyIncome = WeeklyIncome::firstOrCreate(
            [
                'user_id' => $user->id,
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            [
                'amount' => 0, // si el usuario no ha definido sueldo aún
                'spent' => 0,
                'saved' => 0,
                'leftover' => 0,
            ]
        );

        // Crear gasto
        $expense = Expense::create([
            'user_id' => $user->id,
            'weekly_income_id' => $weeklyIncome->id,
            'date' => $date->toDateString(),
            'amount' => $data['amount'],
            'type' => $data['type'],
            'source_id' => $data['source_id'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        // Recalcular lo gastado y lo disponible
        $this->recalculateWeeklyIncome($weeklyIncome);

        return response()->json([
            'message' => 'Gasto registrado',
            'expense' => $this->transformExpense($expense),
            'weekly_income' => [
                'amount' => (float) $weeklyIncome->amount,
                'spent' => (float) $weeklyIncome->spent,
                'leftover' => (float) $weeklyIncome->leftover,
            ],
        ], 201);
    }

    /**
     * DELETE /api/expenses/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $expense = Expense::where('user_id', $user->id)->findOrFail($id);

        $weeklyIncome = $expense->weeklyIncome;

        $expense->delete();

        if ($weeklyIncome) {
            $this->recalculateWeeklyIncome($weeklyIncome);
        }

        return response()->json([
            'message' => 'Gasto eliminado',
        ]);
    }

    /**
     * Recalcula spent y leftover de una semana.
     */
    protected function recalculateWeeklyIncome(WeeklyIncome $weeklyIncome): void
    {
        $totalSpent = $weeklyIncome->expenses()->sum('amount');

        $weeklyIncome->spent = $totalSpent;
        $weeklyIncome->leftover = max(
            0,
            $weeklyIncome->amount - $totalSpent
        );

        // 👉 aquí en el futuro podrías mover leftover a una meta de ahorro
        // cuando "cierres" la semana.
        $weeklyIncome->save();
    }

    protected function transformExpense(Expense $e): array
    {
        return [
            'id' => $e->id,
            'date' => $e->date?->toDateString(),
            'amount' => (float) $e->amount,
            'type' => $e->type,
            'source_id' => $e->source_id,
            'description' => $e->description,
            'created_at' => $e->created_at?->toAtomString(),
        ];
    }
}

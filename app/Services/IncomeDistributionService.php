<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\IncomeOccurrence;
use App\Models\SavingGoal;
use App\Models\Tanda;
use App\Models\TandaPayment;
use App\Models\User;
use App\Models\WeeklyIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reparte un ingreso ya recibido hacia metas de ahorro, tandas o recibos
 * pendientes del usuario. Nunca se ejecuta sola: siempre requiere una
 * llamada explícita a apply() con las líneas que el usuario confirmó.
 *
 * No duplica dinero: usa exactamente las mismas operaciones que
 * SavingGoalController::contribute / TandaController::registerPayment /
 * BillController (marcar pagado), más un Expense (mismo patrón que
 * BillController::registerBillExpense) para que el saldo semanal
 * (WeeklyIncome) refleje que ese dinero ya no está "disponible".
 */
class IncomeDistributionService
{
    public function preview(IncomeOccurrence $occurrence): array
    {
        $total = (float) ($occurrence->received_amount ?? $occurrence->expected_amount);
        $rules = $occurrence->source->rules()->where('active', true)->orderBy('order')->get();

        return $rules->map(fn ($rule) => [
            'rule_id' => $rule->id,
            'target_type' => $rule->target_type,
            'target_id' => $rule->target_id,
            'target_label' => $this->resolveTargetLabel($rule->target_type, $rule->target_id),
            'mode' => $rule->mode,
            'amount' => $rule->amountFor($total),
        ])->values()->all();
    }

    /**
     * @param  array<int, array{target_type: string, target_id: ?int, amount: float}>  $allocations
     */
    public function apply(IncomeOccurrence $occurrence, User $user, array $allocations): IncomeOccurrence
    {
        if (! in_array($occurrence->status, ['received', 'partial'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Solo puedes distribuir un ingreso ya recibido.'],
            ]);
        }

        $available = (float) $occurrence->received_amount;
        $total = collect($allocations)->sum('amount');

        if ($total > $available + 0.01) {
            throw ValidationException::withMessages([
                'allocations' => ['La suma de lo distribuido no puede superar lo recibido.'],
            ]);
        }

        DB::transaction(function () use ($occurrence, $user, $allocations) {
            $weeklyIncome = $occurrence->weeklyIncome;
            $date = $occurrence->received_date ?? Carbon::today();

            foreach ($allocations as $allocation) {
                $amount = (float) ($allocation['amount'] ?? 0);

                if ($amount <= 0) {
                    continue;
                }

                $this->applyAllocation(
                    $occurrence,
                    $user,
                    $allocation['target_type'],
                    $allocation['target_id'] ?? null,
                    $amount,
                    $date,
                    $weeklyIncome
                );
            }

            if ($weeklyIncome) {
                $this->recalculateWeeklyIncome($weeklyIncome->fresh());
            }
        });

        return $occurrence->fresh();
    }

    protected function applyAllocation(
        IncomeOccurrence $occurrence,
        User $user,
        string $targetType,
        ?int $targetId,
        float $amount,
        $date,
        ?WeeklyIncome $weeklyIncome
    ): void {
        switch ($targetType) {
            case 'saving_goal':
                $goal = SavingGoal::where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('participants', fn ($qp) => $qp->where('user_id', $user->id));
                })->findOrFail($targetId);

                $goal->current_amount = (float) $goal->current_amount + $amount;
                if ($goal->current_amount >= $goal->target_amount && $goal->target_amount > 0) {
                    $goal->status = 'completed';
                }
                $goal->save();

                $this->recordExpense(
                    $user, $weeklyIncome, $amount, $date,
                    'income_distribution_saving', $targetId,
                    "Distribución de ingreso a meta: {$goal->name}"
                );
                break;

            case 'tanda':
                $tanda = Tanda::where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('members', fn ($qp) => $qp->where('user_id', $user->id));
                })->findOrFail($targetId);

                TandaPayment::create([
                    'tanda_id' => $tanda->id,
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'paid_at' => $date,
                    'status' => 'paid',
                    'notes' => 'Distribución de ingreso',
                ]);

                $this->recordExpense(
                    $user, $weeklyIncome, $amount, $date,
                    'income_distribution_tanda', $targetId,
                    "Distribución de ingreso a tanda: {$tanda->name}"
                );
                break;

            case 'bill':
                $bill = Bill::where('user_id', $user->id)->findOrFail($targetId);

                // El monto real que "sale" del disponible es el del recibo,
                // no el sugerido por la regla (evita descuadrar el Expense
                // contra un Bill que ya tiene su propio monto fijo).
                $billAmount = (float) $bill->amount;

                $alreadyExpensed = Expense::where('user_id', $user->id)
                    ->where('type', 'bill')
                    ->where('source_id', $bill->id)
                    ->exists();

                if (! $alreadyExpensed) {
                    $this->recordExpense(
                        $user, $weeklyIncome, $billAmount, $date,
                        'bill', $targetId, $bill->name
                    );
                }

                if (! $bill->paid_at) {
                    $bill->status = 'paid';
                    $bill->paid_at = $date;
                    $bill->save();
                }
                break;

            case 'free':
                // El dinero ya quedó en weekly_incomes.amount al recibir el
                // ingreso; no se crea ningún registro adicional.
                break;
        }
    }

    protected function recordExpense(
        User $user,
        ?WeeklyIncome $weeklyIncome,
        float $amount,
        $date,
        string $type,
        ?int $sourceId,
        string $description
    ): void {
        Expense::create([
            'user_id' => $user->id,
            'weekly_income_id' => $weeklyIncome?->id,
            'date' => $date,
            'amount' => $amount,
            'type' => $type,
            'source_id' => $sourceId,
            'description' => $description,
        ]);
    }

    protected function recalculateWeeklyIncome(WeeklyIncome $weeklyIncome): void
    {
        $totalSpent = $weeklyIncome->expenses()->sum('amount');

        $weeklyIncome->spent = $totalSpent;
        $weeklyIncome->leftover = max(0, $weeklyIncome->amount - $totalSpent);
        $weeklyIncome->save();
    }

    protected function resolveTargetLabel(string $targetType, ?int $targetId): ?string
    {
        return match ($targetType) {
            'saving_goal' => SavingGoal::find($targetId)?->name,
            'tanda' => Tanda::find($targetId)?->name,
            'bill' => Bill::find($targetId)?->name,
            default => 'Disponible / libre',
        };
    }
}

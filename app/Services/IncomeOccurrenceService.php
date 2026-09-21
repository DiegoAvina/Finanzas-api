<?php

namespace App\Services;

use App\Models\IncomeOccurrence;
use App\Models\WeeklyIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncomeOccurrenceService
{
    /**
     * Marca la ocurrencia como recibida (total o parcialmente, según el
     * monto). Idempotente: llamar dos veces con el mismo monto final no
     * vuelve a sumar al saldo semanal (ver applyReceipt).
     */
    public function receive(IncomeOccurrence $occurrence, ?float $amount, ?Carbon $date): IncomeOccurrence
    {
        $amount = $amount ?? (float) $occurrence->expected_amount;

        return $this->applyReceipt($occurrence, $amount, $date);
    }

    /**
     * Registra un ingreso parcial. A diferencia de receive(), exige
     * explícitamente que el monto sea menor al esperado.
     */
    public function partial(IncomeOccurrence $occurrence, float $amount, ?Carbon $date): IncomeOccurrence
    {
        if ($amount <= 0 || $amount >= (float) $occurrence->expected_amount) {
            throw ValidationException::withMessages([
                'amount' => ['El monto parcial debe ser mayor a 0 y menor al monto esperado.'],
            ]);
        }

        return $this->applyReceipt($occurrence, $amount, $date);
    }

    public function miss(IncomeOccurrence $occurrence): IncomeOccurrence
    {
        if ($occurrence->status !== 'expected') {
            throw ValidationException::withMessages([
                'status' => ['Solo un ingreso esperado puede marcarse como no recibido.'],
            ]);
        }

        $occurrence->status = 'missed';
        $occurrence->save();

        return $occurrence;
    }

    public function cancel(IncomeOccurrence $occurrence): IncomeOccurrence
    {
        if (! in_array($occurrence->status, ['expected', 'missed', 'partial'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Un ingreso recibido o ya cancelado no puede cancelarse.'],
            ]);
        }

        $occurrence->status = 'cancelled';
        $occurrence->save();

        return $occurrence;
    }

    protected function applyReceipt(IncomeOccurrence $occurrence, float $amount, ?Carbon $date): IncomeOccurrence
    {
        if (in_array($occurrence->status, ['cancelled', 'missed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['No se puede recibir un ingreso cancelado o marcado como no recibido.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['El monto debe ser mayor a 0.'],
            ]);
        }

        if ($amount > (float) $occurrence->expected_amount + 0.01) {
            throw ValidationException::withMessages([
                'amount' => ['No puedes recibir más del monto esperado.'],
            ]);
        }

        $date = $date ?? Carbon::today();
        $delta = round($amount - (float) $occurrence->applied_amount, 2);

        DB::transaction(function () use ($occurrence, $amount, $date, $delta) {
            if ($delta !== 0.0) {
                $weeklyIncome = $this->findOrCreateWeeklyIncome($occurrence->user_id, $date);
                $weeklyIncome->amount = (float) $weeklyIncome->amount + $delta;
                $weeklyIncome->save();

                $occurrence->weekly_income_id = $weeklyIncome->id;
            }

            $occurrence->received_amount = $amount;
            $occurrence->applied_amount = $amount;
            $occurrence->received_date = $date->toDateString();
            $occurrence->status = $amount >= (float) $occurrence->expected_amount ? 'received' : 'partial';
            $occurrence->save();

            if ($occurrence->status === 'received') {
                $this->generateNextOccurrenceIfRecurring($occurrence);
            }
        });

        return $occurrence->fresh();
    }

    protected function generateNextOccurrenceIfRecurring(IncomeOccurrence $occurrence): void
    {
        $source = $occurrence->source;

        if (! $source || ! $source->is_recurring || ! $source->active) {
            return;
        }

        // Evita duplicar si ya existe una ocurrencia futura esperada para esta fuente.
        $alreadyHasNext = IncomeOccurrence::where('income_source_id', $source->id)
            ->where('status', 'expected')
            ->exists();

        if ($alreadyHasNext) {
            return;
        }

        IncomeOccurrence::create([
            'income_source_id' => $source->id,
            'user_id' => $occurrence->user_id,
            'expected_amount' => $source->default_amount ?? $occurrence->expected_amount,
            'expected_date' => $source->nextExpectedDateAfter($occurrence->expected_date)->toDateString(),
            'status' => 'expected',
        ]);
    }

    protected function findOrCreateWeeklyIncome(int $userId, Carbon $date): WeeklyIncome
    {
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        return WeeklyIncome::firstOrCreate(
            [
                'user_id' => $userId,
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            [
                'amount' => 0,
                'spent' => 0,
                'saved' => 0,
                'leftover' => 0,
            ]
        );
    }
}

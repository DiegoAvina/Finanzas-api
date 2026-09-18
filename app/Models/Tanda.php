<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tanda extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'contribution_amount',
        'num_members',
        'rounds_total',
        'pot_amount',
        'frequency',
        'start_date',
        'current_round',
        'next_payment_date',
        'status',
    ];

    protected $casts = [
        'contribution_amount' => 'decimal:2',
        'pot_amount' => 'decimal:2',
        'start_date' => 'date',
        'next_payment_date' => 'date',
    ];

    protected $appends = [
        'progress_percent',
    ];

    /**
     * Dueño / creador de la tanda
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Miembros (nombre interno)
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tanda_members')
            ->withPivot('turn_order', 'has_received', 'received_at')
            ->withTimestamps();
    }

    /**
     * Alias que usa el Dashboard: participants()
     * (apunta a la misma relación que members)
     */
    public function participants(): BelongsToMany
    {
        return $this->members();
    }

    /**
     * Pagos / movimientos de la tanda
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TandaPayment::class);
    }

    /**
     * Progreso de la tanda en porcentaje
     */
    public function getProgressPercentAttribute(): float
    {
        $totalRounds = (int) ($this->num_members ?? 0);
        $current = (int) ($this->current_round ?? 1);

        if ($totalRounds <= 0) {
            return 0.0;
        }

        return min(100, round(($current / $totalRounds) * 100, 1));
    }

    /**
     * Calcula la siguiente fecha de pago a partir de una fecha base,
     * según la frecuencia de la tanda (weekly/biweekly/monthly).
     */
    public function nextPaymentDateAfter(\Illuminate\Support\Carbon $from): \Illuminate\Support\Carbon
    {
        return match ($this->frequency) {
            'biweekly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            default => $from->copy()->addWeek(),
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class IncomeSource extends Model
{
    public const TYPES = [
        'salary' => 'Nómina',
        'freelance' => 'Freelance',
        'business' => 'Negocio',
        'sale' => 'Venta',
        'investment' => 'Inversión',
        'bonus' => 'Bono',
        'gift' => 'Regalo',
        'refund' => 'Reembolso',
        'other' => 'Otro',
    ];

    public const FREQUENCIES = ['weekly', 'biweekly', 'monthly', 'yearly', 'irregular'];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'default_amount',
        'estimated_min_amount',
        'estimated_max_amount',
        'frequency',
        'is_recurring',
        'active',
        'notes',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'estimated_min_amount' => 'decimal:2',
        'estimated_max_amount' => 'decimal:2',
        'is_recurring' => 'boolean',
        'active' => 'boolean',
    ];

    protected $appends = [
        'type_label',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(IncomeOccurrence::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(IncomeDistributionRule::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? self::TYPES['other'];
    }

    /**
     * Siguiente fecha esperada a partir de una fecha base, según frequency.
     * Mismo patrón que Tanda::nextPaymentDateAfter().
     */
    public function nextExpectedDateAfter(Carbon $from): Carbon
    {
        return match ($this->frequency) {
            'weekly' => $from->copy()->addWeek(),
            'biweekly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'yearly' => $from->copy()->addYearNoOverflow(),
            default => $from->copy()->addMonthNoOverflow(),
        };
    }
}

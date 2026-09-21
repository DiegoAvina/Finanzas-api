<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class IncomeOccurrence extends Model
{
    public const STATUSES = ['expected', 'received', 'partial', 'missed', 'cancelled'];

    protected $fillable = [
        'income_source_id',
        'user_id',
        'expected_amount',
        'received_amount',
        'applied_amount',
        'expected_date',
        'received_date',
        'status',
        'weekly_income_id',
        'notes',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'expected_date' => 'date',
        'received_date' => 'date',
    ];

    protected $appends = [
        'remaining_amount',
        'is_overdue',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'income_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function weeklyIncome(): BelongsTo
    {
        return $this->belongsTo(WeeklyIncome::class);
    }

    public function getRemainingAmountAttribute(): float
    {
        $received = (float) ($this->received_amount ?? 0);

        return max(0, (float) $this->expected_amount - $received);
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->status !== 'expected' || ! $this->expected_date) {
            return false;
        }

        return $this->expected_date->lt(Carbon::today());
    }
}

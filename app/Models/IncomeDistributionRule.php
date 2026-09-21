<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeDistributionRule extends Model
{
    public const TARGET_TYPES = ['saving_goal', 'tanda', 'bill', 'free'];

    public const MODES = ['percent', 'fixed'];

    protected $fillable = [
        'income_source_id',
        'user_id',
        'target_type',
        'target_id',
        'mode',
        'value',
        'order',
        'active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'income_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Monto sugerido de esta regla dado un monto total a distribuir.
     */
    public function amountFor(float $totalAmount): float
    {
        if ($this->mode === 'percent') {
            return round($totalAmount * ((float) $this->value / 100), 2);
        }

        return (float) $this->value;
    }
}

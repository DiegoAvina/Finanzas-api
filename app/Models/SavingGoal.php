<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class SavingGoal extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'target_amount',
        'current_amount',
        'deadline',
        'category',
        'image_path',
        'is_group',
        'status',
    ];

    protected $casts = [
        'target_amount'   => 'float',   // 👈 en vez de decimal:2
        'current_amount'  => 'float',   // 👈 en vez de decimal:2
        'deadline'        => 'date',
        'is_group'        => 'boolean',
    ];

    protected $appends = [
        'progress_percent',
        'image_url',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Tabla saving_goal_members
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saving_goal_members')
            ->withPivot('role', 'expected_contribution')
            ->withTimestamps();
    }

    public function movements()
    {
        return $this->hasMany(SavingGoalMovement::class);
    }

    public function getProgressPercentAttribute(): float
    {
        $target  = (float) ($this->target_amount ?? 0);
        $current = (float) ($this->current_amount ?? 0);

        if ($target <= 0) {
            return 0.0;
        }

        return min(100, round(($current / $target) * 100, 1));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}

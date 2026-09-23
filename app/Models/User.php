<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function sharedSavingGoals()
    {
        return $this->belongsToMany(SavingGoal::class, 'saving_goal_members')
            ->withPivot(['role', 'expected_contribution'])
            ->withTimestamps();
    }

    public function weeklyIncomes()
    {
        return $this->hasMany(WeeklyIncome::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}

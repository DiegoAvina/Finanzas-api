<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

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

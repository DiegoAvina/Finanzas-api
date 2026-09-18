<?php

namespace App\Policies;

use App\Models\SavingGoal;
use App\Models\User;

class SavingGoalPolicy
{
    /**
     * Dueño o participante: puede ver la meta y aportar a ella.
     */
    public function contribute(User $user, SavingGoal $savingGoal): bool
    {
        return $savingGoal->user_id === $user->id
            || $savingGoal->participants()->where('users.id', $user->id)->exists();
    }

    /**
     * Solo el dueño puede agregar miembros a la meta.
     */
    public function manageMembers(User $user, SavingGoal $savingGoal): bool
    {
        return $savingGoal->user_id === $user->id;
    }
}

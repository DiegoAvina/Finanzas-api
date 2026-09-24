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

    /**
     * Solo el dueño puede editar la meta o cambiar su portada.
     */
    public function update(User $user, SavingGoal $savingGoal): bool
    {
        return $savingGoal->user_id === $user->id;
    }

    /**
     * Solo el dueño puede eliminar la meta.
     */
    public function delete(User $user, SavingGoal $savingGoal): bool
    {
        return $savingGoal->user_id === $user->id;
    }

    /**
     * Solo el dueño puede retirar dinero de la meta.
     */
    public function withdraw(User $user, SavingGoal $savingGoal): bool
    {
        return $savingGoal->user_id === $user->id;
    }
}

<?php

namespace App\Policies;

use App\Models\Tanda;
use App\Models\User;

class TandaPolicy
{
    /**
     * Dueño o miembro: puede ver la tanda y registrar pagos.
     */
    public function registerPayment(User $user, Tanda $tanda): bool
    {
        return $tanda->user_id === $user->id
            || $tanda->members()->where('users.id', $user->id)->exists();
    }

    /**
     * Solo el dueño puede agregar miembros a la tanda.
     */
    public function manageMembers(User $user, Tanda $tanda): bool
    {
        return $tanda->user_id === $user->id;
    }
}

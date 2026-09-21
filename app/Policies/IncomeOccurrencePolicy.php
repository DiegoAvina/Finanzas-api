<?php

namespace App\Policies;

use App\Models\IncomeOccurrence;
use App\Models\User;

class IncomeOccurrencePolicy
{
    public function view(User $user, IncomeOccurrence $incomeOccurrence): bool
    {
        return $incomeOccurrence->user_id === $user->id;
    }

    public function update(User $user, IncomeOccurrence $incomeOccurrence): bool
    {
        return $incomeOccurrence->user_id === $user->id;
    }
}

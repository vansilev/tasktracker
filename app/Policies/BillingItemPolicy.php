<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BillingItem;
use App\Models\User;

class BillingItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewBilling)
            || $user->hasPermission(Permission::ManageBilling);
    }

    public function view(User $user, BillingItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBilling);
    }

    public function update(User $user, BillingItem $item): bool
    {
        return $user->hasPermission(Permission::ManageBilling);
    }

    public function markPaid(User $user, BillingItem $item): bool
    {
        if ($user->hasPermission(Permission::ManageBilling)) {
            return true;
        }

        return $item->payer_user_id === $user->id;
    }
}

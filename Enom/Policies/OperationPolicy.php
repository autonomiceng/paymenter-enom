<?php

namespace Paymenter\Extensions\Servers\Enom\Policies;

use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

/** Registrar requests are a ledger. Staff read them and act through dedicated actions only. */
class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function view(User $user, Operation $record): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function update(User $user, Operation $record): bool
    {
        return false;
    }

    public function create(User $user): bool { return false; }

    public function delete(User $user, Operation $record): bool { return false; }

    public function deleteAny(User $user): bool { return false; }

    public function forceDelete(User $user, Operation $record): bool { return false; }

    public function forceDeleteAny(User $user): bool { return false; }

    public function restore(User $user, Operation $record): bool { return false; }

    public function restoreAny(User $user): bool { return false; }

    public function replicate(User $user, Operation $record): bool { return false; }

    public function reorder(User $user): bool { return false; }
}

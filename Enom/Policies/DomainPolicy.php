<?php

namespace Paymenter\Extensions\Servers\Enom\Policies;

use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

/** Staff manage domains through explicit actions; records are never created or deleted here. */
class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function view(User $user, Domain $record): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function update(User $user, Domain $record): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function create(User $user): bool { return false; }

    public function delete(User $user, Domain $record): bool { return false; }

    public function deleteAny(User $user): bool { return false; }

    public function forceDelete(User $user, Domain $record): bool { return false; }

    public function forceDeleteAny(User $user): bool { return false; }

    public function restore(User $user, Domain $record): bool { return false; }

    public function restoreAny(User $user): bool { return false; }

    public function replicate(User $user, Domain $record): bool { return false; }

    public function reorder(User $user): bool { return false; }
}

<?php

namespace Paymenter\Extensions\Servers\Enom\Policies;

use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Models\Tld;

/** Endings are imported from Enom, never created or deleted by hand. */
class TldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function view(User $user, Tld $record): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function update(User $user, Tld $record): bool
    {
        return $user->hasPermission('admin.enom.manage');
    }

    public function create(User $user): bool { return false; }

    public function delete(User $user, Tld $record): bool { return false; }

    public function deleteAny(User $user): bool { return false; }

    public function forceDelete(User $user, Tld $record): bool { return false; }

    public function forceDeleteAny(User $user): bool { return false; }

    public function restore(User $user, Tld $record): bool { return false; }

    public function restoreAny(User $user): bool { return false; }

    public function replicate(User $user, Tld $record): bool { return false; }

    public function reorder(User $user): bool { return false; }
}

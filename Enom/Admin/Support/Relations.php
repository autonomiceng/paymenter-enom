<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

/**
 * Admin-only relation from a domain to its owner (`user_id`), registered at runtime so the
 * shared model stays unchanged. Used for table search, sorting and eager loading.
 */
final class Relations
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) { return; }
        Domain::resolveRelationUsing('client', fn (Domain $domain) => $domain->belongsTo(User::class, 'user_id'));
        self::$registered = true;
    }
}

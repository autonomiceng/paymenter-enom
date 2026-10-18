<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Exceptions\DisplayException;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

/** Preserve terminal financial history while reserving a name for only one current attempt. */
final class DomainReservations
{
    public static function retryable(Domain $domain): bool
    {
        if (!in_array($domain->status, ['registration_rejected', 'transfer_rejected', 'cancelled'], true)
            || $domain->provider_expires_at !== null) { return false; }
        $requests = $domain->operations()->get();
        $initials = $requests->whereIn('action', ['register', 'transfer']);
        if ($initials->isEmpty() || $requests->contains(fn ($request) => $request->state !== 'rejected')) { return false; }
        return $domain->status !== 'cancelled' || $initials->every(fn ($request) => TransferOrders::unsent($request));
    }

    public static function blocking(string $name): ?Domain
    {
        $domain = Domain::where('reservation_name', $name)->first();
        return $domain && !self::retryable($domain) ? $domain : null;
    }

    /** Called inside checkout after product/TLD locks. The unique key also serializes different sellers. */
    public static function releaseTerminal(string $name): void
    {
        $domain = Domain::where('reservation_name', $name)->lockForUpdate()->first();
        if (!$domain) { return; }
        // No worker may turn a terminal request back into live work while its domain is locked.
        $domain->operations()->lockForUpdate()->get();
        if (!self::retryable($domain)) { throw new DisplayException($name.' already has an order.'); }
        $domain->update(['reservation_name' => null]);
    }
}

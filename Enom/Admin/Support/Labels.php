<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Throwable;

/**
 * Staff wording and badge colours for the Filament admin. Display only: no writes, no provider calls.
 * Customer-facing wording stays in DomainPresentation.
 */
final class Labels
{
    /** States staff may need to act on. `disposed` keeps the domain paused until staff act on it. */
    public const ATTENTION_STATES = ['uncertain', 'held', 'disposed'];

    /** States that wait on the customer: payment or a transfer code. A hold for account details waits on them too. */
    public const CUSTOMER_STATES = ['awaiting_payment', 'awaiting_code'];

    public const STATE_GROUPS = [
        'attention' => self::ATTENTION_STATES,
        'customer' => self::CUSTOMER_STATES,
        'progress' => ['queued', 'running', 'pending'],
        'completed' => ['succeeded'],
        'failed' => ['rejected'],
    ];

    private const ORDER_ACTIONS = ['register', 'renew', 'transfer'];

    private const UNRECONCILABLE_ACTIONS = ['epp_sync', 'epp_email'];

    /** Requests only staff can move on. */
    public static function needsStaff(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereIn('state', self::ATTENTION_STATES)
                ->where(fn (Builder $q) => $q->where('state', '!=', 'held')->orWhereNull('safe_code')->orWhere('safe_code', '!=', 'contact_details_required')))
            ->orWhere(fn (Builder $q) => $q->where('state', 'pending')->where(fn (Builder $q) => $q
                ->whereIn('action', self::UNRECONCILABLE_ACTIONS)
                ->orWhere(fn (Builder $q) => $q->whereIn('action', self::ORDER_ACTIONS)
                    ->where(fn (Builder $q) => $q->whereNull('provider_order_id')->orWhereIn('provider_order_id', ['', '0']))))));
    }

    /** Requests waiting for the customer to pay, enter a transfer code or update their account details. */
    public static function waitingOnCustomer(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('state', self::CUSTOMER_STATES)
            ->orWhere(fn (Builder $held) => $held->where('state', 'held')->where('safe_code', 'contact_details_required')));
    }

    /** Request group for list tabs: staff attention and customer waits use the predicates above. */
    public static function inGroup(Builder $query, string $group): Builder
    {
        return match ($group) {
            'attention' => self::needsStaff($query),
            'customer' => self::waitingOnCustomer($query),
            'progress' => $query->whereIn('state', self::STATE_GROUPS['progress'])->whereNot(fn (Builder $q) => self::needsStaff($q)),
            default => $query->whereIn('state', self::STATE_GROUPS[$group]),
        };
    }

    public static function domainStatusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'registration_pending', 'transfer_pending' => 'info',
            'expired', 'redemption', 'needs_review', 'registration_rejected', 'transfer_rejected' => 'danger',
            'grace' => 'warning',
            default => 'gray',
        };
    }

    public static function domainStatus(?string $status): string
    {
        return match ($status) {
            'registration_pending' => 'Registering',
            'transfer_pending' => 'Transferring',
            'grace' => 'Expired, grace period',
            default => DomainPresentation::domainStatus($status),
        };
    }

    /** @return array<string, string> */
    public static function domainStatusOptions(): array
    {
        $options = [];
        foreach (['active', 'registration_pending', 'transfer_pending', 'expired', 'grace', 'redemption', 'needs_review', 'registration_rejected', 'transfer_rejected', 'cancelled'] as $status) {
            $options[$status] = self::domainStatus($status);
        }
        return $options;
    }

    public static function state(?string $state): string
    {
        return match ($state) {
            'awaiting_payment' => 'Waiting for payment',
            'awaiting_code' => 'Needs transfer code',
            'queued' => 'Queued',
            'running' => 'Sending',
            'pending' => 'In progress at Enom',
            'uncertain' => 'Unconfirmed',
            'held' => 'On hold',
            'succeeded' => 'Completed',
            'rejected' => 'Failed',
            'disposed' => 'Closed by staff',
            default => DomainPresentation::state($state),
        };
    }

    public const NOT_SENT = 'Queued — not sent yet';

    /** A queued request on a server with processing turned off: nothing has been sent to Enom. */
    public static function notSent(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): bool
    {
        if ($operation->state !== 'queued') { return false; }
        $serverId = (int) $operation->domain?->server_id;
        if ($serverId <= 0) { return false; }
        // Display-only memo: a fresh request must see current server settings.
        $request = request();
        $key = self::class.'.processing';
        $processing = $request->attributes->get($key, []);
        $processing[$serverId] ??= RuntimeGuard::flag($serverId, 'operations_enabled');
        $request->attributes->set($key, $processing);
        return !$processing[$serverId];
    }

    /** Pending without a usable reconciliation path does not confirm receipt by Enom. */
    public static function notConfirmed(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): bool
    {
        return $operation->state === 'pending' && (in_array($operation->action, self::UNRECONCILABLE_ACTIONS, true)
            || in_array($operation->action, self::ORDER_ACTIONS, true) && in_array($operation->provider_order_id, [null, '', '0'], true));
    }

    /** State label for one request; a queued request says when processing is off. */
    public static function requestState(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): string
    {
        if (self::notConfirmed($operation)) { return 'Not confirmed'; }
        return self::notSent($operation) ? self::NOT_SENT : self::state($operation->state);
    }

    public static function stateColor(?string $state): string
    {
        return match ($state) {
            'succeeded' => 'success',
            'awaiting_payment', 'awaiting_code', 'held' => 'warning',
            'queued', 'running', 'pending' => 'info',
            'uncertain', 'rejected' => 'danger',
            default => 'gray',
        };
    }

    /** One sentence: what the state means for staff and what to do next. */
    public static function stateHint(?string $state, ?\Paymenter\Extensions\Servers\Enom\Models\Operation $operation = null): string
    {
        if ($operation && $state === 'pending' && self::notConfirmed($operation)) {
            return 'No reply from Enom. Check with Enom before retrying.';
        }
        if ($operation && $state === 'queued' && self::notSent($operation)) {
            return 'Processing is off in the Enom server settings. Sends once it is turned on.';
        }
        if ($operation?->safe_code === 'transfer_not_registered' && $state === 'held') {
            return 'Never sent: the registry has no record of this name, so it can’t be transferred. Refund it as credit instead of resuming.';
        }
        if ($operation?->safe_code === 'dispatch_lease_insufficient' && $state === 'held') {
            return 'Never sent: too little worker or claim time remained. Review the unsent request before resuming.';
        }
        if ($operation?->safe_code === 'contact_details_required' && $state === 'held') {
            return 'Needs account details. Ask the customer to save their account details, then use updated account details on the domain page.';
        }
        if ($operation?->action === 'nameservers' && str_starts_with($operation->operation_key, 'initial-nameservers:')
            && in_array($state, ['held', 'rejected', 'uncertain'], true)) {
            return 'Registration succeeded; the nameserver update needs review. Never resend an uncertain request.';
        }
        return match ($state) {
            'awaiting_payment' => 'Starts when the invoice is paid.',
            'awaiting_code' => 'Waiting for the customer transfer code. Refunds to credit after 30 days.',
            'queued' => 'Sends on the next run.',
            'running' => 'Sending to Enom now.',
            'pending' => 'Enom is still working on it.',
            'uncertain' => 'Unclear answer from Enom. Check it; never resend.',
            'held' => 'Never sent. Resume it if still wanted.',
            'succeeded' => 'Done at Enom.',
            'rejected' => 'Declined or cancelled. Nothing to do.',
            'disposed' => 'Closed after review. The domain stays paused.',
            default => 'Unknown state.',
        };
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];
        foreach (['awaiting_payment', 'awaiting_code', 'queued', 'running', 'pending', 'uncertain', 'held', 'succeeded', 'rejected', 'disposed'] as $state) {
            $options[$state] = self::state($state);
        }
        return $options;
    }

    /** Staff-only plain text Enom returned for a failed or unclear request. */
    public static function providerMessage(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): ?string
    {
        try { $message = $operation->cost['provider_message'] ?? null; } catch (Throwable) { return null; }
        if (!is_string($message) || trim($message) === '') { return null; }
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $message)), 0, 300, '…');
    }

    /** Request name with its term and, for child nameservers, the host: "Renewal, 3 years". */
    public static function request(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): string
    {
        $label = self::action($operation->action);
        if (in_array($operation->action, ['register', 'transfer', 'renew'], true) && (int) $operation->term > 0) {
            return $label.', '.self::years((int) $operation->term);
        }
        if (str_starts_with((string) $operation->action, 'glue_')) {
            try { $host = $operation->payload['host'] ?? null; } catch (Throwable) { $host = null; }
            if (is_string($host) && $host !== '' && strlen($host) <= 253) { return $label.', '.$host; }
        }
        return $label;
    }

    public static function years(int $years): string
    {
        return $years.' year'.($years === 1 ? '' : 's');
    }

    /** Account credit given back for a paid request that failed, or null. @return array{amount: string, currency: string, at: ?string}|null */
    public static function refund(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): ?array
    {
        try { $refund = $operation->cost['refund'] ?? null; } catch (Throwable) { return null; }
        if (!is_array($refund) || !is_numeric($refund['amount'] ?? null)) { return null; }
        return ['amount' => (string) $refund['amount'], 'currency' => is_string($refund['currency'] ?? null) ? $refund['currency'] : 'USD',
            'at' => is_string($refund['at'] ?? null) ? $refund['at'] : null];
    }

    public static function refundText(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): ?string
    {
        $refund = self::refund($operation);
        return $refund ? 'Refunded '.self::money($refund['amount'], $refund['currency']).' as credit' : null;
    }

    public static function action(?string $action): string
    {
        return match ($action) {
            'lock' => 'Transfer lock change',
            'epp_sync' => 'Transfer code sync',
            'epp_email' => 'Transfer code email',
            'privacy_purchase' => 'ID Protect purchase',
            'privacy_renew' => 'ID Protect renewal',
            'privacy_enable' => 'ID Protect on',
            'privacy_disable' => 'ID Protect off',
            'provider_renewal_disable' => 'Enom auto-renew off',
            'glue_add' => 'New child nameserver',
            'glue_update' => 'Child nameserver change',
            'glue_delete' => 'Child nameserver removal',
            default => DomainPresentation::action($action),
        };
    }

    /** Management requests never bill, including retained rows with the default invoice basis. */
    public static function requestFunding(\Paymenter\Extensions\Servers\Enom\Models\Operation $operation): ?string
    {
        return in_array($operation->action, DomainPresentation::FINANCIAL_ACTIONS, true)
            ? $operation->funding_basis : 'non_billable_management';
    }

    public static function funding(?string $basis): string
    {
        return match ($basis) {
            'paid_invoice' => 'Client invoice',
            'complimentary' => 'Complimentary',
            'non_billable_management' => 'No charge',
            null, '' => '—',
            default => DomainPresentation::funding($basis),
        };
    }

    public static function fundingColor(?string $basis): string
    {
        return match ($basis) {
            'paid_invoice' => 'success',
            'complimentary' => 'primary',
            default => 'gray',
        };
    }

    public static function money(mixed $amount, ?string $currency = 'USD'): ?string
    {
        if (!is_numeric($amount)) { return null; }
        // Cents unless Enom's four-place cost has a sub-cent part: $8.95, $8.955.
        $value = number_format((float) $amount, 4, '.', ',');
        $value = preg_replace('/(\.\d\d\d*?)0+$/', '$1', $value);
        // Sign before the symbol: -$4.00, not $-4.00.
        $sign = str_starts_with($value, '-') ? '-' : '';
        $value = ltrim($value, '-');
        return ($currency === null || $currency === 'USD') ? $sign.'$'.$value : $sign.$value.' '.$currency;
    }

    public static function day(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) { return CarbonImmutable::instance($value); }
        if (!is_string($value) || $value === '') { return null; }
        try { return CarbonImmutable::parse($value); } catch (Throwable) { return null; }
    }

    /** Whole days from today to an expiry date; negative once it has passed. */
    public static function daysUntil(mixed $date): ?int
    {
        $day = self::day($date);
        if (!$day) { return null; }
        return (int) CarbonImmutable::today()->diffInDays($day->startOfDay(), false);
    }

    public static function expiryColor(mixed $date): ?string
    {
        $days = self::daysUntil($date);
        return match (true) {
            $days === null => null,
            $days < 0 => 'danger',
            $days <= 30 => 'warning',
            default => null,
        };
    }

    public static function expiryHint(mixed $date): ?string
    {
        $days = self::daysUntil($date);
        return match (true) {
            $days === null => null,
            $days < -1 => 'Expired '.abs($days).' days ago',
            $days === -1 => 'Expired yesterday',
            $days === 0 => 'Expires today',
            $days === 1 => 'Tomorrow',
            $days <= 60 => 'In '.$days.' days',
            $days < 365 => 'In '.max(2, (int) CarbonImmutable::today()->diffInMonths(self::day($date))).' months',
            default => ($years = (int) CarbonImmutable::today()->diffInYears(self::day($date))) <= 1 ? 'In a year' : 'In '.$years.' years',
        };
    }

    /** Last time Enom data was read for a domain: on-demand refresh or the daily check. */
    public static function lastChecked(Domain $domain): ?CarbonImmutable
    {
        $observed = null;
        try { $observed = $domain->observation['succeeded_at'] ?? null; } catch (Throwable) {}
        $latest = DomainPresentation::latest($domain->last_synced_at, $observed);
        return self::day($latest);
    }

    /** Renewal funding comes only from this domain's staff setting. */
    public static function whoPays(Domain $domain): array
    {
        $free = Pricing::isComplimentary($domain);
        return ['mode' => $free ? 'complimentary' : 'standard', 'label' => $free ? 'Complimentary' : 'Standard', 'source' => 'domain'];
    }

    public static function whoPaysColor(string $mode): string
    {
        return match ($mode) { 'complimentary' => 'primary', default => 'gray' };
    }
}

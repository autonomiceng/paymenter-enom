<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Throwable;

/**
 * Staff wording and badge colours for the Filament admin. Display only: no writes, no provider calls.
 * Customer-facing wording stays in DomainPresentation.
 */
final class Labels
{
    /** Requests staff should look at. `disposed` keeps the domain paused until staff act on it. */
    public const ATTENTION_STATES = ['uncertain', 'held', 'pending', 'disposed'];

    public const STATE_GROUPS = [
        'attention' => self::ATTENTION_STATES,
        'payment' => ['awaiting_payment'],
        'progress' => ['queued', 'running'],
        'completed' => ['succeeded'],
        'failed' => ['rejected'],
    ];

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
        foreach (['active', 'registration_pending', 'transfer_pending', 'expired', 'grace', 'redemption', 'needs_review', 'registration_rejected', 'transfer_rejected'] as $status) {
            $options[$status] = self::domainStatus($status);
        }
        return $options;
    }

    public static function state(?string $state): string
    {
        return match ($state) {
            'awaiting_payment' => 'Waiting for payment',
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

    public static function stateColor(?string $state): string
    {
        return match ($state) {
            'succeeded' => 'success',
            'awaiting_payment', 'held' => 'warning',
            'queued', 'running', 'pending' => 'info',
            'uncertain', 'rejected' => 'danger',
            default => 'gray',
        };
    }

    /** One sentence: what the state means for staff and what to do next. */
    public static function stateHint(?string $state): string
    {
        return match ($state) {
            'awaiting_payment' => 'Starts when the invoice is paid.',
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
        foreach (['awaiting_payment', 'queued', 'running', 'pending', 'uncertain', 'held', 'succeeded', 'rejected', 'disposed'] as $state) {
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
            default => DomainPresentation::action($action),
        };
    }

    public static function funding(?string $basis): string
    {
        return match ($basis) {
            'paid_invoice' => 'Client invoice',
            'complimentary' => 'Complimentary',
            'sponsored', 'operator_funded' => 'Legacy',
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
            'sponsored', 'operator_funded' => 'warning',
            default => 'gray',
        };
    }

    public static function money(mixed $amount, ?string $currency = 'USD'): ?string
    {
        if (!is_numeric($amount)) { return null; }
        $value = number_format((float) $amount, 2, '.', ',');
        return ($currency === null || $currency === 'USD') ? '$'.$value : $value.' '.$currency;
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

    /** @var array<int, bool> per-request cache of client grants */
    private static array $clientGrant = [];

    public static function clientComplimentary(?User $user): bool
    {
        if (!$user) { return false; }
        return self::$clientGrant[(int) $user->id] ??= Pricing::clientComplimentary($user);
    }

    public static function forgetClient(int $userId): void { unset(self::$clientGrant[$userId]); }

    /**
     * Who pays for a domain's next renewal: [mode, label, source].
     * Mirrors Pricing::mode() without a query per row for domains with their own setting.
     *
     * @return array{mode: string, label: string, source: string}
     */
    public static function whoPays(Domain $domain): array
    {
        $own = $domain->price_mode;
        if ($own === 'complimentary') { return ['mode' => 'complimentary', 'label' => 'Complimentary', 'source' => 'domain']; }
        if ($own === 'custom') {
            $price = self::money($domain->service?->price, $domain->service?->currency_code);
            return ['mode' => 'custom', 'label' => $price ? 'Custom '.$price : 'Custom', 'source' => 'domain'];
        }
        $client = $domain->relationLoaded('client') ? $domain->getRelation('client') : User::find($domain->user_id);
        if ($own !== 'standard' && self::clientComplimentary($client)) {
            return ['mode' => 'complimentary', 'label' => 'Complimentary', 'source' => 'client'];
        }
        return ['mode' => 'standard', 'label' => 'Standard', 'source' => $own === 'standard' ? 'domain' : 'client'];
    }

    public static function whoPaysColor(string $mode): string
    {
        return match ($mode) { 'complimentary' => 'primary', 'custom' => 'info', default => 'gray' };
    }
}

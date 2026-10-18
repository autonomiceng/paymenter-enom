<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Pure display mapping for Enom views. No queries, provider calls, private values or side effects.
 * Unknown codes fall back to a readable form of the code itself.
 */
final class DomainPresentation
{
    public const FINANCIAL_ACTIONS = ['register', 'transfer', 'renew', 'privacy_purchase', 'privacy_renew'];

    /** Safe codes a customer may see, mapped to a short outcome key. Everything else stays hidden. */
    private const OUTCOMES = ['renewal_cancelled_unpaid' => 'cancelled_unpaid', 'initial_cancelled_unpaid' => 'cancelled_unpaid', 'initial_cancelled_payment_review' => 'cancelled_payment_review', 'transfer_code_timeout' => 'code_timeout', 'epp_sync_acknowledged' => 'acknowledged', 'contact_details_required' => 'needs_details'];

    public static function outcome(mixed $safeCode): ?string
    {
        return is_string($safeCode) ? (self::OUTCOMES[$safeCode] ?? null) : null;
    }

    public static function day(mixed $value): ?string
    {
        $date = self::parse($value);
        return $date?->format('M j, Y');
    }

    public static function moment(mixed $value): ?string
    {
        $date = self::parse($value);
        return $date ? $date->utc()->format('M j, Y, H:i').' UTC' : null;
    }

    /** The later of two timestamps, for showing one "last checked" time. */
    public static function latest(mixed ...$values): mixed
    {
        $best = null;
        $bestDate = null;
        foreach ($values as $value) {
            $date = self::parse($value);
            if ($date && (!$bestDate || $date->greaterThan($bestDate))) { $best = $value; $bestDate = $date; }
        }
        return $best;
    }

    public static function state(?string $state, ?string $outcome = null): string
    {
        if ($state === 'rejected' && $outcome === 'cancelled_unpaid') { return 'Cancelled, invoice unpaid'; }
        if ($state === 'succeeded' && $outcome === 'acknowledged') { return 'Accepted by the registrar'; }
        return match ($state) {
            'awaiting_payment' => 'Awaiting payment', 'awaiting_code' => 'Needs your code', 'queued' => 'Queued', 'running' => 'Sending',
            'pending' => 'Waiting for the registrar', 'uncertain' => 'Needs review', 'held' => 'On hold',
            'succeeded' => 'Completed', 'rejected' => 'Declined', 'disposed' => 'Closed by support',
            default => self::readable($state, 'Not recorded'),
        };
    }

    public static function action(?string $action): string
    {
        return match ($action) {
            'register' => 'Registration', 'transfer' => 'Transfer', 'renew' => 'Renewal',
            'privacy_purchase' => 'Privacy purchase', 'privacy_renew' => 'Privacy renewal',
            'nameservers' => 'Nameserver change', 'contacts' => 'Contact update', 'lock' => 'Registrar lock change',
            'epp_sync' => 'Transfer code sync', 'privacy_enable' => 'Privacy turned on', 'privacy_disable' => 'Privacy turned off',
            'hosts' => 'DNS record update', 'srv' => 'SRV record update', 'dnssec_add' => 'DS record added',
            'dnssec_delete' => 'DS record removed', 'provider_renewal_disable' => 'Registrar auto-renew turned off',
            default => self::readable($action, 'Request'),
        };
    }

    public const AWAITING_PAYMENT = 'Awaiting payment';

    /**
     * Status for a domain row or header. A pending registration or transfer uses its exact-bound request
     * and verified invoice status ($request: state, invoiced, invoice_status), never the domain status alone.
     * Missing or unverified links stay "not confirmed". No label promises registrar completion.
     */
    public static function progress(?string $status, ?array $request): string
    {
        $kind = match ($status) { 'registration_pending' => 'Registration', 'transfer_pending' => 'Transfer', default => null };
        if ($kind === null) { return self::domainStatus($status); }
        $state = is_string($request['state'] ?? null) ? $request['state'] : null;
        $invoice = is_string($request['invoice_status'] ?? null) ? $request['invoice_status'] : null;
        // Staff funding has no customer invoice. A recorded but unverified invoice link is never treated as paid.
        $settled = $request !== null && (($request['invoiced'] ?? true) === false || $invoice === 'paid');
        return match (true) {
            $state === 'awaiting_payment' && $invoice === 'pending' => self::AWAITING_PAYMENT,
            $state === 'awaiting_code' && $settled => 'Needs your code',
            $state === 'held' && ($request['safe_code'] ?? null) === 'contact_details_required' => 'Needs your details',
            $state === 'queued' && $settled => $kind.' waiting to be sent',
            in_array($state, ['running', 'pending'], true) && $settled => $kind.' in progress',
            in_array($state, ['uncertain', 'held', 'disposed'], true), $state === 'awaiting_payment' && $invoice === 'paid' => $kind.' needs review',
            default => $kind.' status not confirmed',
        };
    }

    public static function domainStatus(?string $status): string
    {
        return match ($status) {
            'cancelled' => 'Cancelled', 'active' => 'Active', 'registration_pending' => 'Registration in progress', 'transfer_pending' => 'Transfer in progress',
            'registration_rejected' => 'Registration declined', 'transfer_rejected' => 'Transfer declined',
            'expired' => 'Expired', 'grace' => 'Expired, in grace period', 'redemption' => 'In redemption', 'needs_review' => 'Needs review',
            default => self::readable($status, 'Unknown'),
        };
    }

    public static function funding(?string $basis): string
    {
        return match ($basis) {
            'paid_invoice' => 'Customer invoice',
            'non_billable_management' => 'No charge, management change',
            default => self::readable($basis, 'Not recorded'),
        };
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) { return CarbonImmutable::instance($value); }
        if (!is_string($value) || $value === '') { return null; }
        try { return CarbonImmutable::parse($value); } catch (Throwable) { return null; }
    }

    private static function readable(?string $code, string $fallback): string
    {
        if (!is_string($code) || $code === '') { return $fallback; }
        return ucfirst(str_replace('_', ' ', $code));
    }
}

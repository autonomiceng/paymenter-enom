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
    private const OUTCOMES = ['renewal_cancelled_unpaid' => 'cancelled_unpaid', 'initial_cancelled_unpaid' => 'cancelled_unpaid', 'transfer_code_timeout' => 'code_timeout', 'epp_sync_acknowledged' => 'acknowledged', 'contact_details_required' => 'needs_details'];

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
    public static function isPastDay(mixed $value): bool
    {
        $date = self::parse($value);
        return $date !== null && $date->format('Y-m-d') < CarbonImmutable::now()->utc()->format('Y-m-d');
    }

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
    public static function invoiceStatus(?string $status): string
    {
        return match ($status) {
            'pending' => 'Awaiting payment', 'paid' => 'Paid', 'cancelled' => 'Cancelled',
            default => self::readable($status, 'Unknown'),
        };
    }

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

    public static function billingResult(?string $code): string
    {
        return match ($code) {
            'not_due' => 'Not due yet', 'invoiced' => 'Invoice issued', 'sponsored' => 'Sponsored renewal requested',
            'sponsorship_review' => 'Sponsorship needs review. No approval covers this cycle, or no complete quote was within the limit.',
            'review' => 'Needs review', 'grace_review' => 'Expired. Unpaid renewal invoices were retired for review.',
            'cancelled' => 'Service cancelled after expiry', 'cancellation_pending' => 'Cancellation waits for the expiry date',
            'cancellation_review' => 'Cancellation needs review', 'invoice_deadline_missed' => 'Invoice deadline missed',
            'configuration_review' => 'Renewal settings need review', 'disabled' => 'Renewal billing is off on the server',
            'staff_review' => 'Paused for staff review', 'ineligible' => 'Not in managed billing',
            default => self::readable($code, 'No run recorded'),
        };
    }

    public static function observationStatus(?string $status): string
    {
        return match ($status) {
            'fresh' => 'Current, checked in the last 24 hours', 'stale' => 'Out of date', 'unknown' => 'Not checked yet',
            default => self::readable($status, 'Not checked yet'),
        };
    }

    public static function quoteState(?string $state): string
    {
        return match ($state) {
            'quoted' => 'Complete quote', 'advisory' => 'Advisory price only', 'confirmed' => 'Confirmed',
            'validated' => 'Validated', 'unconfirmed' => 'Not confirmed',
            default => self::readable($state, 'Not confirmed'),
        };
    }

    public static function costReason(?string $code): string
    {
        return match ($code) {
            'renewal_item_contract_unaccepted' => 'This renewal type has no accepted charge format, so the charge stays unconfirmed.',
            'cost_poll_limit_reached' => 'Charge checks stopped after the limit. The charge stays unconfirmed.',
            default => self::readable($code, ''),
        };
    }

    public static function costHealth(?string $state): string
    {
        return match ($state) {
            'actual_cost_exceeded' => 'A confirmed charge was above the approved limit',
            default => self::readable($state, 'No cost review recorded'),
        };
    }

    public static function eligibilityStep(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'Current registrar read configuration requires review.' => 'Registrar reads are not available. On the Enom server, turn on Sandbox only and Allow registrar reads, and save the login ID and API token through Edit server.',
            'The latest authenticated registrar observation requires refresh or review.',
            'Refresh authenticated identity, expiry and registrar auto-renew observations before enrollment.' => 'Use Check registrar now. Enrollment needs a complete registrar check from the last 24 hours.',
            'Resolve outstanding registrar or payment requests before enabling this policy.' => 'Open requests block this. Review them on Enom administration first.',
            'Provider identity, confirmed expiry and disabled registrar auto-renew must match.' => 'The registrar data does not match the record, or auto-renew is on at Enom. Check the registrar data above.',
            'Current authenticated registrar account binding requires review.' => 'The saved Enom account does not match the account that last checked this domain. Review the server credentials.',
            'A confirmed active one-year service and future registrar expiry are required.' => 'The native service must be active and yearly, with no cancellation, and the domain must be active with a future expiry.',
            default => $reason,
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

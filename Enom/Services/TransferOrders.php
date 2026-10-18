<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Updated;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Throwable;

class TransferOrders
{
    private static bool $booted = false;
    private const OPEN = ['awaiting_payment', 'awaiting_code', 'queued', 'held'];

    public function __construct(private ?Rdap $rdap = null) {}

    public static function boot(): void
    {
        if (self::$booted) { return; }
        self::$booted = true;
        // Transfer codes are byte-sensitive. Never let the global middleware trim them.
        TrimStrings::skipWhen(fn (Request $request) => $request->isMethod('POST')
            && preg_match('/\Adomains\/[0-9]+\/transfer-code\z/', $request->path()) === 1);
        require __DIR__.'/../routes/transfers.php';
        // Updated runs at the native paid transition, before delayed workers or replayed Paid events.
        Event::listen(Updated::class, function (Updated $event) {
            if (!$event->invoice->isDirty('status') || $event->invoice->status !== Invoice::STATUS_PAID
                || $event->invoice->getRawOriginal('status') === Invoice::STATUS_PAID) { return; }
            $at = CarbonImmutable::now('UTC');
            foreach (Operation::where('invoice_id', $event->invoice->id)->where('action', 'transfer')->get() as $operation) {
                app(self::class)->recordPayment($operation, $at);
            }
        });
        Schedule::call(fn () => app(self::class)->expireMissingCodes())->name('enom-transfer-code-expiry')->daily()->withoutOverlapping();
    }

    public static function config(): array
    {
        return [
            ['name' => 'transfer_allowlist', 'label' => 'Transfer endings', 'type' => 'text', 'default' => 'com,net,org',
                'validation' => ['nullable', 'string', 'max:2000', 'regex:/\A\s*\.?[a-z]+(?:\.[a-z]+)*(?:\s*,\s*\.?[a-z]+(?:\.[a-z]+)*)*\s*\z/i'],
                'description' => 'Comma-separated endings. Only enable an ending after a test transfer adds one year using a standard transfer code. Unsupported endings remain unavailable.'],
            ['name' => 'transfer_creation_lock_days', 'label' => 'Days after registration before a transfer', 'type' => 'number',
                'default' => 60, 'database_type' => 'integer', 'validation' => 'required|integer|min:0|max:365'],
            ['name' => 'transfer_previous_lock_days', 'label' => 'Days after a previous transfer', 'type' => 'number',
                'default' => 60, 'database_type' => 'integer', 'validation' => 'required|integer|min:0|max:365'],
        ];
    }

    public function allowed(Tld $tld): bool
    {
        if (!self::supported($tld)) { return false; }
        $ending = strtolower(ltrim((string) $tld->tld, '.'));
        $value = RuntimeGuard::setting((int) $tld->server_id, 'transfer_allowlist', 'com,net,org');
        if (!is_string($value)) { return false; }
        $endings = array_map(fn ($value) => strtolower(ltrim(trim($value), '.')), explode(',', $value));
        return in_array($ending, $endings, true);
    }

    /** Ending support is immutable code policy; the allowlist controls new sales only. */
    public static function supported(Tld $tld): bool
    {
        $ending = strtolower(ltrim((string) $tld->tld, '.'));
        return preg_match('/\A[a-z]+(?:\.[a-z]+)*\z/D', $ending) === 1
            && !in_array(substr($ending, (int) strrpos('.'.$ending, '.')), ['uk', 'ca', 'eu', 'ai', 'de'], true);
    }

    public function precheck(Tld $tld, string $domain): array
    {
        return $this->eligibility($tld, $domain, true);
    }

    private function eligibility(Tld $tld, string $domain, bool $admission): array
    {
        if (!self::supported($tld) || $admission && !$this->allowed($tld)) { return ['allowed' => false, 'code' => 'transfer_ending_unsupported', 'warning' => null, 'expires_at' => null]; }
        $suffix = '.'.strtolower(ltrim((string) $tld->tld, '.'));
        $label = str_ends_with($domain, $suffix) ? substr($domain, 0, -strlen($suffix)) : '';
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])\z/D', $label) || str_starts_with($label, 'xn--')) {
            return ['allowed' => false, 'code' => 'transfer_name_invalid', 'warning' => null, 'expires_at' => null];
        }
        $days = [];
        foreach (['transfer_creation_lock_days', 'transfer_previous_lock_days'] as $key) {
            $value = RuntimeGuard::setting((int) $tld->server_id, $key, 60);
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 365) {
                throw new InputValidationException(['domain' => 'Transfer rules need attention. Please contact support.']);
            }
            $days[] = (int) $value;
        }
        return ($this->rdap ?? app(Rdap::class))->precheck($domain, $days[0], $days[1], CarbonImmutable::now('UTC')->toDateTimeImmutable());
    }

    public function needsCode(Operation $operation): bool
    {
        return $operation->action === 'transfer' && !self::validCode($operation->payload['epp'] ?? null);
    }

    private static function validCode(mixed $code): bool
    {
        return is_string($code) && strlen($code) >= 1 && strlen($code) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $code);
    }

    public function paymentAccepted(Operation $operation): void { $this->recordPayment($operation); }

    private function recordPayment(Operation $operation, ?CarbonImmutable $transition = null): void
    {
        if ($operation->action !== 'transfer') { return; }
        $this->locked($operation, function (Operation $current, Domain $domain) use ($transition) {
            if (!in_array($current->state, self::OPEN, true) || !self::unsent($current)
                || $current->funding_basis !== 'paid_invoice' || !app(DomainOperations::class)->funded($current)) { return; }
            $policy = $current->policy ?? [];
            if (!isset($policy['paid_at'])) {
                $at = $transition ?? $this->recoverPaidAt($current);
                if (!$at) {
                    if (!isset($policy['payment_time_alerted_at'])) {
                        foreach (User::with('role')->get() as $staff) {
                            if ($staff->hasPermission('admin.enom.manage')) {
                                Notification::create(['user_id' => $staff->id, 'title' => 'Transfer payment needs review',
                                    'body' => 'The payment date for '.$domain->domain.' could not be verified. The transfer is on hold.',
                                    'url' => null, 'show_in_app' => true, 'show_as_push' => false]);
                            }
                        }
                        $policy['payment_time_alerted_at'] = now()->toIso8601String();
                    }
                    $current->update(['policy' => $policy, 'state' => 'held', 'safe_code' => 'transfer_payment_time_unknown']);
                    return;
                }
                $policy['paid_at'] = $at->utc()->toIso8601String();
            }
            $changes = ['policy' => $policy];
            if ($this->needsCode($current) && in_array($current->state, ['awaiting_payment', 'awaiting_code', 'queued'], true)) {
                $changes += ['state' => 'awaiting_code', 'safe_code' => 'transfer_code_required'];
            }
            $current->update($changes);
        });
        app(TransferNotifications::class)->sendDue($operation);
    }

    /** Earliest native paid audit, or unchanged successful payment records that first cover the invoice. */
    private function recoverPaidAt(Operation $operation): ?CarbonImmutable
    {
        $invoice = Invoice::with('transactions', 'items')->find($operation->invoice_id);
        if (!$invoice) { return null; }
        foreach (DB::table('audits')->where('auditable_type', Invoice::class)->where('auditable_id', $invoice->id)->orderBy('id')->get() as $audit) {
            $old = json_decode($audit->old_values ?? '{}', true);
            $new = json_decode($audit->new_values ?? '{}', true);
            if (($new['status'] ?? null) === Invoice::STATUS_PAID && ($old['status'] ?? null) !== Invoice::STATUS_PAID) {
                $at = CarbonImmutable::parse($audit->created_at, 'UTC');
                return $at <= CarbonImmutable::now('UTC') && $at >= $operation->created_at ? $at : null;
            }
        }
        $net = 0;
        foreach ($invoice->transactions->sortBy(fn ($row) => $row->created_at->format('Y-m-d H:i:s.u').sprintf('%020d', $row->id)) as $transaction) {
            if ($transaction->status !== InvoiceTransactionStatus::Succeeded) { continue; }
            // An edited transaction cannot establish when it first became successful without an audit.
            if (!$transaction->created_at || !$transaction->updated_at || !$transaction->created_at->equalTo($transaction->updated_at)) { return null; }
            $net += (int) round((float) $transaction->amount * 100);
            if ($net >= (int) round((float) $invoice->total * 100)) {
                $at = CarbonImmutable::instance($transaction->created_at)->utc();
                return $at <= CarbonImmutable::now('UTC') && $at >= $operation->created_at ? $at : null;
            }
        }
        return null;
    }

    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        $value = $operation->policy['paid_at'] ?? null;
        if (!is_string($value) || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|\+00:00)\z/D', $value)) { return null; }
        try {
            $paid = CarbonImmutable::parse($value, 'UTC');
            if ($paid->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) { return null; }
            return $paid > CarbonImmutable::now('UTC') ? null : $paid->addDays(30);
        } catch (Throwable) { return null; }
    }

    /** A claimed request is unsent until the dispatch seal or provider evidence exists. */
    public static function unsent(Operation $operation): bool
    {
        return !array_key_exists('dispatch_attempted_at', $operation->policy ?? [])
            && !array_key_exists('execution', $operation->cost ?? []) && $operation->provider_order_id === null;
    }

    public function assertReady(Operation $operation): void
    {
        if ($operation->action !== 'transfer') { return; }
        $deadline = self::deadline($operation);
        if ($operation->funding_basis === 'paid_invoice' && !$deadline) {
            throw new InputValidationException(['transfer_payment_time_unknown' => 'The transfer payment needs review. Please contact support.']);
        }
        if ($this->needsCode($operation)) {
            $key = $deadline && $deadline <= CarbonImmutable::now('UTC') ? 'transfer_code_timeout' : 'transfer_code_required';
            throw new InputValidationException([$key => 'Enter your transfer code on the domain page.']);
        }
        $domain = $operation->domain;
        $tld = $domain ? Tld::where('server_id', $domain->server_id)->where('tld', $domain->tld)->first() : null;
        if (!$tld) { throw new InputValidationException(['transfer_ending_unsupported' => 'This domain cannot be transferred here.']); }
        $result = $this->eligibility($tld, $domain->domain, false);
        if (!$result['allowed']) {
            throw new InputValidationException([$result['code'] => self::reason($result['code'])]);
        }
    }

    public static function reason(?string $code): string
    {
        return match ($code) {
            'transfer_locked' => 'This domain is locked at its current registrar. Turn off the transfer lock there, then try again.',
            'transfer_redemption' => 'This domain has expired at its current registrar. Renew or restore it there first.',
            'transfer_pending_delete' => 'This domain is about to be deleted by its registry and cannot be transferred.',
            'transfer_creation_lock' => 'This domain was registered too recently. Try again after the registration lock ends.',
            'transfer_previous_lock' => 'This domain moved registrars too recently. Try again after the transfer lock ends.',
            'transfer_maximum_term' => 'This domain is already registered for the maximum term. A transfer would pass the 10-year limit.',
            default => 'This domain cannot be transferred right now. Check with its current registrar.',
        };
    }

    /** Shared code-entry gate for the owner form and submission; payment recovery runs before checking the cutoff. */
    public static function codeRejection(Operation $operation, bool $checkDeadline = true): ?string
    {
        $domain = $operation->domain;
        $invoiceStatus = Invoice::whereKey($operation->invoice_id)->value('status');
        if ($operation->action !== 'transfer' || !in_array($operation->state, self::OPEN, true) || !self::unsent($operation)
            || !$domain || (int) $domain->service_id !== (int) $operation->service_id
            || $domain->status !== 'transfer_pending' || $domain->provider_order_id !== null
            || !in_array($domain->service?->status, ['pending', 'active'], true)
            || !in_array($invoiceStatus, [Invoice::STATUS_PENDING, Invoice::STATUS_PAID], true)) {
            return 'This transfer can no longer accept a code.';
        }
        if ($checkDeadline && $invoiceStatus === Invoice::STATUS_PAID) {
            $deadline = self::deadline($operation);
            if (!$deadline) { return 'The transfer payment needs review. Please contact support.'; }
            if ($deadline <= CarbonImmutable::now('UTC')) { return 'The transfer code deadline has passed.'; }
        }
        return null;
    }

    public function submitCode(User $actor, int $domainId, string $code): Operation
    {
        $domain = Domain::whereKey($domainId)->where('user_id', $actor->id)->firstOrFail();
        if (!self::validCode($code)) { throw new InputValidationException(['transfer_code' => 'Enter a transfer code of 1 to 512 bytes on one line.']); }
        $operation = Operation::where('domain_id', $domain->id)->where('action', 'transfer')->latest('id')->firstOrFail();
        $result = $this->locked($operation, function (Operation $current, Domain $domain) use ($actor, $code) {
            abort_unless((int) $domain->user_id === (int) $actor->id && (int) $domain->service_id === (int) $current->service_id, 404);
            $current->setRelation('domain', $domain);
            if ($error = self::codeRejection($current, false)) {
                throw new InputValidationException(['transfer_code' => $error]);
            }
            if (Invoice::whereKey($current->invoice_id)->value('status') === Invoice::STATUS_PAID) {
                $this->paymentAccepted($current);
                $current->refresh();
            }
            if ($error = self::codeRejection($current)) { return ['error' => $error]; }
            $current->update(['payload' => array_replace($current->payload ?? [], ['epp' => $code])]);
            DB::afterCommit(fn () => app(DomainOperations::class)->resumeInitial((int) $current->id));
            return ['operation' => $current];
        });
        if (isset($result['error'])) { throw new InputValidationException(['transfer_code' => $result['error']]); }
        return $result['operation']->fresh();
    }

    public function expireMissingCodes(): int
    {
        $done = 0;
        Operation::where('action', 'transfer')->where('funding_basis', 'paid_invoice')->whereIn('state', self::OPEN)
            ->orderBy('id')->chunkById(100, function ($operations) use (&$done) {
                foreach ($operations as $operation) {
                    try {
                        $this->paymentAccepted($operation);
                        $done += app(Refunds::class)->rejectUnsentTransfer((int) $operation->id) ? 1 : 0;
                    } catch (Throwable) { /* The next sweep retries without changing the payment deadline. */ }
                }
            });
        return $done;
    }

    private function locked(Operation $operation, callable $callback): mixed
    {
        $ownerId = Domain::whereKey($operation->domain_id)->value('user_id');
        return DB::transaction(function () use ($operation, $ownerId, $callback) {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            return $callback($current, $domain);
        });
    }
}

<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use RuntimeException;
use Throwable;

/**
 * Paid requests that Enom definitely did not carry out are refunded to the client's account
 * credit, once. Uncertain or pending requests are never refunded here; staff resolve them first.
 */
class Refunds
{
    public const REFUNDABLE = ['register', 'transfer', 'renew', 'privacy_purchase', 'privacy_renew'];

    /** @return array{amount: string, currency: string}|null what was credited, or null if nothing was due */
    public function creditRejected(Operation $operation): ?array
    {
        // Identifiers first, then locks in the shared order (user, domain, operation), then evidence.
        $ownerId = Domain::whereKey($operation->domain_id)->value('user_id');
        $credited = DB::transaction(function () use ($operation, $ownerId) {
            User::whereKey($ownerId)->lockForUpdate()->first();
            Domain::whereKey($operation->domain_id)->lockForUpdate()->first();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'rejected' || $current->funding_basis !== 'paid_invoice'
                || !in_array($current->action, self::REFUNDABLE, true) || isset($current->cost['refund'])) { return null; }
            $invoice = Invoice::with('items', 'transactions')->find($current->invoice_id);
            $item = $invoice?->items->firstWhere('id', $current->invoice_item_id);
            $amount = RegistrarMoney::decimal((string) $current->amount);
            // Only money actually received for this exact line comes back: one unit, same currency,
            // and successful positive payments covering the whole invoice.
            $received = 0;
            foreach ($invoice?->transactions ?? [] as $transaction) {
                if ($transaction->status === \App\Enums\InvoiceTransactionStatus::Succeeded && self::cents($transaction->amount) > 0) { $received += self::cents($transaction->amount); }
            }
            if (!$invoice || !$item || (int) $invoice->user_id !== (int) $ownerId || $invoice->status !== Invoice::STATUS_PAID
                || $invoice->currency_code !== $current->currency || (int) $item->quantity !== 1
                || self::cents($invoice->remaining) !== 0 || $amount === null || self::cents($amount) <= 0
                || self::cents($item->price) !== self::cents($amount)
                || $received < self::cents($invoice->total) || $received < self::cents($amount)) { return null; }
            if (in_array($current->action, ['register', 'transfer', 'renew'], true) && $current->action !== 'renew'
                && ($item->reference_type !== Service::class || (int) $item->reference_id !== (int) $current->service_id)) { return null; }
            $credit = Credit::where('user_id', $ownerId)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first()
                ?? new Credit(['user_id' => $ownerId, 'currency_code' => $invoice->currency_code, 'amount' => '0.00']);
            $total = self::cents($credit->amount) + self::cents($amount);
            $credit->amount = intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
            $credit->save();
            $refund = ['amount' => $amount, 'currency' => $invoice->currency_code, 'at' => now()->toIso8601String(), 'to' => 'account_credit'];
            $current->update(['cost' => array_replace($current->cost ?? [], ['refund' => $refund])]);
            // A registration or transfer that never happened leaves no service to bill.
            if (in_array($current->action, ['register', 'transfer'], true)
                && Domain::whereKey($current->domain_id)->where('service_id', $current->service_id)->where('user_id', $ownerId)->exists()) {
                Service::whereKey($current->service_id)->where('user_id', $ownerId)->whereIn('status', [Service::STATUS_PENDING, Service::STATUS_ACTIVE])
                    ->update(['status' => 'cancelled']);
            }
            return $refund + ['user_id' => (int) $ownerId, 'invoice' => $invoice, 'operation' => $current];
        });
        if (!$credited) { return null; }
        try {
            $domain = Domain::find($operation->domain_id);
            NotificationHelper::sendNotification('enom_domain_order_refunded', [
                'domain' => $domain?->domain, 'action' => self::actionLabel($credited['operation']->action),
                'amount' => $credited['amount'], 'currency' => $credited['currency'], 'invoice' => $credited['invoice'],
                'domain_url' => $domain ? route('enom.domains.show', $domain->id) : url('/domains'),
            ], User::findOrFail($credited['user_id']));
        } catch (Throwable) {} // The credit stands even if the notification can't be sent.
        return ['amount' => $credited['amount'], 'currency' => $credited['currency']];
    }

    /**
     * Retry refunds that didn't complete (a failed credit write, a crash between rejection and credit).
     * creditRejected() re-checks everything and is idempotent.
     */
    public function retryPending(): int
    {
        $done = 0;
        Operation::query()->where('state', 'rejected')->where('funding_basis', 'paid_invoice')->whereIn('action', self::REFUNDABLE)
            ->where('completed_at', '>=', now()->subDays(30))->orderBy('id')->chunkById(100, function ($operations) use (&$done) {
                foreach ($operations as $operation) {
                    if (isset($operation->cost['refund'])) { continue; }
                    try { $done += $this->creditRejected($operation) ? 1 : 0; } catch (Throwable) {}
                }
            });
        return $done;
    }

    /** Whether staff may close this request and refund it: paid, held, and proven never sent. */
    public function canRefundHeld(Operation $operation): bool
    {
        return $operation->state === 'held' && $operation->funding_basis === 'paid_invoice'
            && in_array($operation->action, self::REFUNDABLE, true) && !isset($operation->cost['refund'])
            && !array_key_exists('dispatch_attempted_at', $operation->policy ?? []) && !isset($operation->cost['execution'])
            && $operation->provider_order_id === null
            // Managed requests carry a dispatch marker, so a claim without it is provably unsent. Older rows must never have started.
            && (($operation->policy['dispatch_marker_version'] ?? null) === 1 || $operation->started_at === null)
            && Invoice::whereKey($operation->invoice_id)->value('status') === Invoice::STATUS_PAID;
    }

    public function refundHeld(User $staff, int $operationId, string $reason): array
    {
        if (!$staff->hasPermission('admin.enom.manage') || !$staff->hasPermission('admin.invoices.update')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to refund this.');
        }
        $reason = trim(preg_replace('/\s+/', ' ', $reason));
        if (strlen($reason) < 5 || strlen($reason) > 500) { throw new InputValidationException(['reason' => 'Give a short reason (5 to 500 characters).']); }
        $domainId = Operation::whereKey($operationId)->value('domain_id');
        $ownerId = Domain::whereKey($domainId)->value('user_id');
        $operation = DB::transaction(function () use ($operationId, $staff, $reason, $domainId, $ownerId) {
            User::whereKey($ownerId)->lockForUpdate()->first();
            Domain::whereKey($domainId)->lockForUpdate()->first();
            $current = Operation::whereKey($operationId)->lockForUpdate()->firstOrFail();
            if (!$this->canRefundHeld($current)) { throw new RuntimeException('Only a paid request that was never sent to Enom can be refunded here.'); }
            $current->update(['state' => 'rejected', 'safe_code' => 'refunded_by_staff', 'completed_at' => now(),
                'funding_reason' => mb_substr('Refunded by '.$staff->name.': '.$reason, 0, 500)]);
            if (in_array($current->action, ['register', 'transfer'], true)) {
                Domain::whereKey($current->domain_id)->whereIn('status', ['registration_pending', 'transfer_pending'])->whereNull('provider_order_id')
                    ->update(['status' => $current->action === 'register' ? 'registration_rejected' : 'transfer_rejected']);
            }
            return $current;
        });
        return $this->creditRejected($operation) ?? throw new RuntimeException('The request was closed, but no paid amount was found to refund.');
    }

    private static function cents(mixed $amount): int { return (int) round(((float) $amount) * 100); }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'register' => 'registration', 'transfer' => 'transfer', 'renew' => 'renewal',
            'privacy_purchase' => 'ID Protect purchase', 'privacy_renew' => 'ID Protect renewal', default => 'order',
        };
    }
}

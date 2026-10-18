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
            $payment = $this->refundablePayment($current, $ownerId);
            if (!$payment) { return null; }
            ['invoice' => $invoice, 'amount' => $amount] = $payment;
            $credit = Credit::where('user_id', $ownerId)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first()
                ?? new Credit(['user_id' => $ownerId, 'currency_code' => $invoice->currency_code, 'amount' => '0.00']);
            $total = self::cents($credit->amount) + self::cents($amount);
            $credit->amount = PricingRule::decimal($total);
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
            && !array_key_exists('dispatch_attempted_at', $operation->policy ?? []) && !array_key_exists('execution', $operation->cost ?? [])
            && $operation->provider_order_id === null
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
            if (!$this->refundablePayment($current, $ownerId)) { throw new RuntimeException('No eligible paid amount was found. The request is still held.'); }
            $current->update(['state' => 'rejected', 'safe_code' => 'refunded_by_staff', 'payload' => null, 'completed_at' => now(),
                'funding_reason' => mb_substr('Refunded by '.$staff->name.': '.$reason, 0, 500)]);
            if (in_array($current->action, ['register', 'transfer'], true)) {
                Domain::whereKey($current->domain_id)->whereIn('status', ['registration_pending', 'transfer_pending'])->whereNull('provider_order_id')
                    ->update(['status' => $current->action === 'register' ? 'registration_rejected' : 'transfer_rejected']);
            }
            return $current;
        });
        return $this->creditRejected($operation) ?? throw new RuntimeException('The request was closed, but no paid amount was found to refund.');
    }

    /** Reject only a paid transfer that still has no code at its deadline and was never sent. */
    public function rejectUnsentTransfer(int $operationId): ?array
    {
        $operation = Operation::findOrFail($operationId);
        $ownerId = Domain::whereKey($operation->domain_id)->value('user_id');
        $rejected = DB::transaction(function () use ($operation, $ownerId) {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $deadline = TransferOrders::deadline($current);
            if ($current->action !== 'transfer' || $current->funding_basis !== 'paid_invoice'
                || !in_array($current->state, ['awaiting_payment', 'awaiting_code', 'held', 'queued'], true)
                || !TransferOrders::unsent($current) || !$deadline || $deadline->isFuture()
                || !app(TransferOrders::class)->needsCode($current) || isset($current->cost['refund'])
                || $domain->status !== 'transfer_pending' || $domain->provider_order_id !== null
                || !$this->refundablePayment($current, $ownerId)) { return null; }
            $current->update(['state' => 'rejected', 'safe_code' => 'transfer_code_timeout', 'payload' => null,
                'completed_at' => now(), 'lease_expires_at' => null]);
            $domain->update(['status' => 'transfer_rejected']);
            Service::whereKey($current->service_id)->where('user_id', $ownerId)
                ->whereIn('status', [Service::STATUS_PENDING, Service::STATUS_ACTIVE])->update(['status' => 'cancelled']);
            return $current;
        });
        // Keep the rejection if writing credit fails. retryPending completes the same ledger entry.
        return $rejected ? $this->creditRejected($rejected) : null;
    }

    private function refundablePayment(Operation $current, mixed $ownerId): ?array
    {
        $invoice = Invoice::with('items', 'transactions')->find($current->invoice_id);
        $item = $invoice?->items->firstWhere('id', $current->invoice_item_id);
        $amount = RegistrarMoney::decimal((string) $current->amount);
        // Only money actually received for this exact line comes back: one unit, same currency,
        // and successful net payments covering the whole invoice.
        $received = 0;
        foreach ($invoice?->transactions ?? [] as $transaction) {
            if ($transaction->status === \App\Enums\InvoiceTransactionStatus::Succeeded) { $received += self::cents($transaction->amount); }
        }
        if (!$invoice || !$item || (int) $invoice->user_id !== (int) $ownerId || $invoice->status !== Invoice::STATUS_PAID
            || $invoice->currency_code !== $current->currency || (int) $item->quantity !== 1
            || self::cents($invoice->remaining) > 0 || $amount === null || self::cents($amount) <= 0
            || self::cents($item->price) !== self::cents($amount)
            || $received < self::cents($invoice->total)) { return null; }
        // The line must belong to this request: registrations/transfers reference their service, managed
        // renewals and ID Protect lines reference nothing; the domain must still own that service.
        $serviceLine = in_array($current->action, ['register', 'transfer'], true);
        if (($serviceLine ? ($item->reference_type !== Service::class || (int) $item->reference_id !== (int) $current->service_id)
                : ($item->reference_type !== null || $item->reference_id !== null))
            || !Domain::whereKey($current->domain_id)->where('user_id', $ownerId)->where('service_id', $current->service_id)->exists()
            || !Service::whereKey($current->service_id)->where('user_id', $ownerId)->where('currency_code', $current->currency)->where('quantity', 1)->exists()) { return null; }
        return ['invoice' => $invoice, 'amount' => $amount];
    }

    private static function cents(mixed $amount): int { return PricingRule::minor($amount); }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'register' => 'registration', 'transfer' => 'transfer', 'renew' => 'renewal',
            'privacy_purchase' => 'ID Protect purchase', 'privacy_renew' => 'ID Protect renewal', default => 'order',
        };
    }
}

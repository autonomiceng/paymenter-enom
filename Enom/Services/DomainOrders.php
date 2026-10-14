<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use RuntimeException;

class DomainOrders
{
    public function __construct(private DomainOperations $operations) {}

    public function quote(User $user, string $domain, string $action = 'register', int $term = 1): array
    {
        if (!in_array($action, ['register', 'transfer'], true) || $term !== 1) { throw new RuntimeException('Only one-year registration and transfer are available.'); }
        $parsed = DomainName::parse($domain, ['com']);
        $product = Product::whereHas('server', fn ($q) => $q->where('extension', 'Enom'))->get()
            ->first(fn ($product) => $product->settings->firstWhere('key', 'enom_tld')?->value === 'com');
        if (!$product) { throw new RuntimeException('Domain catalogue is unavailable.'); }
        $plan = $product->plans->first(fn ($plan) => $plan->type === 'recurring' && $plan->billing_unit === 'year' && (int) $plan->billing_period === 1);
        if (!$plan || !$product->hidden || $product->upgrades()->exists() || $product->configOptions()->exists()) { throw new RuntimeException('Domain catalogue configuration requires review.'); }
        $currency = 'USD';
        $nativePrice = $plan->prices->firstWhere('currency_code', $currency);
        if (!$nativePrice || (float) $nativePrice->price <= 0 || (float) $nativePrice->setup_fee !== 0.0) { throw new RuntimeException('Domain renewal pricing is unavailable.'); }
        $settings = $product->settings->pluck('value', 'key');
        $base = $settings->get('enom_'.$action.'_price');
        if (!is_numeric($base) || (float) $base <= 0) { throw new RuntimeException('Domain action pricing is unavailable.'); }
        $renewalAmount = $this->taxed($user, (string) $nativePrice->price, $currency);
        $amount = $this->taxed($user, (string) $base, $currency);
        $api = $this->operations->apiForServer((int) $product->server_id);
        $result = $api->search($parsed['domain']);
        $check = EnomApi::normalize('search', $result);
        if ($result->status !== 'success' || ($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false
            || ($action === 'register' && empty($check['available']))
            || ($action === 'transfer' && (!empty($check['available']) || empty($check['transferable'])))) {
            throw new RuntimeException('This domain is unavailable or requires unsupported registry pricing.');
        }
        if (Domain::where('domain', $parsed['domain'])->exists()) { throw new RuntimeException('This domain already has a portal record.'); }
        $intent = OrderIntent::create([
            'id' => bin2hex(random_bytes(24)), 'user_id' => $user->id, 'server_id' => $product->server_id,
            'product_id' => $product->id, 'plan_id' => $plan->id, ...$parsed,
            'action' => $action, 'term' => 1, 'amount' => $amount, 'renewal_amount' => $renewalAmount,
            'currency' => $currency, 'expires_at' => now()->addMinutes(15),
            'payload' => ['quote_check' => ['available' => $check['available'] ?? false, 'transferable' => $check['transferable'] ?? false]],
        ]);
        return $this->quoteData($intent);
    }

    private function quoteData(OrderIntent $intent): array
    {
        return ['intent_id' => $intent->id, 'domain' => $intent->domain, 'action' => $intent->action,
            'term' => 1, 'amount' => $intent->amount, 'currency' => $intent->currency,
            'renewal_amount' => $intent->renewal_amount, 'expires_at' => $intent->expires_at->toIso8601String()];
    }

    /** Reopen an existing quote without another availability request or order write. */
    public function pendingQuote(User $user, string $intentId): array
    {
        $intent = OrderIntent::where('user_id', $user->id)->whereKey($intentId)->firstOrFail();
        if ($intent->claimed_at || $intent->expires_at->isPast()) {
            throw new RuntimeException('Domain quote expired or was already used.');
        }

        return $this->quoteData($intent);
    }

    public function place(User $user, string $intentId, array $contacts = [], ?string $epp = null): Invoice
    {
        return DB::transaction(function () use ($user, $intentId, $contacts, $epp) {
            $intent = OrderIntent::where('user_id', $user->id)->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($intent->claimed_at) { return Invoice::where('user_id', $user->id)->findOrFail($intent->invoice_id); }
            if ($intent->expires_at->isPast()) { throw new RuntimeException('Domain quote expired. Search again.'); }
            $this->operations->apiForServer((int) $intent->server_id); // Guard, no request.
            $product = Product::whereKey($intent->product_id)->lockForUpdate()->firstOrFail();
            $plan = $product->plans()->findOrFail($intent->plan_id);
            if ($product->server_id != $intent->server_id || $product->server?->extension !== 'Enom' || !$product->hidden
                || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1) { throw new RuntimeException('Domain quote configuration changed.'); }
            $renewal = $plan->prices()->where('currency_code', $intent->currency)->firstOrFail();
            $base = $product->settings()->where('key', 'enom_'.$intent->action.'_price')->first()?->value;
            if (!$base || $this->taxed($user, $base, $intent->currency) !== $intent->amount
                || $this->taxed($user, $renewal->price, $intent->currency) !== $intent->renewal_amount) { throw new RuntimeException('Domain pricing changed. Search again.'); }
            if ($intent->action === 'transfer' && (!is_string($epp) || strlen($epp) < 1 || strlen($epp) > 255 || preg_match('/[\x00-\x1f]/', $epp))) { throw new RuntimeException('A valid transfer authorization code is required.'); }
            if (!$contacts) { throw new RuntimeException('Registrant contact details are required.'); }
            foreach ($contacts as $type => $contact) {
                if (!is_array($contact)) { throw new RuntimeException('Registrant contact details are invalid.'); }
                DomainManager::validatePayload('contacts', ['confirmed' => true, 'contact_type' => $type, 'contact' => $contact]);
                if (empty($contact['postal_code'])) { throw new RuntimeException('Registrant postal code is required.'); }
            }
            $intent->payload = ['contacts' => $contacts, 'epp' => $intent->action === 'transfer' ? $epp : null];
            $intent->save();
            $order = new Order(['user_id' => $user->id, 'currency_code' => $intent->currency]);
            $order->send_create_email = false;
            $order->save();
            $service = $order->services()->create(['user_id' => $user->id, 'currency_code' => $intent->currency,
                'product_id' => $intent->product_id, 'plan_id' => $intent->plan_id, 'price' => $intent->renewal_amount,
                'quantity' => 1, 'coupon_id' => null, 'status' => Service::STATUS_PENDING]);
            $service->label = $intent->domain;
            $service->save();
            $intent->service_id = $service->id;
            $intent->save();
            $managed = filter_var($product->server->settings->firstWhere('key', 'managed_billing')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
            $configuration = $managed ? $this->operations->providerConfiguration((int) $intent->server_id) : [];
            $policy = $managed ? ['version' => 1, 'enabled' => true,
                'issue_days' => (int) ($product->server->settings->firstWhere('key', 'issue_days')?->value ?? 21),
                'due_days' => (int) ($product->server->settings->firstWhere('key', 'due_days')?->value ?? 7), 'term' => 1,
                'account_fingerprint' => hash('sha256', 'sandbox:'.strtolower($configuration['uid']))] : null;
            $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $intent->server_id,
                'domain' => $intent->domain, 'sld' => $intent->sld, 'tld' => $intent->tld,
                'billing_managed' => $managed, 'renewal_policy' => $policy,
                'status' => $intent->action === 'transfer' ? 'transfer_pending' : 'registration_pending', 'capabilities' => []]);
            if ($managed) { app(DomainBilling::class)->policy($domain); }
            $invoice = $this->newInvoice($user, $intent->currency);
            $item = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id,
                'quantity' => 1, 'price' => $intent->amount, 'description' => ucfirst($intent->action).' '.$intent->domain.' (1 year)']);
            $operation = $this->operations->bindInvoiceItem($item);
            if (!$operation || $operation->state !== 'awaiting_payment') { throw new RuntimeException('Domain payment binding could not be established.'); }
            $intent->update(['claimed_at' => now(), 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]);
            $service->properties()->create(['key' => 'enom_domain', 'value' => $intent->domain]);
            return $invoice;
        });
    }

    public function renew(User $user, int $domainId, ?string $nonce = null): Invoice
    {
        $domain = Domain::where('user_id', $user->id)->findOrFail($domainId);
        if ($domain->billing_managed) { return app(DomainBilling::class)->invoice($domainId, $user, $nonce); }
        return $this->billExisting($user, $domainId, 'renew', $nonce);
    }

    public function privacy(User $user, int $domainId, string $action, ?string $nonce = null): Invoice
    {
        $action = match ($action) { 'purchase', 'privacy_purchase' => 'privacy_purchase', 'renew', 'privacy_renew' => 'privacy_renew', default => throw new RuntimeException('Invalid privacy purchase.') };
        return $this->billExisting($user, $domainId, $action, $nonce);
    }

    private function billExisting(User $user, int $domainId, string $action, ?string $nonce): Invoice
    {
        if ($nonce !== null && !preg_match('/^[a-f0-9]{32,64}$/D', $nonce)) { throw new RuntimeException('Invalid purchase request.'); }
        return DB::transaction(function () use ($user, $domainId, $action, $nonce) {
            $domain = Domain::where('user_id', $user->id)->whereKey($domainId)->lockForUpdate()->firstOrFail();
            $service = $domain->service;
            if (!$service || !$this->operations->validService($service, $domain) || $domain->status !== 'active' || $service->status !== Service::STATUS_ACTIVE) { throw new RuntimeException('Domain billing is unavailable.'); }
            if (DomainOperations::writesPaused($domain)) { throw new RuntimeException('Domain purchases require staff review.'); }
            $this->operations->apiForServer((int) $domain->server_id);
            $key = $nonce ? 'billing:'.$domainId.':'.$action.':'.hash('sha256', $nonce) : null;
            if ($key && ($existing = Operation::where('operation_key', $key)->first())) {
                return Invoice::where('user_id', $user->id)->findOrFail($existing->invoice_id);
            }
            if ($existing = Operation::where('domain_id', $domainId)->where('action', $action)->whereIn('state', ['awaiting_payment', 'queued', 'running', 'pending', 'uncertain'])->first()) {
                return Invoice::where('user_id', $user->id)->findOrFail($existing->invoice_id);
            }
            if ($domain->operations()->whereIn('state', DomainOperations::UNRESOLVED_STATES)->exists()) {
                throw new \InvalidArgumentException('An existing registrar request must complete or be reviewed before a new invoice.');
            }
            if ($action === 'renew' && $service->cancellation()->exists()) { throw new RuntimeException('Renewal preference is disabled.'); }
            $amount = $service->price;
            if ($action !== 'renew') {
                if (($domain->capabilities[$action] ?? false) !== true) { throw new RuntimeException('Privacy purchase is unavailable for this domain.'); }
                $base = $service->product->settings()->where('key', 'enom_privacy_price')->first()?->value;
                if (!is_numeric($base) || (float) $base <= 0) { throw new RuntimeException('Privacy retail pricing is unavailable.'); }
                $amount = $this->taxed($user, $base, $service->currency_code);
            }
            $invoice = $this->newInvoice($user, $service->currency_code);
            $item = $invoice->items()->create(['reference_type' => $action === 'renew' ? Service::class : null,
                'reference_id' => $action === 'renew' ? $service->id : null, 'quantity' => 1, 'price' => $amount,
                'description' => ($action === 'renew' ? 'Renew ' : 'Domain privacy ').$domain->domain.' (1 year)']);
            if ($action === 'renew') {
                $operation = $this->operations->bindInvoiceItem($item);
                if (!$operation || $operation->state !== 'awaiting_payment') { throw new RuntimeException('Renewal binding could not be established.'); }
                if ($key) { $operation->update(['operation_key' => $key]); }
            } else {
                $privacyBase = null;
                if ($action === 'privacy_renew') {
                    $cachedExpiry = $domain->snapshot['privacy']['expires_at'] ?? null;
                    $expiryResult = new \Paymenter\Extensions\Servers\Enom\Api\RegistrarResult('success', null, ['getwppsinfo' => ['wppsexpdate' => $cachedExpiry]]);
                    $privacyBase = $expiryResult->confirmedPrivacyExpiry();
                    if (!$privacyBase) { throw new RuntimeException('Refresh privacy expiry before purchasing its renewal.'); }
                }
                Operation::create(['domain_id' => $domainId, 'service_id' => $service->id, 'invoice_id' => $invoice->id,
                    'invoice_item_id' => $item->id, 'operation_key' => $key ?? 'invoice-item:'.$item->id,
                    'action' => $action, 'term' => 1, 'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment',
                    'amount' => $amount, 'currency' => $service->currency_code, 'payload' => [],
                    'policy' => $domain->billing_managed ? ['dispatch_marker_version' => 1] : null,
                    'base_expires_at' => $privacyBase?->format('Y-m-d'),
                    'expected_expires_at' => $privacyBase ? \Carbon\CarbonImmutable::instance($privacyBase)->addYearNoOverflow()->format('Y-m-d') : now()->addYearNoOverflow()->format('Y-m-d')]);
            }
            return $invoice;
        });
    }

    private function newInvoice(User $user, string $currency): Invoice
    {
        $invoice = new Invoice(['user_id' => $user->id, 'currency_code' => $currency,
            'status' => Invoice::STATUS_PENDING, 'due_at' => now()->addDays(7)]);
        $invoice->send_create_email = false;
        $invoice->save();
        return $invoice;
    }

    private function taxed(User $user, $amount, string $currency): string
    {
        return number_format((float) (new Price(['price' => $amount, 'currency' => Currency::findOrFail($currency)],
            apply_exclusive_tax: true, tax: Settings::tax($user)))->price, 2, '.', '');
    }
}

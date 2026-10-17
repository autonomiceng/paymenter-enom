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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use RuntimeException;

class DomainOrders
{
    public function __construct(private DomainOperations $operations) {}

    /**
     * Availability and price for one name, without creating anything. Used by the search page,
     * which checks each enabled ending separately. Guests ($user null) see pre-tax catalogue prices.
     * Availability is cached for a minute; quoting checks again.
     *
     * @return array{domain: string, tld: string, available: bool, transferable: bool, premium: bool,
     *     register_price: ?string, transfer_price: ?string, renew_price: ?string, complimentary: bool, existing: bool,
     *     terms: list<int>, register_totals: array<int, string>, taxed: bool}
     */
    public function check(?User $user, string $input): array
    {
        $resolved = app(Catalogue::class)->resolve($input);
        $tld = $resolved['tld'];
        $record = Domain::where('domain', $resolved['domain'])->first();
        $existing = $record !== null;
        $check = Cache::remember('enom:check:'.$tld->server_id.':'.$resolved['domain'], 60, function () use ($tld, $resolved) {
            $result = $this->operations->apiForServer((int) $tld->server_id, true)->search($resolved['domain']);
            if ($result->status !== 'success') { throw new RuntimeException('Availability could not be checked right now.'); }
            return EnomApi::normalize('search', $result);
        });
        $premium = ($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false;
        $terms = self::termsFor($tld, $check);
        $complimentary = $user !== null && self::complimentaryFor($user);
        $price = fn (?string $amount) => $complimentary ? '0.00' : ($user ? Pricing::taxed($user, $amount, 'USD') : $amount);
        $totals = [];
        foreach ($terms as $years) { $totals[$years] = $price($tld->registrationTotal($years)); }
        return ['domain' => $resolved['domain'], 'tld' => $tld->tld,
            'available' => !$existing && !$premium && $terms !== [] && !empty($check['available']),
            'transferable' => !$existing && !$premium && empty($check['available']) && !empty($check['transferable']),
            // Only the owner learns that a name is in this portal; everyone else just sees it taken.
            'premium' => $premium, 'existing' => $existing && $user !== null && (int) $record->user_id === (int) $user->id,
            'complimentary' => $complimentary, 'taxed' => $user !== null,
            'register_price' => $price($tld->price('register')), 'transfer_price' => $price($tld->price('transfer')),
            'renew_price' => $price($tld->price('renew')), 'terms' => $terms, 'register_totals' => $totals];
    }

    /** Registration terms for this name: the ending's range, raised to the name's own registry minimum. */
    private static function termsFor(Tld $tld, array $check): array
    {
        $minimum = (int) ($check['min_reg_year'] ?? 1);
        return array_values(array_filter($tld->terms(), fn (int $years) => $years >= max(1, $minimum)));
    }

    public function quote(User $user, string $domain, string $action = 'register', int $term = 1): array
    {
        if (!in_array($action, ['register', 'transfer'], true)) { throw new RuntimeException('Choose registration or transfer.'); }
        if ($action === 'transfer') { $term = 1; } // A transfer always adds one year.
        $resolved = app(Catalogue::class)->resolve($domain);
        $tld = $resolved['tld'];
        $product = app(Catalogue::class)->ensureProduct((int) $tld->server_id);
        $plan = $product->plans->first(fn ($plan) => $plan->type === 'recurring' && $plan->billing_unit === 'year' && (int) $plan->billing_period === 1);
        if (!$plan) { throw new RuntimeException('Domain catalogue configuration requires review.'); }
        $currency = 'USD';
        $parsed = ['domain' => $resolved['domain'], 'sld' => $resolved['sld'], 'tld' => $tld->tld];
        if (Domain::where('domain', $parsed['domain'])->exists()) { throw new RuntimeException('This domain already has a portal record.'); }
        $result = $this->operations->apiForServer((int) $tld->server_id, true)->search($parsed['domain']);
        $check = EnomApi::normalize('search', $result);
        if ($result->status !== 'success') { throw new RuntimeException('Availability could not be checked right now.'); }
        if (($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false
            || ($action === 'register' && (empty($check['available']) || !in_array($term, self::termsFor($tld, $check), true)))
            || ($action === 'transfer' && (!empty($check['available']) || empty($check['transferable'])))) {
            throw new RuntimeException('This domain is unavailable or requires unsupported registry pricing.');
        }
        $complimentary = self::complimentaryFor($user);
        $amount = $complimentary ? '0.00' : Pricing::taxed($user, $action === 'register' ? $tld->registrationTotal($term) : $tld->price('transfer'), $currency);
        $renewalAmount = $complimentary ? '0.00' : Pricing::taxed($user, $tld->price('renew'), $currency);
        if ($amount === null || $renewalAmount === null) { throw new RuntimeException('Domain pricing is unavailable.'); }
        $intent = OrderIntent::create([
            'id' => bin2hex(random_bytes(24)), 'user_id' => $user->id, 'server_id' => $tld->server_id,
            'product_id' => $product->id, 'plan_id' => $plan->id, ...$parsed,
            'action' => $action, 'term' => $term, 'amount' => $amount, 'renewal_amount' => $renewalAmount,
            'currency' => $currency, 'expires_at' => now()->addMinutes(30),
            'payload' => ['quote_check' => ['available' => $check['available'] ?? false, 'transferable' => $check['transferable'] ?? false],
                'complimentary' => $complimentary, 'terms' => self::termsFor($tld, $check)],
        ]);
        return $this->quoteData($intent);
    }

    private function quoteData(OrderIntent $intent): array
    {
        $tld = Tld::query()->where('server_id', $intent->server_id)->where('tld', $intent->tld)->first();
        $years = max(1, (int) $intent->term);
        return ['intent_id' => $intent->id, 'domain' => $intent->domain, 'action' => $intent->action,
            'term' => $years, 'years' => $years, 'amount' => $intent->amount, 'currency' => $intent->currency,
            'renewal_amount' => $intent->renewal_amount, 'expires_at' => $intent->expires_at->toIso8601String(),
            'terms' => $intent->action === 'register' ? array_values(array_map('intval', $intent->payload['terms'] ?? [$years])) : [1],
            'min_years' => $tld ? min($tld->terms()) : 1, 'max_years' => $tld ? max($tld->terms()) : 1,
            'new_expiry_estimate' => now()->addYearsNoOverflow($years)->toDateString(),
            // Every term's total with the client's own tax rule, so the page never re-derives a rounded amount.
            'totals' => $tld && $intent->action === 'register' && !($intent->payload['complimentary'] ?? false) && ($owner = User::find($intent->user_id))
                ? array_filter(array_combine($terms = array_values(array_map('intval', $intent->payload['terms'] ?? [$years])),
                    array_map(fn (int $n) => Pricing::taxed($owner, $tld->registrationTotal($n), $intent->currency), $terms))) : [],
            'registry_fields' => $tld ? app(Catalogue::class)->attributesFor($tld) : [],
            'complimentary' => (bool) ($intent->payload['complimentary'] ?? false),
            'server_id' => (int) $intent->server_id,
            'default_nameservers' => RuntimeGuard::defaultNameservers((int) $intent->server_id),
            'test_environment' => RuntimeGuard::isTest((int) $intent->server_id)];
    }

    /**
     * Requote an open order for a different registration term, keeping the same intent so the
     * client's form stays put. Prices come from the catalogue; availability was checked at quote time.
     */
    public function changeTerm(User $user, string $intentId, int $years): array
    {
        return DB::transaction(function () use ($user, $intentId, $years) {
            $intent = OrderIntent::where('user_id', $user->id)->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($intent->claimed_at || $intent->expires_at->isPast()) { throw new RuntimeException('Domain quote expired or was already used.'); }
            if ($intent->action !== 'register' || !in_array($years, array_map('intval', $intent->payload['terms'] ?? [1]), true)) {
                throw new RuntimeException('That term is not available for this name.');
            }
            $tld = Tld::query()->where('server_id', $intent->server_id)->where('tld', $intent->tld)->firstOrFail();
            $complimentary = (bool) ($intent->payload['complimentary'] ?? false);
            $amount = $complimentary ? '0.00' : Pricing::taxed($user, $tld->registrationTotal($years), $intent->currency);
            if ($amount === null) { throw new RuntimeException('Domain pricing is unavailable.'); }
            // Keep the expiry explicitly: some MySQL/MariaDB installs auto-update the first TIMESTAMP column.
            $intent->update(['term' => $years, 'amount' => $amount, 'expires_at' => $intent->expires_at]);
            return $this->quoteData($intent->fresh());
        });
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

    /**
     * Turn a quote into an order. A paying client gets an invoice; a complimentary client's
     * registration is queued straight away and paid by the reseller.
     *
     * @return Invoice|Domain the invoice to pay, or the domain for complimentary orders
     */
    public function place(User $user, string $intentId, array $contacts = [], ?string $epp = null, array $nameservers = [], array $registry = []): Invoice|Domain
    {
        return DB::transaction(function () use ($user, $intentId, $contacts, $epp, $nameservers, $registry) {
            // Client row first: serialises complimentary limits with grants and other orders.
            User::whereKey($user->id)->lockForUpdate()->first();
            $intent = OrderIntent::where('user_id', $user->id)->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($intent->claimed_at) {
                return ($intent->payload['complimentary'] ?? false)
                    ? Domain::where('user_id', $user->id)->where('service_id', $intent->service_id)->firstOrFail()
                    : Invoice::where('user_id', $user->id)->findOrFail($intent->invoice_id);
            }
            if ($intent->expires_at->isPast()) { throw new RuntimeException('Domain quote expired. Search again.'); }
            $this->operations->apiForServer((int) $intent->server_id, true); // Credentials present; no request.
            $product = Product::whereKey($intent->product_id)->lockForUpdate()->firstOrFail();
            $plan = $product->plans()->findOrFail($intent->plan_id);
            if ($product->server_id != $intent->server_id || $product->server?->extension !== 'Enom'
                || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1) { throw new RuntimeException('Domain quote configuration changed.'); }
            $complimentary = (bool) ($intent->payload['complimentary'] ?? false);
            $tld = Tld::query()->where('server_id', $intent->server_id)->where('tld', $intent->tld)->first();
            if (!$tld?->orderable() || $complimentary !== self::complimentaryFor($user)
                || !$complimentary && (Pricing::taxed($user, $intent->action === 'register' ? $tld->registrationTotal(max(1, (int) $intent->term)) : $tld->price('transfer'), $intent->currency) !== $intent->amount
                    || Pricing::taxed($user, $tld->price('renew'), $intent->currency) !== $intent->renewal_amount)) {
                throw new PriceChangedException('Domain pricing changed. Search again.');
            }
            if ($intent->action === 'transfer' && (!is_string($epp) || strlen($epp) < 1 || strlen($epp) > 255 || preg_match('/[\x00-\x1f]/', $epp))) { throw new RuntimeException('A valid transfer authorization code is required.'); }
            if (!$contacts) { throw new RuntimeException('Registrant contact details are required.'); }
            foreach ($contacts as $type => $contact) {
                if (!is_array($contact)) { throw new RuntimeException('Registrant contact details are invalid.'); }
                DomainManager::validatePayload('contacts', ['confirmed' => true, 'contact_type' => $type, 'contact' => $contact]);
                if (empty($contact['postal_code'])) { throw new RuntimeException('Registrant postal code is required.'); }
            }
            $nameservers = $intent->action === 'register' ? ($nameservers ?: RuntimeGuard::defaultNameservers((int) $intent->server_id)) : [];
            if ($nameservers) { DomainManager::validatePayload('nameservers', ['confirmed' => true, 'nameservers' => implode("\n", $nameservers)]); }
            // Registry details (for example .us nexus) are validated against the ending's stored schema.
            $registry = RegistryFields::validate(app(Catalogue::class)->attributesFor($tld), $registry);
            $intent->payload = ['contacts' => $contacts, 'epp' => $intent->action === 'transfer' ? $epp : null,
                'nameservers' => $nameservers, 'complimentary' => $complimentary, 'registry' => $registry,
                'terms' => $intent->payload['terms'] ?? [1]];
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
            RuntimeGuard::bindAccount((int) $intent->server_id);
            $configuration = $this->operations->providerConfiguration((int) $intent->server_id, true);
            $policy = ['version' => 1, 'enabled' => true,
                'issue_days' => (int) ($product->server->settings->firstWhere('key', 'issue_days')?->value ?? 21),
                'due_days' => (int) ($product->server->settings->firstWhere('key', 'due_days')?->value ?? 7), 'term' => 1,
                'account_fingerprint' => RuntimeGuard::accountFingerprint($configuration)];
            if (Domain::where('domain', $intent->domain)->exists()) {
                throw new InputValidationException(['domain' => 'This domain already has a portal record.']);
            }
            $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $intent->server_id,
                'domain' => $intent->domain, 'sld' => $intent->sld, 'tld' => $intent->tld,
                'billing_managed' => true, 'renewal_policy' => $policy,
                // A complimentary client over their limit pays for this domain now and at renewal.
                'price_mode' => !$complimentary && Pricing::clientComplimentary($user) ? 'standard' : null,
                'status' => $intent->action === 'transfer' ? 'transfer_pending' : 'registration_pending', 'capabilities' => []]);
            app(DomainBilling::class)->policy($domain);
            $service->properties()->create(['key' => 'enom_domain', 'value' => $intent->domain]);
            if ($complimentary) {
                $domain->refresh();
                $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id,
                    'operation_key' => 'complimentary:'.$intent->id, 'action' => $intent->action, 'term' => max(1, (int) $intent->term),
                    'funding_basis' => 'complimentary', 'state' => 'queued', 'amount' => '0.00', 'currency' => $intent->currency,
                    'authorized_by' => $user->id, 'funding_reason' => 'Complimentary client',
                    'payload' => $intent->payload, 'policy' => self::complimentaryPolicy($domain, $service, (int) $intent->server_id, max(1, (int) $intent->term))]);
                // A paid $0.00 record keeps the native unpaid-order cleanup away from slow transfers and shows the client what happened.
                $record = new Invoice(['user_id' => $user->id, 'currency_code' => $intent->currency, 'status' => Invoice::STATUS_PAID, 'due_at' => now()]);
                $record->send_create_email = false;
                $record->save();
                $record->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => '0.00',
                    'description' => self::lineDescription($intent).' — complimentary']);
                $intent->update(['claimed_at' => now(), 'invoice_id' => $record->id]);
                \Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation::dispatch($operation->id)->afterCommit();
                return $domain;
            }
            $invoice = $this->newInvoice($user, $intent->currency);
            $item = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id,
                'quantity' => 1, 'price' => $intent->amount, 'description' => self::lineDescription($intent)]);
            $operation = $this->operations->bindInvoiceItem($item);
            if (!$operation || $operation->state !== 'awaiting_payment') { throw new RuntimeException('Domain payment binding could not be established.'); }
            $intent->update(['claimed_at' => now(), 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]);
            return $invoice;
        });
    }

    /** Complimentary applies to a new order when the client has a grant with room under its domain limit. */
    private static function complimentaryFor(User $user): bool
    {
        return Pricing::clientComplimentary($user) && Pricing::clientHasRoom($user);
    }

    /** Binding and cost cap recorded on every complimentary request. */
    private static function lineDescription(OrderIntent $intent): string
    {
        $years = max(1, (int) $intent->term);
        return $intent->action === 'transfer' ? 'Transfer '.$intent->domain.' (adds 1 year)'
            : 'Register '.$intent->domain.' ('.$years.' '.($years === 1 ? 'year' : 'years').')';
    }

    public static function complimentaryPolicy(Domain $domain, Service $service, int $serverId, int $years = 1): array
    {
        return array_replace($domain->renewal_policy ?? [], [
            'billing_managed' => true, 'dispatch_marker_version' => 1, 'domain' => $domain->domain, 'user_id' => (int) $domain->user_id,
            'service_id' => (int) $service->id, 'server_id' => $serverId,
            'authority_generation' => (int) $domain->authority_generation, 'confirmed_revision' => (int) $domain->confirmed_revision,
            'currency' => $service->currency_code, 'term' => $years, 'retail_minor' => 0,
            'complimentary_cap_minor' => Pricing::complimentaryCapMinor($serverId),
        ]);
    }

    public function renew(User $user, int $domainId, ?string $nonce = null, int $years = 1): Invoice
    {
        $domain = Domain::where('user_id', $user->id)->findOrFail($domainId);
        if ($domain->billing_managed) { return app(DomainBilling::class)->invoice($domainId, $user, $nonce, $years); }
        if ($years !== 1) { throw new RuntimeException('This domain renews one year at a time.'); }
        return $this->billExisting($user, $domainId, 'renew', $nonce);
    }

    /** Terms a client may renew for now, and the price per year they pay. */
    public function renewalOptions(User $user, int $domainId): array
    {
        $domain = Domain::where('user_id', $user->id)->findOrFail($domainId);
        $max = $domain->billing_managed ? DomainBilling::maxRenewalYears($domain) : min(1, DomainBilling::maxRenewalYears($domain));
        $perYear = Pricing::renewalPrice($domain);
        return ['years' => $max > 0 ? range(1, $max) : [], 'price_per_year' => $perYear,
            'totals' => $perYear === null ? [] : array_combine($max > 0 ? range(1, $max) : [], array_map(
                fn (int $years) => RegistrarMoney::decimal(number_format(((float) $perYear) * $years, 2, '.', '')), $max > 0 ? range(1, $max) : [])),
            'expires_at' => $domain->provider_expires_at?->toDateString(), 'complimentary' => Pricing::isComplimentary($domain),
            'open_invoice' => self::openRenewalInvoice($domain)];
    }

    /** The unpaid renewal invoice for this expiry, if one was already issued. */
    private static function openRenewalInvoice(Domain $domain): ?array
    {
        $operation = Operation::where('domain_id', $domain->id)->where('action', 'renew')->where('state', 'awaiting_payment')
            ->whereNotNull('invoice_id')->latest('id')->first();
        $invoice = $operation ? Invoice::where('user_id', $domain->user_id)->find($operation->invoice_id) : null;
        return $invoice && $invoice->status === Invoice::STATUS_PENDING
            ? ['id' => (int) $invoice->id, 'years' => max(1, (int) $operation->term), 'url' => route('invoices.show', $invoice)] : null;
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
            User::whereKey($user->id)->lockForUpdate()->first();
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
                $base = RuntimeGuard::setting((int) $domain->server_id, 'privacy_price')
                    ?? $service->product->settings()->where('key', 'enom_privacy_price')->first()?->value;
                if (!is_numeric($base) || (float) $base <= 0) { throw new RuntimeException('Privacy retail pricing is unavailable.'); }
                $amount = Pricing::taxed($user, (string) $base, $service->currency_code);
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
}

<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use RuntimeException;

class DomainOrders
{
    public function __construct(private DomainOperations $operations) {}

    /**
     * Availability and price for one name, without creating anything. Used by the search page,
     * which checks each enabled ending separately. Guests ($user null) see pre-tax catalogue prices.
     * Availability is cached for a minute; cart admission checks again.
     *
     * @return array{domain: string, tld: string, available: bool, transferable: bool, premium: bool,
     *     register_price: ?string, transfer_price: ?string, renew_price: ?string, existing: bool,
     *     terms: list<int>, register_totals: array<int, string>, taxed: bool}
     */
    public function check(?User $user, string $input): array
    {
        $resolved = app(Catalogue::class)->resolve($input);
        $tld = $resolved['tld'];
        $record = DomainReservations::blocking($resolved['domain']);
        $existing = $record !== null;
        $check = Cache::remember('enom:check:'.$tld->server_id.':'.$resolved['domain'], 60, function () use ($tld, $resolved) {
            $result = $this->operations->apiForServer((int) $tld->server_id, true)->search($resolved['domain']);
            if ($result->status !== 'success') { throw new RuntimeException('Availability could not be checked right now.'); }
            return EnomApi::normalize('search', $result);
        });
        $premium = ($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false;
        $terms = self::termsFor($tld, $check);
        $precheck = [];
        if (empty($check['available']) && app(TransferOrders::class)->allowed($tld)) {
            try { $precheck = app(TransferOrders::class)->precheck($tld, $resolved['domain']); }
            catch (InputValidationException $e) { report($e); }
        }
        $transferable = !$existing && !$premium && empty($check['available']) && !empty($check['transferable']) && $tld->orderable('transfer') && app(TransferOrders::class)->allowed($tld) && ($precheck['allowed'] ?? false);
        $notRegistered = ($precheck['code'] ?? null) === 'transfer_not_registered';
        $price = fn (?string $amount) => $user ? Pricing::taxed($user, $amount, 'USD') : $amount;
        $totals = [];
        foreach ($terms as $years) { $totals[$years] = $price($tld->registrationTotal($years)); }
        return ['domain' => $resolved['domain'], 'tld' => $tld->tld,
            'available' => !$existing && !$premium && $terms !== [] && $tld->orderable('register') && !empty($check['available']),
            'transferable' => $transferable,
            // Only the owner learns that a name is in this portal; everyone else just sees it taken.
            'premium' => $premium, 'existing' => $existing && $user !== null && (int) $record->user_id === (int) $user->id,
            'taxed' => $user !== null,
            'register_price' => $price($tld->price('register')), 'transfer_price' => $notRegistered ? null : $price($tld->price('transfer')),
            'renew_price' => $price($tld->price('renew')), 'warning' => $precheck['warning'] ?? null,
            'transfer_reason_code' => $precheck['code'] ?? null,
            'transfer_reason' => isset($precheck['allowed']) && !$precheck['allowed'] ? TransferOrders::reason($precheck['code'] ?? null) : null,
            'register_url' => self::checkoutUrl($tld, 'register', $resolved['domain'], $terms[0] ?? null),
            'transfer_url' => $transferable ? self::checkoutUrl($tld, 'transfer', $resolved['domain'], 1) : null, 'terms' => $terms, 'register_totals' => $totals];
    }

    /** Registration terms for this name: the ending's range, raised to the name's own registry minimum. */
    private static function termsFor(Tld $tld, array $check): array
    {
        $minimum = (int) ($check['min_reg_year'] ?? 1);
        return array_values(array_filter($tld->terms(), fn (int $years) => $years >= max(1, $minimum) && $years <= min(10, (int) ($check['max_reg_year'] ?? 10))));
    }

    public static function checkoutUrl(Tld $tld, string $action, string $name, ?int $years = null): ?string
    {
        $product = app(Catalogue::class)->productFor($tld, $action);
        if (!$product || !$tld->orderable($action)) { return null; }
        $plan = $product->plans()->whereHas('prices', fn ($q) => $q->where('currency_code', 'USD')->where('price', '>', 0))
            ->when($years !== null, fn ($q) => $q->where('billing_period', $years))->orderBy('sort')->first();
        return $plan ? route('products.checkout', ['category' => $product->category, 'product' => $product,
            'plan' => $plan->id, 'config' => ['domain' => $name]]) : null;
    }

    public static function complimentaryPolicy(Domain $domain, Service $service, int $serverId, int $years = 1): array
    {
        return array_replace($domain->renewal_policy ?? [], [
            'billing_managed' => true, 'domain' => $domain->domain, 'user_id' => (int) $domain->user_id,
            'service_id' => (int) $service->id, 'server_id' => $serverId,
            'authority_generation' => (int) $domain->authority_generation, 'confirmed_revision' => (int) $domain->confirmed_revision,
            'currency' => $service->currency_code, 'term' => $years, 'retail_minor' => 0,
            'complimentary_cap_minor' => Pricing::complimentaryCapMinor($serverId),
        ]);
    }

    public function renew(User $user, int $domainId, ?string $nonce = null, int $years = 1): Invoice
    {
        $domain = Domain::where('user_id', $user->id)->findOrFail($domainId);
        return app(DomainBilling::class)->invoice($domainId, $user, $nonce, $years);
    }

    /** Terms a client may renew for now, and the price per year they pay. */
    public function renewalOptions(User $user, int $domainId): array
    {
        $domain = Domain::where('user_id', $user->id)->findOrFail($domainId);
        $max = $domain->billing_managed ? DomainBilling::maxRenewalYears($domain) : 0;
        $perYear = Pricing::renewalPrice($domain);
        return ['years' => $max > 0 ? range(1, $max) : [], 'price_per_year' => $perYear,
            'totals' => $perYear === null ? [] : array_combine($max > 0 ? range(1, $max) : [], array_map(
                fn (int $years) => PricingRule::total($perYear, $years), $max > 0 ? range(1, $max) : [])),
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
        return $this->billPrivacy($user, $domainId, $action, $nonce);
    }

    private function billPrivacy(User $user, int $domainId, string $action, ?string $nonce): Invoice
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
            if (($domain->capabilities[$action] ?? false) !== true) { throw new RuntimeException('Privacy purchase is unavailable for this domain.'); }
            $base = RuntimeGuard::setting((int) $domain->server_id, 'privacy_price');
            if (!is_numeric($base) || (float) $base <= 0) { throw new RuntimeException('Privacy retail pricing is unavailable.'); }
            $amount = Pricing::taxed($user, (string) $base, $service->currency_code);
            $invoice = $this->newInvoice($user, $service->currency_code);
            $item = $invoice->items()->create(['reference_type' => null,
                'reference_id' => null, 'quantity' => 1, 'price' => $amount,
                'description' => 'Domain privacy '.$domain->domain.' (1 year)']);
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
                'policy' => [],
                'base_expires_at' => $privacyBase?->format('Y-m-d'),
                'expected_expires_at' => $privacyBase ? \Carbon\CarbonImmutable::instance($privacyBase)->addYearNoOverflow()->format('Y-m-d') : now()->addYearNoOverflow()->format('Y-m-d')]);
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

<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;

/** Native cart admission and synchronous initial invoice binding. */
final class CartOrdering
{
    public function boot(): void
    {
        foreach ([\App\Events\CartItem\Creating::class, \App\Events\CartItem\Updating::class] as $event) {
            Event::listen($event, fn ($event) => $this->admit($event->cartItem));
        }
        Event::listen(\App\Events\Order\Creating::class, function ($event) {
            $cart = Cart::get();
            if (!$cart->exists) { return; }
            $cart->load('items.product.server', 'items.plan.prices', 'coupon.products');
            $domainItems = $cart->items->filter(fn ($item) => $item->product?->server?->extension === 'Enom');
            if ($domainItems->isEmpty()) { return; }
            if ((int) Auth::id() !== (int) $event->order->user_id || $cart->user_id !== null && (int) $cart->user_id !== (int) $event->order->user_id
                || $cart->currency_code !== 'USD' || $event->order->currency_code !== 'USD') {
                throw new DisplayException('Domain orders must use your own cart and USD.');
            }
            foreach ($domainItems as $item) {
                $this->admit($item);
                if (!$item->price->available || (float) $item->price->total <= 0) {
                    throw new DisplayException('A domain must have a price above $0.00 after discounts.');
                }
            }
        });
        Event::listen(\App\Events\InvoiceItem\Creating::class, function ($event) {
            $item = $event->invoiceItem;
            if ($item->reference_type !== Service::class || !($service = $item->reference)
                || $service->product?->server?->extension !== 'Enom') { return; }
            if (Domain::where('service_id', $service->id)->exists()) { return; }
            $mapping = app(Catalogue::class)->mapping($service->product, $service->plan);
            $name = self::canonical($service->properties()->where('key', 'domain')->value('value'), $mapping['tld']);
            $years = (int) $mapping['years'];
            $item->description = $mapping['action'] === 'transfer' ? 'Transfer '.$name.' (adds 1 year)'
                : 'Register '.$name.' ('.$years.' '.($years === 1 ? 'year' : 'years').')';
        });
        Event::listen(\App\Events\InvoiceItem\Created::class, fn ($event) => app(DomainOperations::class)->bindInvoiceItem($event->invoiceItem));
    }

    /** Rendering checkout fields never reads the provider. */
    public function fields(Product $product, array $values = []): array
    {
        $mapping = app(Catalogue::class)->mapping($product);
        if ($mapping === null) { return []; }
        $tld = $mapping['tld'];
        $schema = app(Catalogue::class)->attributesFor($tld);
        $fields = [['name' => 'domain', 'type' => 'text', 'label' => 'Domain name', 'required' => true,
            'placeholder' => 'example.'.$tld->tld,
            'description' => ($mapping['action'] === 'transfer'
                ? "The domain you're moving here. You'll enter its transfer code after checkout."
                : "The full name, like example.{$tld->tld}. It's registered to the name and address on your account."),
            'validation' => [function ($attribute, $value, $fail) use ($tld, $mapping) {
                try { $this->checkName($tld, $mapping['action'], self::canonical($value, $tld)); }
                catch (\Throwable $error) { $fail($error instanceof DisplayException ? $error->getMessage() : 'This domain could not be checked. Try again.'); }
            }]]];
        $registry = self::registryInput($schema, $values);
        $needed = RegistryFields::needed($schema, $registry);
        foreach ($schema as $field) {
            if ($field['child'] && !in_array($field['name'], $needed, true)) { continue; }
            $fields[] = ['name' => 'enom_registry_'.$field['name'], 'label' => $field['label'],
                'type' => $field['options'] ? 'select' : 'text', 'required' => $field['child'] || $field['required'],
                'description' => 'The .'.$tld->tld.' registry asks for this.',
                'options' => array_column($field['options'], 'label', 'value')];
        }
        return $fields;
    }

    public static function canonical(mixed $input, Tld $tld): string
    {
        if (!is_string($input)) { throw new DisplayException('Enter a domain name like example.'.$tld->tld.'.'); }
        $name = strtolower(trim($input));
        if (preg_match('/[^\x00-\x7f]/', $name) || str_contains($name, 'xn--')) {
            throw new DisplayException("Names with accents or non-Latin letters aren't available yet.");
        }
        if (!str_contains($name, '.')) { $name .= '.'.$tld->tld; }
        if (!str_ends_with($name, '.'.$tld->tld)) { throw new DisplayException('This order is for .'.$tld->tld.' names. Search again to order this domain.'); }
        $label = substr($name, 0, -strlen($tld->tld) - 1);
        if (strlen($label) < 2 || strlen($label) > 63 || strlen($name) > 253) {
            throw new DisplayException('The part before .'.$tld->tld.' must be 2 to 63 characters.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/D', $label) || substr($label, 2, 2) === '--') {
            throw new DisplayException('Enter a domain name using letters, numbers and hyphens, with no hyphen at either end.');
        }
        return $name;
    }

    /** Admission checks the complete config and writes only canonical, known fields. */
    public function admit(CartItem $item): void
    {
        $product = Product::find($item->product_id);
        if (!$product || $product->server?->extension !== 'Enom') { return; }
        $mapping = app(Catalogue::class)->mapping($product, $item->plan()->first());
        $tld = $mapping['tld'];
        if ((int) $item->quantity !== 1 || !empty($item->config_options) || $item->cart?->currency_code !== 'USD') {
            throw new DisplayException('Domain orders require one domain per line, no paid options and USD.');
        }
        $this->sale($mapping, $item->plan()->first());
        $config = is_array($item->checkout_config) ? $item->checkout_config : [];
        $name = self::canonical($config['domain'] ?? null, $tld);
        $this->checkName($tld, $mapping['action'], $name, (int) $mapping['years']);
        $schema = app(Catalogue::class)->attributesFor($tld);
        $registry = RegistryFields::validate($schema, self::registryInput($schema, $config));
        foreach ($item->cart->items()->where('id', '!=', $item->id ?? 0)->with('product.server')->get() as $other) {
            if ($other->product?->server?->extension !== 'Enom') { continue; }
            $otherMapping = app(Catalogue::class)->mapping($other->product, $other->plan);
            if (self::canonical($other->checkout_config['domain'] ?? null, $otherMapping['tld']) === $name) {
                throw new DisplayException($name.' is already in your cart.');
            }
        }
        $clean = ['domain' => $name];
        foreach ($registry as $key => $value) { $clean['enom_registry_'.$key] = $value; }
        $item->checkout_config = $clean;
    }

    private function sale(array $mapping, $plan): void
    {
        $tld = $mapping['tld'];
        $price = $plan?->prices()->where('currency_code', 'USD')->first();
        if (!$tld->orderable($mapping['action']) || $tld->price('renew') === null || !$price || (float) $price->price <= 0
            || (float) $price->setup_fee !== 0.0 || $mapping['years'] === null
            || $mapping['action'] === 'register' && !in_array((int) $mapping['years'], $tld->terms(), true)
            || $mapping['action'] === 'transfer' && (int) $mapping['years'] !== 1) {
            throw new DisplayException('This domain plan is no longer available. Search again.');
        }
    }

    public function checkName(Tld $tld, string $action, string $name, ?int $years = null, bool $reserved = false): array
    {
        if (!$reserved && DomainReservations::blocking($name) !== null) { throw new DisplayException($name.' already has an order.'); }
        $result = app(DomainOperations::class)->apiForServer((int) $tld->server_id, true)->search($name);
        if ($result->status !== 'success') { throw new DisplayException('This domain could not be checked. Try again.'); }
        $check = EnomApi::normalize('search', $result);
        if (($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false) {
            throw new DisplayException($name.' is a premium name. Premium names are not available.');
        }
        if ($action === 'register') {
            $minimum = max(1, (int) ($check['min_reg_year'] ?? 1));
            $maximum = min(10, (int) ($check['max_reg_year'] ?? 10));
            if (empty($check['available'])) { throw new DisplayException($name.' is already registered.'); }
            $terms = array_values(array_filter($tld->terms(), fn ($term) => $term >= $minimum && $term <= $maximum));
            if (!$terms || $years !== null && !in_array($years, $terms, true)) {
                throw new DisplayException($name.' requires a registration term from '.$minimum.' to '.$maximum.' years.');
            }
        } else {
            if (!empty($check['available'])) { throw new DisplayException($name.' isn’t registered yet, so there’s nothing to transfer.'); }
            if (empty($check['transferable']) || !app(TransferOrders::class)->allowed($tld)) {
                throw new DisplayException($name.' can’t be transferred to us.');
            }
            $precheck = app(TransferOrders::class)->precheck($tld, $name);
            if (!$precheck['allowed']) { throw new DisplayException(TransferOrders::reason($precheck['code'] ?? null)); }
            $check['warning'] = $precheck['warning'] ?? null;
        }
        return $check;
    }

    private static function registryInput(array $schema, array $config): array
    {
        $registry = [];
        foreach ($schema as $field) { $registry[$field['name']] = $config['enom_registry_'.$field['name']] ?? null; }
        return $registry;
    }

    public function bind(InvoiceItem $item): ?Operation
    {
        if ($item->reference_type !== Service::class || !($service = $item->reference)
            || $service->product?->server?->extension !== 'Enom') { return null; }
        return DB::transaction(function () use ($item, $service) {
            $product = Product::whereKey($service->product_id)->lockForUpdate()->firstOrFail();
            $mapping = app(Catalogue::class)->mapping($product, $service->plan);
            $tld = Tld::whereKey($mapping['tld']->id)->lockForUpdate()->firstOrFail();
            $mapping['tld'] = $tld;
            $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($existing = Operation::where('operation_key', 'invoice-item:'.$item->id)->first()) {
                if ((int) $existing->service_id !== (int) $service->id || (int) $existing->invoice_id !== (int) $item->invoice_id
                    || (int) $existing->invoice_item_id !== (int) $item->id || $existing->domain?->domain !== $service->properties()->where('key', 'domain')->value('value')
                    || (float) $existing->amount !== (float) $item->price || $existing->action !== $mapping['action']
                    || (int) $existing->domain?->service_id !== (int) $service->id
                    || (int) $existing->domain?->user_id !== (int) $service->user_id || (int) $item->invoice->user_id !== (int) $service->user_id
                    || $item->invoice->currency_code !== $existing->currency || $service->currency_code !== $existing->currency
                    || (int) $item->quantity !== 1 || (int) $service->quantity !== 1) { throw new DisplayException('Domain payment binding conflicts with this line.'); }
                return $existing;
            }
            if (Domain::where('service_id', $service->id)->exists()) { throw new DisplayException('An initial domain invoice is already bound.'); }
            $invoice = $item->invoice;
            if ($invoice->status !== Invoice::STATUS_PENDING || (int) $invoice->user_id !== (int) $service->user_id
                || $invoice->currency_code !== 'USD' || $service->currency_code !== 'USD' || (int) $service->quantity !== 1
                || (int) $item->quantity !== 1 || (float) $item->price <= 0 || $service->status !== Service::STATUS_PENDING) {
                throw new DisplayException('Domain payment binding is invalid.');
            }
            $this->sale($mapping, $service->plan);
            $config = $service->properties()->pluck('value', 'key')->all();
            $name = self::canonical($config['domain'] ?? null, $tld);
            $years = (int) $mapping['years'];
            $checked = $this->checkName($tld, $mapping['action'], $name, $years);
            $registry = RegistryFields::validate(app(Catalogue::class)->attributesFor($tld), self::registryInput(app(Catalogue::class)->attributesFor($tld), $config));
            $snapshot = app(RegistrationSetup::class)->payload($service->user, (int) $tld->server_id, $mapping['action']);
            RuntimeGuard::bindAccount((int) $tld->server_id);
            $configuration = app(DomainOperations::class)->providerConfiguration((int) $tld->server_id, true);
            $renewal = Pricing::taxed($service->user, $tld->price('renew'), 'USD');
            $policy = ['version' => 1, 'enabled' => true, 'term' => 1,
                'issue_days' => (int) ($product->server->settings->firstWhere('key', 'issue_days')?->value ?? 21),
                'due_days' => (int) ($product->server->settings->firstWhere('key', 'due_days')?->value ?? 7),
                'account_fingerprint' => RuntimeGuard::accountFingerprint($configuration)];
            DomainReservations::releaseTerminal($name);
            $domain = Domain::create(['service_id' => $service->id, 'user_id' => $service->user_id, 'server_id' => $tld->server_id,
                'domain' => $name, 'sld' => substr($name, 0, -strlen($tld->tld) - 1), 'tld' => $tld->tld,
                'status' => $mapping['action'] === 'register' ? 'registration_pending' : 'transfer_pending',
                'billing_managed' => true, 'renewal_policy' => $policy, 'price_mode' => 'standard', 'capabilities' => []]);
            $frozen = array_replace($policy, ['billing_managed' => true, 'domain' => $name, 'user_id' => (int) $service->user_id, 'service_id' => (int) $service->id, 'server_id' => (int) $tld->server_id,
                'authority_generation' => (int) $domain->authority_generation, 'confirmed_revision' => (int) $domain->confirmed_revision,
                'currency' => 'USD', 'term' => $years, 'retail_minor' => PricingRule::minor($item->price),
                'checkout_product_id' => (int) $product->id, 'checkout_plan_id' => (int) $service->plan_id, 'checkout_years' => $years, 'paid_at' => null,
                'transfer_lookup_unknown' => $mapping['action'] === 'transfer' && !empty($checked['warning'])]);
            $operation = Operation::create(['operation_key' => 'invoice-item:'.$item->id, 'domain_id' => $domain->id, 'service_id' => $service->id,
                'invoice_id' => $item->invoice_id, 'invoice_item_id' => $item->id, 'action' => $mapping['action'], 'term' => $years,
                'amount' => $item->price, 'currency' => 'USD', 'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment',
                'payload' => ['contacts' => $snapshot['contacts'], 'registry' => $registry, 'nameservers' => $snapshot['nameservers'], 'epp' => null], 'policy' => $frozen]);
            $service->label = $name;
            $service->plan_id = app(Catalogue::class)->billingPlan($product)->id;
            $service->price = $renewal;
            $service->expires_at = null;
            $service->save();
            $service->properties()->where('key', 'domain')->update(['value' => $name]);
            app(DomainBilling::class)->policy($domain->fresh());
            return $operation;
        });
    }
}

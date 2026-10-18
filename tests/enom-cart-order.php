<?php
/** Native cart helpers. Include after the disposable application bootstrap. */
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;

/** Publish synthetic prices through the real catalogue, including exact costs for every term. */
function enomTestTld(array $attributes): Tld
{
    $register = $attributes['register_price'] ?? $attributes['fixed_register'] ?? '12.00';
    $renew = $attributes['renew_price'] ?? $attributes['fixed_renew'] ?? '14.00';
    $transfer = $attributes['transfer_price'] ?? $attributes['fixed_transfer'] ?? '13.00';
    $cost = $attributes['cost_register'] ?? '1.0000';
    unset($attributes['register_price'], $attributes['transfer_price'], $attributes['cost_register']);
    $tld = Tld::create($attributes + ['enabled' => true, 'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => true,
        'use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => $register, 'fixed_renew' => $renew, 'fixed_transfer' => $transfer,
        'cost_register_terms' => array_fill(1, 10, $cost), 'cost_renew' => '1.0000', 'cost_transfer' => '1.0000', 'min_years' => 1, 'max_years' => 10]);
    app(Catalogue::class)->publish($tld);
    return $tld->fresh();
}

function enomCartProfile(User $user): void
{
    $user->update(['first_name' => 'Test', 'last_name' => 'Owner', 'email' => 'account-'.$user->id.'@example.com']);
    foreach (['phone' => '+1.2065550100', 'address' => '1 Main Street', 'city' => 'Seattle', 'state' => 'WA', 'zip' => '98101', 'country' => 'US'] as $key => $value) {
        $user->properties()->updateOrCreate(['key' => $key], ['name' => ucfirst($key), 'value' => $value]);
    }
}

function enomCartStart(User $user): App\Models\Cart
{
    Illuminate\Support\Once::flush();
    Illuminate\Support\Facades\Auth::login($user);
    Illuminate\Support\Facades\RateLimiter::clear('cart-add:user:'.$user->id);
    if (function_exists('enomLogout')) { enomLogout($user); }
    return App\Models\Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
}

function enomCartAdd(User $user, App\Models\Cart $cart, string $domain, string $action = 'register', int $years = 1, array $registry = [])
{
    $tld = Tld::where('tld', explode('.', strtolower(trim($domain)), 2)[1] ?? 'com')->where('enabled', true)->firstOrFail();
    $product = app(Catalogue::class)->productFor($tld, $action);
    $plan = $product->plans()->where('billing_period', $years)->firstOrFail();
    $config = ['domain' => $domain];
    foreach ($registry as $key => $value) { $config['enom_registry_'.$key] = $value; }
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->withQueryParams(['plan' => $plan->id, 'config' => $config])
        ->test(App\Livewire\Products\Checkout::class, ['category' => $product->category, 'product' => $product->slug])->call('checkout');
}

function enomCartCheckout(User $user, App\Models\Cart $cart)
{
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false)->call('checkout');
}

/** Existing test transports stay injected. The native components create every billing row. */
function enomCartOrder(User $user, string $domain, string $action = 'register', int $years = 1, array $registry = []): array
{
    global $testRoot, $release, $app, $enomHttpKernel, $enomCookieJars;
    if (!function_exists('enomHttp')) { require __DIR__.'/enom-http.php'; }
    $cart = enomCartStart($user);
    enomCartAdd($user, $cart, $domain, $action, $years, $registry)->assertHasNoErrors();
    if (!$cart->items()->exists()) { throw new RuntimeException('Native checkout did not add '.$domain.'.'); }
    $checkout = enomCartCheckout($user, $cart)->assertHasNoErrors();
    $record = Domain::where('reservation_name', strtolower(trim($domain)))->first();
    if (!$record) { throw new RuntimeException('Native cart did not bind '.$domain.'.'); }
    $operation = Operation::where('domain_id', $record->id)->whereIn('action', ['register', 'transfer'])->sole();
    return ['domain' => $record, 'service' => $record->service, 'invoice' => App\Models\Invoice::findOrFail($operation->invoice_id),
        'item' => App\Models\InvoiceItem::findOrFail($operation->invoice_item_id), 'operation' => $operation];
}

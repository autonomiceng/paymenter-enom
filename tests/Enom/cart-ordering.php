<?php
$enomTestLabel = 'cart-ordering';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Models\Service;
use App\Models\CartItem;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\CartOrdering;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;

function cartRefuses(callable $action, string $needle, string $message): void { refuses($action, $needle, $message, [Throwable::class]); }

$user = client('cart-owner@example.com');
$cart = enomCartStart($user);
enomCartAdd($user, $cart, ' FIRST.COM ', 'register', 3);
enomCartAdd($user, $cart, 'second.com');
verify($cart->items()->count() === 2, 'The native product checkout did not add two names.');
verify($cart->items()->first()->checkout_config === ['domain' => 'first.com'], 'Cart config was not canonical.');
$line = $cart->items()->first();
$line->checkout_config = ['domain'=>' FIRST.COM '];
$line->save();
verify($cart->items()->count() === 2, 'Editing a line rejected itself.');
cartRefuses(fn () => $cart->items()->create(['product_id' => $line->product_id, 'plan_id' => $line->plan_id, 'quantity' => 1, 'config_options' => [], 'checkout_config' => ['domain' => 'FIRST.com']]), 'already in your cart', 'Duplicate accepted.');
cartRefuses(fn () => $line->update(['quantity' => 2]), 'one domain', 'Quantity two accepted.');
$line->refresh();
$line->checkout_config = ['domain' => 'first.com', 'auth_code' => 'private', 'contacts' => ['secret'], 'price' => 0];
$line->save();
verify($line->fresh()->checkout_config === ['domain' => 'first.com'], 'Unknown config persisted.');
foreach (['x.com', 'sub.name.com', 'xn--name.com', 'éxample.com', 'wrong.net', '-bad.com', str_repeat('a',64).'.com'] as $name) {
    cartRefuses(fn () => CartOrdering::canonical($name, $com), '', 'Invalid name accepted: '.$name);
}
verify(CartOrdering::canonical('Bare-Name', $com) === 'bare-name.com', 'Bare label did not get its ending.');

// A hosting line may use the same domain; only registrar lines reserve names.
$hosting = App\Models\Product::create(['name'=>'Hosting','slug'=>'cart-hosting','category_id'=>$product->category_id]);
$hostingPlan = $hosting->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_unit'=>'month','billing_period'=>1]);
$hostingPlan->prices()->create(['currency_code'=>'USD','price'=>'20.00','setup_fee'=>'0.00']);
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hostingPlan->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>['domain'=>'first.com']]);
verify($cart->items()->count()===3,'Matching hosting domain was rejected.');
cartRefuses(fn()=> $line->update(['plan_id'=>$hostingPlan->id]), '', 'Another product plan was admitted.');
$line->refresh();
cartRefuses(fn()=> $line->update(['config_options'=>['paid-option'=>1]]), '', 'Paid configurable options were admitted.');
$line->refresh();
$cart->update(['currency_code'=>'EUR']); $line->unsetRelation('cart');
$line->checkout_config = ['domain'=>' FIRST.COM '];
cartRefuses(fn()=> $line->save(), 'USD', 'Non-USD cart was admitted.');
$cart->update(['currency_code'=>'USD']); $line->unsetRelation('cart');
$pricedPlan=$line->plan;
$pricedPlan->prices()->where('currency_code','USD')->delete();
cartRefuses(fn()=> $line->save(), 'no longer available', 'Missing term price was admitted.');
$pricedPlan->prices()->create(['currency_code'=>'USD','price'=>'36.00','setup_fee'=>'0.00']);
$ledger=tableRows(['orders','services','invoices','invoice_items','enom_domains','enom_operations']);
$com->update(['register_enabled'=>false]);
enomCartCheckout($user,$cart);
verify(tableRows(array_keys($ledger))===$ledger,'Disabled action left checkout obligations.');
$com->update(['register_enabled'=>true]);

$availability['first.com'] = 'transferable';
$transferProduct = app(Catalogue::class)->productFor($com, 'transfer');
$transferPlan = $transferProduct->plans()->first();
cartRefuses(fn()=> $cart->items()->create(['product_id'=>$transferProduct->id,'plan_id'=>$transferPlan->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>['domain'=>'first.com']]), 'already in your cart', 'Register and transfer duplicate accepted.');
unset($availability['first.com']);
$availability['maximum.com'] = 'maxfive';
cartRefuses(fn()=> app(CartOrdering::class)->checkName($com,'register','maximum.com',6), 'term', 'Name maximum ignored.');
$availability['premium.com'] = 'premium';
cartRefuses(fn () => app(CartOrdering::class)->checkName($com, 'register', 'premium.com', 1), 'premium', 'Premium name accepted.');
$availability['minimum.com'] = 'twoyear';
cartRefuses(fn () => app(CartOrdering::class)->checkName($com, 'register', 'minimum.com', 1), 'term', 'Name minimum ignored.');
$before = tableRows(['orders','invoices','invoice_items','services','enom_domains','enom_operations']);
$fail = true;
Operation::creating(function ($operation) use (&$fail) { if ($fail && $operation->domain->domain === 'second.com') { throw new RuntimeException('Synthetic binding failure'); } });
enomCartCheckout($user, $cart);
verify(tableRows(array_keys($before)) === $before, 'A second-line binding failure left obligations.');
verify($server->settings()->where('key', 'enom_account_binding')->count() === 0, 'Failed checkout left account binding.');
$fail = false;
enomCartCheckout($user, $cart);
verify(Domain::count() === 2 && Operation::count() === 2, 'Native checkout did not bind both domains.');
$domain = Domain::where('domain','first.com')->sole();
$operation = $domain->operations()->sole();
$service = $domain->service;
verify((int)$operation->term === 3 && $operation->amount === '36.00' && $operation->state === 'awaiting_payment', 'Initial term or amount changed.');
verify(App\Models\InvoiceItem::findOrFail($operation->invoice_item_id)->description === 'Register first.com (3 years)', 'Initial invoice description differs.');
verify($service->label === 'first.com' && (float) $service->price === 14.0 && $service->expires_at === null && (int)$service->plan->billing_period === 1, 'Annual billing identity differs: '.json_encode([$service->label,$service->price,$service->expires_at,$service->plan->billing_period]));
verify((int)$operation->policy['checkout_years'] === 3 && (int)$operation->policy['checkout_plan_id'] !== (int)$service->plan_id, 'Chosen plan was not frozen.');
verify(app(CartOrdering::class)->bind(App\Models\InvoiceItem::findOrFail($operation->invoice_item_id))->id === $operation->id && Operation::count() === 2, 'Binding replay duplicated an operation.');
verify(!Illuminate\Support\Facades\Schema::hasTable('enom_order_intents'), 'Obsolete intent table remains.');
verify(count(array_filter($providerCommands, fn($command) => $command[0] !== 'Check')) === 0, 'Checkout sent a registrar write.');
echo 'Cart ordering: '.$checks.' passed; native product and cart components; SQLite; no provider writes.'.PHP_EOL;
$successful = true;

<?php
// Native multi-year checkout, exact-term wholesale admission, purchased expiry and renewal obligations.
// Disposable native app; injected provider; no real requests.
$enomTestLabel = 'terms';
require dirname(__DIR__).'/enom-native-app.php';

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
require_once dirname(__DIR__).'/enom-cart-order.php';
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
// Per-name Check answers: availability, the name's own minimum term and Enom's per-year prices.
$names = [];
$requests = [];
$purchaseYears = null; // Override the expiry Purchase/GetDomainExp report, in years from today.
$expiries = [];
$wholesale = '8.9500';
$transport = function (string $command, array $params) use (&$names, &$requests, &$purchaseYears, &$expiries, &$wholesale): string {
    $requests[] = [$command, $params];
    $name = strtolower(($params['SLD'] ?? '').'.'.($params['TLD'] ?? ''));
    $answer = ($names[$name] ?? []) + ['available' => true, 'min' => null, 'register' => '8.95', 'renew' => '9.95', 'transfer' => '9.50'];
    if ($command === 'Check') {
        return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.($answer['available'] ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP>'
            .'<Properties><Transferable>'.($answer['available'] ? 'false' : 'true').'</Transferable>'.($answer['min'] !== null ? '<MinRegYear>'.$answer['min'].'</MinRegYear>' : '').'</Properties>'
            .'<Prices><Currency>USD</Currency><Registration>'.$answer['register'].'</Registration><Renewal>'.$answer['renew'].'</Renewal><Transfer>'.$answer['transfer'].'</Transfer></Prices></Domain></Domains>');
    }
    if ($command === 'PE_GetProductPrice') { return enomXml($command, '<productprice><productenabled>True</productenabled><price>'.$wholesale.'</price></productprice>'); }
    if ($command === 'Purchase') {
        $expiries[$name] = now()->addYearsNoOverflow($purchaseYears ?? $params['NumYears'])->format('Y-m-d');
        return enomXml($command, '<RRPCode>200</RRPCode><OrderID>'.(70000 + count($requests)).'</OrderID><DomainInfo><RegistryExpDate>'.$expiries[$name].' 10:00:00.000</RegistryExpDate></DomainInfo>');
    }
    if ($command === 'GetDomainExp') { return enomXml($command, '<ExpirationDate>'.($expiries[$name] ?? '2027-10-14').'</ExpirationDate>'); }
    if ($command === 'GetDomainInfo') {
        return enomXml($command, '<GetDomainInfo><domainname domainnameid="4242">'.$name.'</domainname><status><expiration>'.($expiries[$name] ?? '2027-10-14').'</expiration><belongs-to>offline-account</belongs-to></status></GetDomainInfo>');
    }
    if ($command === 'TP_CreateOrder') { return enomXml($command, '<TransferOrderID>8800</TransferOrderID>'); }
    if (in_array($command, ['GetOrderDetail', 'ModifyNS'], true)) { return enomXml($command, '<RRPCode>200</RRPCode>'); }
    throw new RuntimeException('Unexpected provider command '.$command);
};
$operations = new DomainOperations((new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)))->withSuffixes(['ai']),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, $billing = new DomainBilling($operations));
$orders = new DomainOrders($operations);
$staffDomains = new StaffDomains($operations);

$fundRole = App\Models\Role::create(['name' => 'Domain funder', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$funder = User::create(['email' => 'funder@example.invalid', 'password' => 'synthetic', 'role_id' => $fundRole->id]);
$client = User::create(['email' => 'client@example.invalid', 'password' => 'synthetic']);
$other = User::create(['email' => 'other@example.invalid', 'password' => 'synthetic']);
$free = User::create(['email' => 'free@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Terms Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'billing_enabled' => '1', 'complimentary_cost_cap' => '25.00'] as $key => $value) {
    $server->settings()->create(['key' => $key, 'value' => $value]);
}
$com = enomTestTld(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
$ai = enomTestTld(['server_id' => $server->id, 'tld' => 'ai', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '90.00', 'renew_price' => '80.00', 'transfer_price' => '85.00',
    'min_years' => 2, 'max_years' => 10]);
$product = app(Catalogue::class)->productFor($com, 'register');
$plan = app(Catalogue::class)->billingPlan($product);
enomCartProfile($client);
$contacts = ['registrant' => ['first_name' => 'Term', 'last_name' => 'Client', 'address1' => '1 Long Road', 'city' => 'Termtown', 'postal_code' => '12345',
    'country' => 'US', 'email_address' => 'term.client@example.com', 'phone' => '+1.5551234567']];
function pay(Invoice $invoice): void { ExtensionHelper::addPayment($invoice->id, null, amount: (float) $invoice->fresh()->total, isCreditTransaction: true); }

// Native checkout freezes the selected term while the service bills annually.
verify($com->registrationTotal(3) === '36.00' && $ai->registrationTotal(2) === '180.00', 'Per-year plan totals differ.');
$names['longmin.com'] = ['min' => 3];
verify($orders->check($client, 'longmin.com')['terms'] === range(3,10), 'Name-specific minimum ignored.');
$invoice = enomCartOrder($client, 'paid-years.com', 'register', 3)['invoice'];
$paidOperation = Operation::where('invoice_id', $invoice->id)->sole();
verify($invoice->items->sole()->description === 'Register paid-years.com (3 years)' && (float)$invoice->total === 36.0, 'Invoice initial term differs.');
pay($invoice);
verify($paidOperation->fresh()->state === 'queued', 'Paid three-year registration was not queued: '.json_encode($paidOperation->fresh()->toArray()).' funded='.(int)$operations->funded($paidOperation->fresh()));
$requests = [];
$done = $operations->execute($paidOperation->id);
$purchase = array_values(array_filter($requests, fn ($request) => $request[0] === 'Purchase'));
verify(count($purchase) === 1 && $purchase[0][1]['NumYears'] === 3, 'Purchase did not send three years: '.$done->safe_code);
verify($done->cost['quote']['amount'] === '26.8500' && $done->cost['quote']['source'] === 'PE_GetProductPrice', 'Exact-term preflight price differs.');
verify($done->state === 'succeeded' && $done->domain->fresh()->provider_expires_at->format('Y-m-d') === '2029-10-14', 'Purchased term did not confirm: '.$done->safe_code);
$short = enomCartOrder($client, 'short-term.com', 'register', 3)['invoice'];
pay($short); $purchaseYears = 1; $requests = [];
$shortResult = $operations->execute(Operation::where('invoice_id', $short->id)->sole()->id); $purchaseYears = null;
$shortPurchase = array_values(array_filter($requests, fn ($request) => $request[0] === 'Purchase'));
verify(count($shortPurchase) === 1 && $shortPurchase[0][1]['NumYears'] === 3,
    'Short-term readback did not follow exactly one three-year Purchase.');
verify($shortResult->state === 'uncertain' && $shortResult->safe_code === 'expiry_unconfirmed',
    'Short-term readback returned '.$shortResult->state.'/'.$shortResult->safe_code.'.');
$minLater = enomCartOrder($client, 'min-later.com', 'register', 2)['invoice'];
pay($minLater); $names['min-later.com'] = ['min' => 3]; $requests = [];
$rejected = $operations->execute(Operation::where('invoice_id', $minLater->id)->sole()->id);
verify($rejected->state === 'rejected' && $rejected->safe_code === 'domain_unavailable_or_premium' && array_column($requests,0) === ['Check'], 'New name minimum reached Purchase.');
verify(($rejected->fresh()->cost['refund']['amount'] ?? null) === '24.00', 'Definite registration rejection was not credited.');

// A fraction of a cent above the frozen retail price cannot be rounded away.
$edge = enomCartOrder($client, 'fractional-cost.com')['invoice'];
pay($edge); $wholesale = '12.0001'; $requests = [];
$edgeResult = $operations->execute(Operation::where('invoice_id',$edge->id)->sole()->id);
verify($edgeResult->state === 'held' && $edgeResult->safe_code === 'registrar_cost_unavailable_or_above_price', 'Fractional wholesale overrun reached dispatch.');
verify(!in_array('Purchase',array_column($requests,0),true), 'Fractional wholesale overrun sent Purchase.');
$wholesale = '8.9500';

// ---------------------------------------------------------------- renewals: options, invoices and the open-invoice rule
$active = $done->domain->fresh();
verify($active->billing_managed && DomainBilling::maxRenewalYears($active) === 7, 'Ten-year ceiling for an expiry of 2029-10-14 differs: '.DomainBilling::maxRenewalYears($active));
$options = $orders->renewalOptions($client, $active->id);
verify($options['years'] === range(1, 7) && $options['price_per_year'] === '14.00' && $options['totals'][7] === '98.00' && $options['expires_at'] === '2029-10-14'
    && $options['complimentary'] === false && $options['open_invoice'] === null, 'Renewal options differ: '.json_encode($options));
refuses(fn () => $orders->renewalOptions($other, $active->id), '', 'Another client read renewal options.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
refuses(fn () => $orders->renew($client, $active->id, null, 8), '10-year', 'An eight-year renewal past the ceiling was invoiced.');
$renewal = $orders->renew($client, $active->id, null, 2);
$renewalItem = $renewal->items->sole();
$renewalOperation = Operation::where('invoice_id', $renewal->id)->sole();
verify((float) $renewal->total === 28.0 && $renewalItem->description === 'Renew paid-years.com (2 years)', 'Two-year renewal invoice differs: '.$renewalItem->description);
verify((int) $renewalOperation->term === 2 && $renewalOperation->expected_expires_at->format('Y-m-d') === '2031-10-14' && $renewalOperation->policy['retail_minor'] === 2800, 'Two-year renewal obligation differs.');
$reuseRows = tableRows(['invoices', 'invoice_items', 'enom_operations']);
verify($billing->invoice($active->id)->id === $renewal->id, 'Scheduled billing did not reuse the open multi-year invoice.');
verify(tableRows(['invoices', 'invoice_items', 'enom_operations']) == $reuseRows, 'Scheduled reuse changed the existing obligation.');
$open = $orders->renewalOptions($client, $active->id)['open_invoice'];
verify($open === ['id' => (int) $renewal->id, 'years' => 2, 'url' => route('invoices.show', $renewal)], 'Open renewal invoice not offered: '.json_encode($open));
verify($orders->renew($client, $active->id, null, 2)->id === $renewal->id, 'Same-term renewal did not return the open invoice.');
$before = tableRows(['invoices', 'invoice_items', 'enom_operations']);
refuses(fn () => $orders->renew($client, $active->id, null, 3), 'already exists', 'A different term replaced or duplicated the open renewal invoice.');
verify(tableRows(['invoices', 'invoice_items', 'enom_operations']) == $before, 'Refused renewal term wrote records.');
// Unmanaged domains cannot enter managed renewal billing.
$legacyService = Service::create(['user_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
    'price' => '14.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
$legacy = Domain::create(['service_id' => $legacyService->id, 'user_id' => $client->id, 'server_id' => $server->id,
    'domain' => 'legacy-term.com', 'sld' => 'legacy-term', 'tld' => 'com', 'status' => 'active',
    'provider_expires_at' => '2027-01-01', 'billing_managed' => false, 'capabilities' => ['renew' => true]]);
$legacyOptions = $orders->renewalOptions($client, $legacy->id);
verify($legacyOptions === ['years' => [], 'price_per_year' => '14.00', 'totals' => [], 'expires_at' => '2027-01-01',
    'complimentary' => false, 'open_invoice' => null], 'Unmanaged renewal options differ: '.json_encode($legacyOptions));
foreach ([1, 2] as $years) {
    $beforeUnmanaged = tableRows(['invoices', 'invoice_items', 'enom_operations']);
    refuses(fn () => $orders->renew($client, $legacy->id, null, $years), 'Domain service binding requires review.',
        'Unmanaged domain accepted a '.$years.'-year renewal.', [RuntimeException::class]);
    verify(tableRows(array_keys($beforeUnmanaged)) === $beforeUnmanaged, 'Refused unmanaged renewal wrote billing rows.');
}
// Staff renew-now honours the same ceiling and term.
refuses(fn () => $staffDomains->renewNow($funder, $active->id, 8), '10-year', 'Staff renewed past the ceiling.', [InvalidArgumentException::class]);
verify($staffDomains->renewalYears($active->id) === range(1, 7), 'Staff renewal terms differ.');

// Native cart displays admission errors as notifications rather than field errors.
// A domain claimed after cart admission must refuse checkout without new obligations.
$collisionCart = enomCartStart($client);
enomCartAdd($client, $collisionCart, 'claimed-after-quote.com')->assertHasNoErrors();
$collisionService = $legacyService->replicate(); $collisionService->user_id = $other->id; $collisionService->save();
Domain::create(['service_id' => $collisionService->id, 'user_id' => $other->id, 'server_id' => $server->id,
    'domain' => 'claimed-after-quote.com', 'sld' => 'claimed-after-quote', 'tld' => 'com', 'status' => 'active']);
$beforeCollision = tableRows(['services', 'orders', 'invoices', 'invoice_items', 'enom_operations', 'enom_domains']);
$collisionCheckout = enomCartCheckout($client, $collisionCart);
verify(($collisionCheckout->effects['dispatches'] ?? []) === [['name' => 'notify', 'params' => [[
    'message' => 'claimed-after-quote.com already has an order.', 'type' => 'error']]]],
    'Claimed domain did not return the expected native checkout error.');
verify(tableRows(array_keys($beforeCollision)) === $beforeCollision, 'Claimed domain checkout left new billing rows.');

// Defer only the synchronous binding callback, leaving the native cart to create the service and line.
$app->instance(DomainOperations::class, new class(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]) extends DomainOperations {
    public function bindInvoiceItem(App\Models\InvoiceItem $item): ?Operation { return null; }
});
$bindingCart = enomCartStart($client);
enomCartAdd($client, $bindingCart, 'binding-race.com')->assertHasNoErrors();
enomCartCheckout($client, $bindingCart)->assertHasNoErrors();
$app->instance(DomainOperations::class, $operations);
$bindingInvoice = Invoice::where('user_id', $client->id)->latest('id')->firstOrFail();
$bindingItem = $bindingInvoice->items()->sole();
$bindingFailure = true;
Operation::creating(function (Operation $operation) use ($bindingItem, &$bindingFailure): void {
    if ($operation->operation_key === 'invoice-item:'.$bindingItem->id && $bindingFailure) {
        throw new RuntimeException('Synthetic binding storage failure');
    }
});
$beforeBinding = tableRows(['services', 'orders', 'invoices', 'invoice_items', 'enom_domains', 'enom_operations']);
refuses(fn () => $operations->bindInvoiceItem($bindingItem), 'Synthetic binding storage failure',
    'Unexpected binding storage failure was hidden.', [RuntimeException::class]);
verify(tableRows(array_keys($beforeBinding)) === $beforeBinding, 'Failed binding left domain or billing rows.');
$bindingFailure = false;
// Native binders lock product, ending and service before inserting the unique operation key.
// Complete the winner after the loser's unlocked domain lookup, before it enters those locks.
$race = true;
$winner = null;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$race, &$winner, $operations, $bindingItem): void {
    if (!$race || !str_contains($query->sql, 'from "enom_domains"') || !str_contains($query->sql, '"service_id" = ?')
        || $query->bindings !== [$bindingItem->reference_id]) { return; }
    $race = false;
    $winner = $operations->bindInvoiceItem($bindingItem->fresh());
});
$bound = $operations->bindInvoiceItem($bindingItem);
verify(!$race && $winner !== null && $bound !== null && $bound->id === $winner->id
    && $bound->operation_key === 'invoice-item:'.$bindingItem->id
    && Operation::where('operation_key', $bound->operation_key)->count() === 1
    && Domain::where('service_id', $bindingItem->reference_id)->count() === 1,
    'Serialized native binding lost or duplicated the winning domain or operation.');
$beforeReplay = tableRows(array_keys($beforeBinding));
verify($operations->bindInvoiceItem($bindingItem->fresh())->id === $bound->id
    && tableRows(array_keys($beforeReplay)) === $beforeReplay, 'Binding replay changed the winner or wrote duplicate rows.');

echo 'Registrar terms: '.$checks.' passed; exact-term registration and renewal proofs; SQLite.'.PHP_EOL;
$successful = true;

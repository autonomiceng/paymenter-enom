<?php
// Who pays (standard, custom, complimentary), client grants, complimentary orders and renewals, and the
// standard-price sync before a new invoice. Disposable native app; injected provider; no real requests.
$enomTestLabel = 'pricing';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\CustomProperty;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$checkPrice = ['amount' => '8.95', 'currency' => 'USD'];
$commands = [];
$transport = function (string $command, array $params) use (&$checkPrice, &$commands): string {
    $commands[] = $command;
    $name = ($params['SLD'] ?? 'x').'.'.($params['TLD'] ?? 'com');
    if ($command === 'Check') {
        $prices = $checkPrice['amount'] === null ? '' : '<Prices><Currency>'.$checkPrice['currency'].'</Currency><Registration>'.$checkPrice['amount'].'</Registration><Renewal>'.$checkPrice['amount'].'</Renewal><Transfer>'.$checkPrice['amount'].'</Transfer></Prices>';
        return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>210</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>true</Transferable></Properties>'.$prices.'</Domain></Domains>');
    }
    throw new RuntimeException('Unexpected provider command '.$command);
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$billing = new DomainBilling($operations);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, $billing);
$orders = new DomainOrders($operations);
$staffDomains = new StaffDomains($operations);

$fundRole = App\Models\Role::create(['name' => 'Domain funder', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$manageRole = App\Models\Role::create(['name' => 'Domain manager', 'permissions' => ['admin.enom.manage']]);
$funder = User::create(['email' => 'funder@example.invalid', 'password' => 'synthetic', 'role_id' => $fundRole->id]);
$managerOnly = User::create(['email' => 'manager@example.invalid', 'password' => 'synthetic', 'role_id' => $manageRole->id]);
$payer = User::create(['email' => 'payer@example.invalid', 'password' => 'synthetic']);
$guest = User::create(['email' => 'guest@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Pricing Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'billing_enabled' => '1', 'complimentary_cost_cap' => '10.00'] as $key => $value) {
    $server->settings()->create(['key' => $key, 'value' => $value]);
}
$com = Tld::create(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
Tld::create(['server_id' => $server->id, 'tld' => 'net', 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '15.00', 'renew_price' => '16.00', 'transfer_price' => '15.00']);
$product = app(Catalogue::class)->ensureProduct($server->id);
$plan = $product->plans()->sole();
$contacts = ['registrant' => ['first_name' => 'Comp', 'last_name' => 'Client', 'address1' => '1 Free Road', 'city' => 'Freetown', 'postal_code' => '12345',
    'country' => 'US', 'email_address' => 'comp.client@example.com', 'phone' => '+1.5551234567']];
$counter = 0;
function pricedDomain(User $user, array $domain = [], string $price = '14.00', bool $managed = true): Domain {
    global $counter, $server, $product, $plan;
    $counter++;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
        'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => null]);
    return Domain::create($domain + ['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'priced'.$counter.'.com',
        'sld' => 'priced'.$counter, 'tld' => 'com', 'status' => 'active', 'provider_expires_at' => '2026-12-01', 'provider_domain_id' => '9'.$counter,
        'billing_managed' => $managed, 'authority_generation' => 1, 'confirmed_revision' => 1, 'renewal_policy' => ['enabled' => true]]);
}
function money(): array { return tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']); }

// Grants: a raw user property written by staff with admin.enom.fund; never a client-visible CustomProperty.
refuses(fn () => $staffDomains->setClientComplimentary($managerOnly, $guest, true), 'permission', 'Staff without admin.enom.fund granted complimentary.', [Illuminate\Auth\Access\AuthorizationException::class]);
refuses(fn () => $staffDomains->setClientComplimentary($funder, $guest, true, 0), 'domain limit', 'Zero domain limit accepted.', [InvalidArgumentException::class]);
verify(!Pricing::clientComplimentary($guest) && Pricing::clientGrant(null) === null, 'Client without a grant is complimentary.');
$staffDomains->setClientComplimentary($funder, $guest, true, 2);
$grant = Pricing::clientGrant($guest);
verify($grant['granted_by'] === $funder->id && $grant['max_domains'] === 2 && is_string($grant['granted_at']), 'Grant was not recorded with staff, time and limit.');
$property = $guest->properties()->where('key', Pricing::CLIENT_PROPERTY)->sole();
verify($property->custom_property_id === null, 'Grant was stored against a CustomProperty.');
// A property tied to a CustomProperty (client-submittable) is ignored, and so is any grant once a CustomProperty uses the key.
$unrelated = CustomProperty::create(['key' => 'company_vat', 'name' => 'VAT', 'type' => 'string', 'model' => User::class, 'non_editable' => false, 'required' => false, 'show_on_invoice' => false]);
verify(Pricing::clientComplimentary($guest), 'An unrelated CustomProperty voided the grant.');
$property->update(['custom_property_id' => $unrelated->id]);
verify(!Pricing::clientComplimentary($guest), 'Grant attached to a CustomProperty was honoured.');
$property->update(['custom_property_id' => null]);
$custom = CustomProperty::create(['key' => Pricing::CLIENT_PROPERTY, 'name' => 'Complimentary', 'type' => 'string', 'model' => User::class, 'non_editable' => false, 'required' => false, 'show_on_invoice' => false]);
verify(!Pricing::clientComplimentary($guest), 'Grant honoured while a CustomProperty uses the key (clients could submit it).');
$custom->delete();
verify(Pricing::clientComplimentary($guest), 'Grant not honoured after the CustomProperty was removed.');
$guest->properties()->where('key', Pricing::CLIENT_PROPERTY)->update(['value' => 'not-json']);
verify(!Pricing::clientComplimentary($guest), 'Malformed grant was honoured.');
$guest->properties()->where('key', Pricing::CLIENT_PROPERTY)->update(['value' => '1']);
verify(Pricing::clientGrant($guest)['max_domains'] === null, 'Legacy "1" grant not read as unlimited.');
$staffDomains->setClientComplimentary($funder, $guest, true, 2);

// Mode precedence: explicit domain mode > client grant > standard.
$standardClientDomain = pricedDomain($payer);
verify(Pricing::mode($standardClientDomain) === 'standard' && Pricing::source($standardClientDomain) === 'client', 'Plain client domain is not standard by default.');
$inherits = pricedDomain($guest);
verify(Pricing::mode($inherits) === 'complimentary' && Pricing::renewalPrice($inherits) === '0.00', 'Client grant did not make an inheriting domain complimentary.');
foreach (['standard' => '14.00', 'custom' => '14.00'] as $mode => $price) {
    $inherits->update(['price_mode' => $mode]);
    verify(Pricing::mode($inherits) === $mode && Pricing::source($inherits) === 'domain' && Pricing::renewalPrice($inherits->fresh()) === $price, 'Explicit '.$mode.' did not override the client grant.');
}
$payerDomain = pricedDomain($payer, ['price_mode' => 'complimentary']);
verify(Pricing::mode($payerDomain) === 'complimentary', 'Explicit complimentary did not override a paying client.');
$inherits->update(['price_mode' => 'bogus']);
verify(Pricing::mode($inherits) === 'complimentary', 'Unknown stored mode did not fall back to the client default.');
$inherits->update(['price_mode' => null]);
// Limit: complimentary domains held count toward max_domains; cancelled ones do not.
verify(Pricing::clientHasRoom($guest), 'Client with one of two complimentary domains has no room.');
$second = pricedDomain($guest);
verify(!Pricing::clientHasRoom($guest), 'Client at the limit still has room.');
$paidByGuest = pricedDomain($guest, ['price_mode' => 'standard']);
verify(!Pricing::clientHasRoom($guest), 'Paid domain changed the complimentary count.');
$second->update(['status' => 'cancelled']);
verify(Pricing::clientHasRoom($guest), 'Cancelled domain still counts toward the limit.');
verify(!Pricing::clientHasRoom($payer), 'Client without a grant has room.');

// Domain modes: custom needs a positive price that becomes the service price; standard follows the catalogue; inherit clears.
$custom = pricedDomain($payer, [], '11.00');
foreach (['0', '0.00', '-5', 'abc', null] as $bad) {
    refuses(fn () => Pricing::setDomainMode($custom, 'custom', $bad), 'above 0.00', 'Custom price '.json_encode($bad).' was accepted.', [InvalidArgumentException::class]);
}
refuses(fn () => Pricing::setDomainMode($custom, 'free'), 'Choose who pays', 'Unknown mode was accepted.', [InvalidArgumentException::class]);
verify($custom->fresh()->price_mode === null && $custom->fresh()->service->price == '11.00', 'Refused mode change altered the domain or service.');
Pricing::setDomainMode($custom, 'custom', '19.99');
verify($custom->fresh()->price_mode === 'custom' && $custom->fresh()->service->price == '19.99' && Pricing::renewalPrice($custom->fresh()) === '19.99', 'Custom price not applied to the service.');
Pricing::setDomainMode($custom->fresh(), 'standard');
verify($custom->fresh()->service->price == '14.00', 'Standard mode did not sync the service price to the catalogue renewal price.');
Pricing::setDomainMode($custom->fresh(), 'inherit');
verify($custom->fresh()->price_mode === null && Pricing::source($custom->fresh()) === 'client', 'Inherit did not clear the domain mode.');
// Service price sync applies to billing-managed domains only; legacy native-date domains keep their price.
$legacy = pricedDomain($payer, [], '11.00', false);
Pricing::syncServicePrice($legacy);
verify($legacy->fresh()->service->price == '11.00', 'Legacy unmanaged domain was repriced.');
$managed = pricedDomain($payer, [], '11.00');
Pricing::syncServicePrice($managed);
verify($managed->fresh()->service->price == '14.00', 'Managed standard domain was not synced.');
$orphan = pricedDomain($payer, ['tld' => 'org', 'domain' => 'orphan.org', 'sld' => 'orphan'], '11.00');
Pricing::syncServicePrice($orphan);
verify(Pricing::renewalPrice($orphan) === null && $orphan->fresh()->service->price == '11.00', 'Domain without a catalogue ending lost its price.');
// Complimentary renewal price is free whatever the service says; the cap is read from the server (cents).
verify(Pricing::complimentaryCapMinor($server->id) === 1000, 'Cap not read in cents.');
$server->settings()->where('key', 'complimentary_cost_cap')->update(['value' => 'abc']);
verify(Pricing::complimentaryCapMinor($server->id) === 5000, 'Invalid cap did not fall back to the documented 50.00.');
$server->settings()->where('key', 'complimentary_cost_cap')->update(['value' => '10.00']);

// Complimentary order: $0.00 quote, a paid $0.00 record invoice, no payable invoice, and a capped complimentary request.
$beforeInvoices = Invoice::count();
$commands = [];
$quote = $orders->quote($guest, 'Freebie.com');
verify($quote['complimentary'] === true && $quote['amount'] === '0.00' && $quote['renewal_amount'] === '0.00' && $commands === ['Check'], 'Complimentary quote is not free or sent more than a Check.');
refuses(fn () => $orders->quote($guest, 'freebie.net'), '', 'Disabled .net was quoted.', [InvalidArgumentException::class, RuntimeException::class]);
$domain = $orders->place($guest, $quote['intent_id'], $contacts);
verify($domain instanceof Domain && $domain->domain === 'freebie.com' && $domain->price_mode === null && $domain->billing_managed && $domain->status === 'registration_pending', 'Complimentary order did not return its domain.');
verify($domain->service->price == '0.00' && $domain->service->status === Service::STATUS_PENDING, 'Complimentary service price differs.');
$record = Invoice::where('user_id', $guest->id)->latest('id')->firstOrFail();
verify(Invoice::count() === $beforeInvoices + 1 && $record->status === Invoice::STATUS_PAID && $record->items->count() === 1
    && (float) $record->items->first()->price === 0.0 && str_contains($record->items->first()->description, 'complimentary')
    && $record->items->first()->reference_id === $domain->service_id && $record->transactions()->count() === 0, 'Complimentary order did not get exactly one paid $0.00 record invoice.');
verify(Invoice::where('user_id', $guest->id)->where('status', Invoice::STATUS_PENDING)->doesntExist(), 'Complimentary order created a payable invoice.');
$order = Operation::where('domain_id', $domain->id)->sole();
verify($order->funding_basis === 'complimentary' && $order->state === 'queued' && $order->action === 'register' && $order->amount === '0.00'
    && $order->invoice_id === null && $order->invoice_item_id === null && $order->policy['complimentary_cap_minor'] === 1000 && (int) $order->authorized_by === $guest->id
    && ($order->policy['account_fingerprint'] ?? null) === RuntimeGuard::accountFingerprint(['uid' => 'offline-account']), 'Complimentary request shape differs.');
$jobs = queuedJobs(ExecuteOperation::class);
verify(count($jobs) === 1 && str_contains(json_decode($jobs[0]->payload, true)['data']['command'], 'i:'.$order->id.';'), 'Complimentary request was not queued for the worker exactly once.');
verify($orders->place($guest, $quote['intent_id'], $contacts)->id === $domain->id && Operation::count() === Operation::count() && Invoice::count() === $beforeInvoices + 1, 'Replayed placement created more records.');
// Funding: complimentary while the client is; revoked before dispatch is unfunded; after dispatch the outcome stays recordable.
verify($operations->funded($order), 'Complimentary registration was not funded.');
$staffDomains->setClientComplimentary($funder, $guest, false);
verify(!$operations->funded($order->fresh()) && $domain->fresh()->service->price == '14.00', 'Revoked complimentary registration stayed funded or service stayed free.');
$commands = [];
verify($operations->execute($order->id)->state === 'held' && $commands === [], 'Revoked complimentary registration reached Enom.');
Operation::whereKey($order->id)->update(['state' => 'queued', 'safe_code' => null]);
$staffDomains->setClientComplimentary($funder, $guest, true, 2);
verify($domain->fresh()->service->price == '0.00' && $operations->funded($order->fresh()), 'Re-granted client did not restore the free service and funding.');
$order = $order->fresh(); $order->update(['policy' => $order->policy + ['dispatch_attempted_at' => now()->toIso8601String()]]);
$staffDomains->setClientComplimentary($funder, $guest, false);
verify($operations->funded($order->fresh()), 'Revocation after dispatch stopped the outcome being recorded.');
$policy = $order->fresh()->policy; unset($policy['dispatch_attempted_at']);
$order = $order->fresh(); $order->update(['policy' => $policy]);
$staffDomains->setClientComplimentary($funder, $guest, true, 2);
// The worker holds a complimentary registration whose current Enom price is above the cap, unknown or not USD. No Purchase is sent.
foreach ([['amount' => '10.01', 'currency' => 'USD'], ['amount' => null, 'currency' => 'USD'], ['amount' => '5.00', 'currency' => 'EUR']] as $price) {
    $checkPrice = $price; $commands = [];
    $result = $operations->execute($order->id);
    verify($result->state === 'held' && $result->safe_code === 'complimentary_cost_above_limit' && $commands === ['Check'], 'Complimentary registration was not held for '.json_encode($price).': '.$result->safe_code);
    Operation::whereKey($order->id)->update(['state' => 'queued', 'safe_code' => null, 'started_at' => null, 'lease_expires_at' => null]);
}
$checkPrice = ['amount' => '8.95', 'currency' => 'USD'];
// A quote taken before the client's status changed cannot be placed, in either direction; nothing is written.
$staffDomains->setClientComplimentary($funder, $guest, true, 3); // Room for one more.
$stale = $orders->quote($guest, 'stale-free.com');
verify($stale['complimentary'] === true, 'Fixture expected a complimentary quote.');
$staffDomains->setClientComplimentary($funder, $guest, false);
$before = tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']);
refuses(fn () => $orders->place($guest, $stale['intent_id'], $contacts), 'pricing changed', 'Complimentary quote placed after revocation.');
verify(tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']) == $before, 'Refused placement wrote records.');
$paidQuote = $orders->quote($guest, 'stale-paid.com');
verify($paidQuote['complimentary'] === false && $paidQuote['amount'] === '12.00' && $paidQuote['renewal_amount'] === '14.00', 'Revoked client was not quoted catalogue prices.');
$staffDomains->setClientComplimentary($funder, $guest, true, 3);
$before = tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']);
refuses(fn () => $orders->place($guest, $paidQuote['intent_id'], $contacts), 'pricing changed', 'Paid quote placed after a grant.');
verify(tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']) == $before, 'Refused placement wrote records.');
// Over the domain limit the client pays for the extra domain, now and at renewal.
$staffDomains->setClientComplimentary($funder, $guest, true, 2);
verify(!Pricing::clientHasRoom($guest), 'Fixture expected the client at the two-domain limit.');
$extra = $orders->quote($guest, 'extra-paid.com');
verify($extra['complimentary'] === false && $extra['amount'] === '12.00', 'Client over the limit got a complimentary quote.');
$invoice = $orders->place($guest, $extra['intent_id'], $contacts);
$extraDomain = Domain::where('domain', 'extra-paid.com')->sole();
verify($invoice instanceof Invoice && $invoice->status === Invoice::STATUS_PENDING && (float) $invoice->total === 12.0 && $extraDomain->price_mode === 'standard'
    && Pricing::mode($extraDomain) === 'standard', 'Domain over the limit is not a standard paid order.');

// Scheduled renewal billing. Complimentary: one reseller-paid request per cycle, never an invoice.
Carbon\Carbon::setTestNow('2026-11-10 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$free = pricedDomain($guest, ['price_mode' => 'complimentary'], '0.00');
$money = money();
verify($billing->bill($free->id) === 'complimentary', 'Due complimentary domain was not renewed by the scheduler.');
$renewal = Operation::where('domain_id', $free->id)->sole();
verify($renewal->funding_basis === 'complimentary' && $renewal->state === 'queued' && $renewal->action === 'renew' && $renewal->invoice_id === null
    && $renewal->authorized_by === null && $renewal->base_expires_at->format('Y-m-d') === '2026-12-01' && $renewal->policy['complimentary_cap_minor'] === 1000, 'Scheduled complimentary renewal shape differs.');
$rerun = $billing->bill($free->id);
verify($billing->complimentary($free->id, $funder)->id === $renewal->id && Operation::where('domain_id', $free->id)->count() === 1, 'Complimentary renewal repeated within a cycle.');
knownBug($rerun === 'complimentary', 'complimentary-rerun-health-review', 'DomainBilling::bill() runs assertEligible() (renewal blocker = the queued complimentary renewal itself) before reusing the cycle request, so every later daily run records billing health "review" for a healthy queued renewal.');
verify(money() == $money, 'Complimentary renewal created invoices, items, transactions or credit use.');
$noInvoice = pricedDomain($guest, ['price_mode' => 'complimentary'], '0.00');
refuses(fn () => $billing->invoice($noInvoice->id), 'without an invoice', 'A complimentary domain was invoiced.');
Carbon\Carbon::setTestNow('2026-11-09 12:00:00');
$early = pricedDomain($guest, ['price_mode' => 'complimentary'], '0.00');
verify($billing->bill($early->id) === 'not_due' && $early->operations()->doesntExist(), 'Complimentary renewal ran before the issue date.');
Carbon\Carbon::setTestNow('2026-11-10 12:00:00');
// One renewal per cycle across funding bases: a paid renewal already in this cycle blocks a complimentary one.
$switched = pricedDomain($payer, ['price_mode' => 'standard']);
$paid = $billing->invoice($switched->id);
App\Helpers\ExtensionHelper::addPayment($paid->id, null, amount: 14, isCreditTransaction: true);
$paidRenewal = Operation::where('invoice_id', $paid->id)->sole();
$staffDomains->setPricing($funder, $switched->id, 'complimentary');
verify($paid->fresh()->status === Invoice::STATUS_PAID && $paidRenewal->fresh()->funding_basis === 'paid_invoice', 'Switching to complimentary altered a paid renewal.');
$money = money();
verify($billing->complimentary($switched->id, $funder) === null && Operation::where('domain_id', $switched->id)->count() === 1 && money() == $money, 'A complimentary renewal was added to a cycle that already has a paid one.');
refuses(fn () => $staffDomains->renewNow($funder, $switched->id), 'already has a renewal', 'Staff renew-now added a second renewal to the cycle.');
// Switching to complimentary retires a clean unpaid renewal invoice; partly paid history is kept.
$clean = pricedDomain($payer, ['price_mode' => 'standard']);
$cleanInvoice = $billing->invoice($clean->id);
$partial = pricedDomain($payer, ['price_mode' => 'standard']);
$partialInvoice = $billing->invoice($partial->id);
App\Helpers\ExtensionHelper::addPayment($partialInvoice->id, null, amount: 5, isCreditTransaction: true);
$staffDomains->setPricing($funder, $clean->id, 'complimentary');
$staffDomains->setPricing($funder, $partial->id, 'complimentary');
verify($cleanInvoice->fresh()->status === Invoice::STATUS_CANCELLED && Operation::where('invoice_id', $cleanInvoice->id)->sole()->safe_code === 'renewal_cancelled_unpaid', 'Clean unpaid renewal invoice was not retired.');
verify($partialInvoice->fresh()->status === Invoice::STATUS_PENDING && $partialInvoice->fresh()->transactions()->count() === 1, 'Partly paid renewal history was altered.');
verify($billing->complimentary($clean->id, $funder)?->funding_basis === 'complimentary', 'Retired cycle did not allow the complimentary renewal.');

// Standard mode: a NEW invoice charges the current catalogue renewal price; an issued invoice keeps its amount.
$stalePrice = pricedDomain($payer, ['price_mode' => 'standard'], '11.00');
verify($billing->bill($stalePrice->id) === 'invoiced', 'Standard domain was not invoiced.');
$standardInvoice = Invoice::whereHas('items', fn ($query) => $query->where('description', 'like', '%'.$stalePrice->domain.'%'))->sole();
verify((float) $standardInvoice->total === 14.0 && $stalePrice->fresh()->service->price == '14.00', 'New invoice did not use the current catalogue price.');
$com->update(['renew_price' => '20.00']);
$items = tableRows(['invoice_items']);
verify($billing->invoice($stalePrice->id)->id === $standardInvoice->id && (float) $standardInvoice->fresh()->total === 14.0 && tableRows(['invoice_items']) == $items
    && $stalePrice->fresh()->service->price == '14.00', 'A catalogue change altered an issued invoice or its service price.');
// Custom mode never follows the catalogue.
$customDomain = pricedDomain($payer, ['price_mode' => 'custom'], '9.50');
$billing->bill($customDomain->id);
verify((float) Invoice::whereHas('items', fn ($query) => $query->where('description', 'like', '%'.$customDomain->domain.'%'))->sole()->total === 9.5, 'Custom price followed the catalogue.');
// Disabling an ending stops new orders, but existing domains still renew at its renewal price.
$com->update(['enabled' => false]);
$afterDisable = pricedDomain($payer, ['price_mode' => 'standard'], '11.00');
verify($billing->bill($afterDisable->id) === 'invoiced' && $afterDisable->fresh()->service->price == '20.00', 'Disabled ending stopped renewal billing at its renewal price.');
refuses(fn () => $orders->quote($payer, 'new-after-disable.com'), 'not available', 'Disabled ending accepted a new order.');
$com->update(['enabled' => true]);

// Staff permissions: complimentary needs admin.enom.fund to set, to leave, and to renew now.
$target = pricedDomain($payer);
refuses(fn () => $staffDomains->setPricing($managerOnly, $target->id, 'complimentary'), 'permission', 'Manage-only staff made a domain complimentary.', [Illuminate\Auth\Access\AuthorizationException::class]);
$staffDomains->setPricing($managerOnly, $target->id, 'custom', '30.00');
verify($target->fresh()->service->price == '30.00', 'Manage-only staff could not set a custom price.');
$staffDomains->setPricing($funder, $target->id, 'complimentary');
refuses(fn () => $staffDomains->setPricing($managerOnly, $target->id, 'standard'), 'permission', 'Manage-only staff removed complimentary status.', [Illuminate\Auth\Access\AuthorizationException::class]);
refuses(fn () => $staffDomains->renewNow($managerOnly, $target->id), 'permission', 'Manage-only staff queued a complimentary renewal.', [Illuminate\Auth\Access\AuthorizationException::class]);
verify($staffDomains->renewNow($funder, $target->id) instanceof Operation, 'Funder could not renew a complimentary domain now.');
refuses(fn () => $staffDomains->setPricing($guest, $target->id, 'standard'), 'permission', 'A client changed pricing through staff actions.', [Illuminate\Auth\Access\AuthorizationException::class]);

echo 'Pricing and complimentary: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;

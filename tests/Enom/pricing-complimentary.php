<?php
// Standard and per-domain complimentary renewals, staff authority, and current annual prices. Disposable native app; injected provider; no real requests.
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
require_once dirname(__DIR__).'/enom-cart-order.php';
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
    $server->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => in_array($key, ['uid', 'token'], true)]);
}
$com = enomTestTld(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
enomTestTld(['server_id' => $server->id, 'tld' => 'net', 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '15.00', 'renew_price' => '16.00', 'transfer_price' => '15.00']);
$product = app(Catalogue::class)->productFor($com, 'register');
$plan = app(Catalogue::class)->billingPlan($product);
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

// Account properties cannot grant free ordering or override a domain renewal price.
$guest->properties()->create(['key'=>'enom_complimentary','value'=>'1']);
$standard=pricedDomain($guest);
verify(!Pricing::isComplimentary($standard) && Pricing::renewalPrice($standard)==='14.00','Client property granted complimentary renewal.');
refuses(fn()=> $staffDomains->setPricing($managerOnly,$standard->id,'custom'),'standard or complimentary','Custom pricing remains available.',[InvalidArgumentException::class]);
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
verify($rerun === 'complimentary', 'Staff-free renewal rerun must keep healthy cycle status.');
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
// Disabling an ending stops new orders, but existing domains still renew at its renewal price.
$com->update(['enabled' => false]);
$afterDisable = pricedDomain($payer, ['price_mode' => 'standard'], '11.00');
verify($billing->bill($afterDisable->id) === 'invoiced' && $afterDisable->fresh()->service->price == '20.00', 'Disabled ending stopped renewal billing at its renewal price.');
refuses(fn () => $orders->check($payer, 'new-after-disable.com'), 'not available', 'Disabled ending accepted a new order.');
$com->update(['enabled' => true]);

// Staff permissions: complimentary needs admin.enom.fund to set, to leave, and to renew now.
$target = pricedDomain($payer);
refuses(fn () => $staffDomains->setPricing($managerOnly, $target->id, 'complimentary'), 'permission', 'Manage-only staff made a domain complimentary.', [Illuminate\Auth\Access\AuthorizationException::class]);
$staffDomains->setPricing($managerOnly, $target->id, 'standard');
verify($target->fresh()->service->price == '20.00', 'Standard renewal price differs.');
$staffDomains->setPricing($funder, $target->id, 'complimentary');
refuses(fn () => $staffDomains->setPricing($managerOnly, $target->id, 'standard'), 'permission', 'Manage-only staff removed complimentary status.', [Illuminate\Auth\Access\AuthorizationException::class]);
refuses(fn () => $staffDomains->renewNow($managerOnly, $target->id), 'permission', 'Manage-only staff queued a complimentary renewal.', [Illuminate\Auth\Access\AuthorizationException::class]);
verify($staffDomains->renewNow($funder, $target->id) instanceof Operation, 'Funder could not renew a complimentary domain now.');
refuses(fn () => $staffDomains->setPricing($guest, $target->id, 'standard'), 'permission', 'A client changed pricing through staff actions.', [Illuminate\Auth\Access\AuthorizationException::class]);

echo 'Pricing and complimentary: '.$checks.' passed; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;

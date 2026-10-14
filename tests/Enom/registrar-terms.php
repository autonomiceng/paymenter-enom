<?php
// 0.4 multi-year terms through the native order path: catalogue terms and totals, availability and quotes
// (including a name's own registry minimum), term changes on an open quote, placement re-check, invoice lines,
// operation terms for paid and complimentary orders, execution preflight totals, and renewal options.
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
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
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
$transport = function (string $command, array $params) use (&$names, &$requests, &$purchaseYears, &$expiries): string {
    $requests[] = [$command, $params];
    $name = strtolower(($params['SLD'] ?? '').'.'.($params['TLD'] ?? ''));
    $answer = ($names[$name] ?? []) + ['available' => true, 'min' => null, 'register' => '8.95', 'renew' => '9.95', 'transfer' => '9.50'];
    if ($command === 'Check') {
        return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.($answer['available'] ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP>'
            .'<Properties><Transferable>'.($answer['available'] ? 'false' : 'true').'</Transferable>'.($answer['min'] !== null ? '<MinRegYear>'.$answer['min'].'</MinRegYear>' : '').'</Properties>'
            .'<Prices><Currency>USD</Currency><Registration>'.$answer['register'].'</Registration><Renewal>'.$answer['renew'].'</Renewal><Transfer>'.$answer['transfer'].'</Transfer></Prices></Domain></Domains>');
    }
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
$com = Tld::create(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
$ai = Tld::create(['server_id' => $server->id, 'tld' => 'ai', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '90.00', 'renew_price' => '80.00', 'transfer_price' => '85.00',
    'min_years' => 2, 'max_years' => 10]);
$product = app(Catalogue::class)->ensureProduct($server->id);
$plan = $product->plans()->sole();
$contacts = ['registrant' => ['first_name' => 'Term', 'last_name' => 'Client', 'address1' => '1 Long Road', 'city' => 'Termtown', 'postal_code' => '12345',
    'country' => 'US', 'email_address' => 'term.client@example.com', 'phone' => '+1.5551234567']];
function pay(Invoice $invoice): void { ExtensionHelper::addPayment($invoice->id, null, amount: (float) $invoice->fresh()->total, isCreditTransaction: true); }

// ---------------------------------------------------------------- catalogue terms and totals
verify($com->terms() === range(1, 10) && $ai->terms() === range(2, 10), 'Ending term ranges differ.');
verify($com->registrationTotal(1) === '12.00' && $com->registrationTotal(3) === '40.00' && $com->registrationTotal(10) === '138.00', 'First year at registration, later years at renewal price differs.');
verify($ai->registrationTotal(1) === null && $ai->registrationTotal(2) === '170.00' && $com->registrationTotal(11) === null && $com->registrationTotal(0) === null, 'Out-of-range term was priced.');
foreach ([[null, null, range(1, 10)], [0, 0, range(1, 10)], [3, 2, [3]], [1, 15, range(1, 10)], [12, null, [10]]] as [$min, $max, $expected]) {
    verify((new Tld(['min_years' => $min, 'max_years' => $max]))->terms() === $expected, 'Terms for min '.json_encode($min).' max '.json_encode($max).' differ.');
}
$odd = new Tld(['pricing_mode' => 'fixed', 'register_price' => '9.99', 'renew_price' => '10.01', 'transfer_price' => '1.00']);
verify($odd->registrationTotal(10) === '100.08' && (new Tld(['pricing_mode' => 'fixed', 'register_price' => '9.99']))->registrationTotal(2) === null, 'Cent arithmetic or missing renewal price differs.');

// ---------------------------------------------------------------- check(): guests, clients, the name's own minimum
$guest = $orders->check(null, 'Open-Name.com');
verify($guest['available'] && $guest['terms'] === range(1, 10) && $guest['register_totals'][3] === '40.00' && $guest['taxed'] === false
    && $guest['complimentary'] === false && $guest['existing'] === false && $guest['register_price'] === '12.00', 'Guest check differs: '.json_encode($guest));
$names['longmin.com'] = ['min' => 3];
$long = $orders->check($client, 'longmin.com');
verify($long['available'] && $long['terms'] === range(3, 10) && array_keys($long['register_totals']) === range(3, 10), 'Name minimum did not raise the first term.');
verify($orders->check($client, 'pricey.ai')['terms'] === range(2, 10), 'Ending minimum ignored.');
$names['toolong.com'] = ['min' => 11];
$tooLong = $orders->check($client, 'toolong.com');
verify(!$tooLong['available'] && $tooLong['terms'] === [], 'A name needing more than 10 years was offered.');
$staffDomains->setClientComplimentary($funder, $free, true, 5);
$freeCheck = $orders->check($free, 'gift-name.com');
verify($freeCheck['complimentary'] && $freeCheck['register_totals'][5] === '0.00' && $freeCheck['register_price'] === '0.00', 'Complimentary check shows prices.');
verify($orders->check(null, 'gift-name.com')['complimentary'] === false && $orders->check(null, 'gift-name.com')['register_price'] === '12.00', 'Guest saw another client\'s complimentary prices.');

// ---------------------------------------------------------------- quote(), changeTerm() and place()
$quote = $orders->quote($client, 'three-years.com', 'register', 3);
verify($quote['term'] === 3 && $quote['years'] === 3 && $quote['amount'] === '40.00' && $quote['renewal_amount'] === '14.00' && $quote['terms'] === range(1, 10)
    && $quote['totals'][3] === '40.00' && $quote['totals'][1] === '12.00' && $quote['new_expiry_estimate'] === '2029-10-14', 'Three-year quote differs: '.json_encode($quote));
$names['quote-min.com'] = ['min' => 2];
refuses(fn () => $orders->quote($client, 'quote-min.com', 'register', 1), 'unavailable', 'A term below the name\'s minimum was quoted.');
refuses(fn () => $orders->quote($client, 'pricey.ai', 'register', 1), 'unavailable', 'A term below the ending\'s minimum was quoted.');
refuses(fn () => $orders->quote($client, 'eleven.com', 'register', 11), 'unavailable', 'An 11-year term was quoted.');
$aiQuote = $orders->quote($client, 'pricey.ai', 'register', 2);
verify($aiQuote['amount'] === '170.00' && $aiQuote['terms'] === range(2, 10) && $aiQuote['min_years'] === 2, '.ai two-year quote differs.');
$names['move-me.com'] = ['available' => false];
$transferQuote = $orders->quote($client, 'move-me.com', 'transfer', 5);
verify($transferQuote['term'] === 1 && $transferQuote['amount'] === '13.00' && $transferQuote['terms'] === [1] && $transferQuote['totals'] === [], 'Transfer was not forced to one year at the transfer price.');
// changeTerm: same intent, catalogue price, same expiry; only listed terms; register only; owner only; open only.
$intent = OrderIntent::findOrFail($quote['intent_id']);
$expiresAt = $intent->expires_at->toIso8601String();
Carbon\Carbon::setTestNow(now()->addMinutes(5));
$five = $orders->changeTerm($client, $quote['intent_id'], 5);
verify($five['intent_id'] === $quote['intent_id'] && $five['term'] === 5 && $five['amount'] === '68.00' && $five['expires_at'] === $expiresAt
    && OrderIntent::findOrFail($quote['intent_id'])->expires_at->toIso8601String() === $expiresAt, 'Term change did not reprice the same intent and keep its expiry.');
$longQuote = $orders->quote($client, 'longmin.com', 'register', 3);
$before = tableRows(['enom_order_intents']);
refuses(fn () => $orders->changeTerm($client, $quote['intent_id'], 11), 'not available', 'Term 11 accepted.');
refuses(fn () => $orders->changeTerm($client, $quote['intent_id'], 0), 'not available', 'Term 0 accepted.');
refuses(fn () => $orders->changeTerm($client, $transferQuote['intent_id'], 2), 'not available', 'Transfer term changed.');
refuses(fn () => $orders->changeTerm($client, $longQuote['intent_id'], 2), 'not available', 'Term below the name minimum accepted on change.');
refuses(fn () => $orders->changeTerm($other, $quote['intent_id'], 2), '', 'Another client changed the quote.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
verify(tableRows(['enom_order_intents']) == $before, 'Refused term changes wrote the intent.');
$stale = $orders->quote($client, 'stale-term.com', 'register', 2);
Carbon\Carbon::setTestNow(now()->addMinutes(31));
refuses(fn () => $orders->changeTerm($client, $stale['intent_id'], 3), 'expired', 'Expired quote changed term.');
Carbon\Carbon::setTestNow('2026-10-14 12:00:00');

// place(): the total is re-checked against the catalogue for the frozen term.
$repriced = $orders->quote($client, 'repriced.com', 'register', 4);
$com->update(['renew_price' => '15.00']);
$before = tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']);
refuses(fn () => $orders->place($client, $repriced['intent_id'], $contacts), 'pricing changed', 'A stale multi-year total was placed.');
verify(tableRows(['invoices', 'services', 'enom_domains', 'enom_operations']) == $before, 'Refused placement wrote records.');
$com->update(['renew_price' => '14.00']);
$paidQuote = $orders->quote($client, 'paid-years.com', 'register', 3);
$invoice = $orders->place($client, $paidQuote['intent_id'], $contacts);
$item = $invoice->items->sole();
$paidOperation = Operation::where('invoice_id', $invoice->id)->sole();
verify($item->description === 'Register paid-years.com (3 years)' && (float) $item->price === 40.0 && (float) $invoice->total === 40.0, 'Invoice line differs: '.$item->description);
verify((int) $paidOperation->term === 3 && $paidOperation->amount === '40.00' && $paidOperation->policy['term'] === 3 && $paidOperation->state === 'awaiting_payment', 'Paid operation term differs.');
$oneYear = $orders->place($client, $orders->quote($client, 'one-year.com')['intent_id'], $contacts);
verify($oneYear->items->sole()->description === 'Register one-year.com (1 year)', 'Singular year label differs.');
$transferInvoice = $orders->place($client, $transferQuote['intent_id'], $contacts, 'EPP-CODE-1');
verify($transferInvoice->items->sole()->description === 'Transfer move-me.com (adds 1 year)' && (int) Operation::where('invoice_id', $transferInvoice->id)->sole()->term === 1, 'Transfer line or term differs.');
// Complimentary: the term is frozen on the request, its policy and the $0.00 record line.
$giftQuote = $orders->quote($free, 'gift-years.com', 'register', 4);
verify($giftQuote['amount'] === '0.00' && $giftQuote['totals'] === [], 'Complimentary quote shows totals.');
$giftDomain = $orders->place($free, $giftQuote['intent_id'], $contacts);
$gift = Operation::where('domain_id', $giftDomain->id)->sole();
verify((int) $gift->term === 4 && $gift->policy['term'] === 4 && $gift->funding_basis === 'complimentary' && $gift->policy['complimentary_cap_minor'] === 2500, 'Complimentary operation term differs.');
verify(Invoice::where('user_id', $free->id)->latest('id')->first()->items->sole()->description === 'Register gift-years.com (4 years) — complimentary', 'Complimentary record line differs.');

// ---------------------------------------------------------------- execution: Purchase NumYears and total preflight
pay($invoice);
verify($paidOperation->fresh()->state === 'queued', 'Paid three-year registration was not queued.');
$requests = [];
$done = $operations->execute($paidOperation->id);
$purchase = array_values(array_filter($requests, fn ($request) => $request[0] === 'Purchase'));
verify(count($purchase) === 1 && $purchase[0][1]['NumYears'] === 3, 'Purchase did not send NumYears=3.');
verify($done->cost['quote']['amount'] === '28.85' && $done->cost['quote']['term'] === 3, 'Preflight did not bind the three-year cost (8.95 + 2 x 9.95): '.json_encode($done->cost['quote'] ?? null));
verify($done->state === 'succeeded' && $done->domain->fresh()->provider_expires_at->format('Y-m-d') === '2029-10-14', 'Three-year registration did not confirm three years: '.$done->state.' '.$done->safe_code);
// A registration that Enom recorded for fewer years than paid must not confirm.
$short = $orders->place($client, $orders->quote($client, 'short-term.com', 'register', 3)['intent_id'], $contacts);
pay($short);
$purchaseYears = 1;
$shortResult = $operations->execute(Operation::where('invoice_id', $short->id)->sole()->id);
$purchaseYears = null;
verify($shortResult->state !== 'succeeded', 'registration-term-unverified: A paid 3-year registration whose Purchase and GetDomainExp both report one year still succeeds: DomainOperations::bindInvoiceItem() (Enom/Services/DomainOperations.php:100) sets expected_expires_at from provider_expires_at, which is null for a new registration, and the complimentary path in DomainOrders::place() sets none, so the readback check at DomainOperations.php:428 is skipped and the term is never verified.');
// Complimentary: the total for all years is compared with the cap (8.95 + 3 x 9.95 = 38.80 > 25.00).
$requests = [];
$held = $operations->execute($gift->id);
verify($held->state === 'held' && $held->safe_code === 'complimentary_cost_above_limit' && array_column($requests, 0) === ['Check'], 'Four-year complimentary order was not held on its total: '.$held->safe_code);
Operation::whereKey($gift->id)->update(['state' => 'queued', 'safe_code' => null, 'started_at' => null, 'lease_expires_at' => null]);
$names['gift-years.com'] = ['register' => '4.00', 'renew' => '5.00'];
$requests = [];
verify($operations->execute($gift->id)->state === 'succeeded' && array_values(array_filter($requests, fn ($r) => $r[0] === 'Purchase'))[0][1]['NumYears'] === 4, 'Four-year complimentary order under the cap (19.00) did not register four years.');
// The name's minimum at execution time is checked against the frozen term: a rejected paid request is credited back.
$minLater = $orders->place($client, $orders->quote($client, 'min-later.com', 'register', 2)['intent_id'], $contacts);
pay($minLater);
$names['min-later.com'] = ['min' => 3];
$requests = [];
$rejected = $operations->execute(Operation::where('invoice_id', $minLater->id)->sole()->id);
verify($rejected->state === 'rejected' && $rejected->safe_code === 'domain_unavailable_or_premium' && array_column($requests, 0) === ['Check'], 'A term below the name\'s current minimum reached Purchase.');
verify(($rejected->fresh()->cost['refund']['amount'] ?? null) === '26.00', 'Definitely rejected paid registration was not credited.');

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
verify((float) $renewal->total === 28.0 && $renewalItem->description === 'Renew paid-years.com (2 years; expires 2029-10-14)', 'Two-year renewal invoice differs: '.$renewalItem->description);
verify((int) $renewalOperation->term === 2 && $renewalOperation->expected_expires_at->format('Y-m-d') === '2031-10-14' && $renewalOperation->policy['retail_minor'] === 2800, 'Two-year renewal obligation differs.');
$open = $orders->renewalOptions($client, $active->id)['open_invoice'];
verify($open === ['id' => (int) $renewal->id, 'years' => 2, 'url' => route('invoices.show', $renewal)], 'Open renewal invoice not offered: '.json_encode($open));
verify($orders->renew($client, $active->id, null, 2)->id === $renewal->id, 'Same-term renewal did not return the open invoice.');
$before = tableRows(['invoices', 'invoice_items', 'enom_operations']);
refuses(fn () => $orders->renew($client, $active->id, null, 3), 'already exists', 'A different term replaced or duplicated the open renewal invoice.');
verify(tableRows(['invoices', 'invoice_items', 'enom_operations']) == $before, 'Refused renewal term wrote records.');
// Unmanaged (legacy native-date) domains renew one year at a time.
$legacyService = Service::create(['user_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '14.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
$legacy = Domain::create(['service_id' => $legacyService->id, 'user_id' => $client->id, 'server_id' => $server->id, 'domain' => 'legacy-term.com', 'sld' => 'legacy-term', 'tld' => 'com',
    'status' => 'active', 'provider_expires_at' => '2027-01-01', 'billing_managed' => false, 'capabilities' => ['renew' => true]]);
verify($orders->renewalOptions($client, $legacy->id)['years'] === [1], 'Unmanaged domain offered multi-year renewal.');
refuses(fn () => $orders->renew($client, $legacy->id, null, 2), 'one year at a time', 'Unmanaged domain accepted a two-year renewal.');
// Staff renew-now honours the same ceiling and term.
refuses(fn () => $staffDomains->renewNow($funder, $active->id, 8), '10-year', 'Staff renewed past the ceiling.', [InvalidArgumentException::class]);
verify($staffDomains->renewalYears($active->id) === range(1, 7), 'Staff renewal terms differ.');

echo 'Registrar terms: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;

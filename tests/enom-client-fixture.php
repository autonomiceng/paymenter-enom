<?php
/**
 * Client-area fixture shared by the UI suite: one Enom server with an injected transport, a catalogue
 * (.com and .net for sale, .org imported but disabled), the hidden native product, clients with native
 * profile properties, and a domain factory. Include after enom-native-app.php and enom-http.php.
 *
 * $availability maps a full name to the Check answer: available (default), taken, transferable, premium, twoyear
 * (available but with a two-year minimum first term) or error.
 * Every provider command is recorded in $providerCommands; anything other than Check refuses.
 */
use App\Models\Service;
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
require_once __DIR__.'/enom-cart-order.php';

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
config(['settings.mail_must_verify' => false]);
$availability = [];
$providerCommands = [];
$transport = function (string $command, array $params) use (&$availability, &$providerCommands): string {
    $providerCommands[] = [$command, $params];
    if ($command !== 'Check') { throw new RuntimeException('Unexpected provider command '.$command); }
    $name = strtolower(($params['SLD'] ?? '').'.'.($params['TLD'] ?? ''));
    $state = $availability[$name] ?? 'available';
    if ($state === 'error') {
        return '<interface-response><Command>CHECK</Command><ErrCount>1</ErrCount><errors><Err1>Provider internal detail 7f3a</Err1></errors><Done>true</Done></interface-response>';
    }
    $premium = $state === 'premium' ? 'true' : 'false';
    return enomXml('Check', '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.(in_array($state, ['available', 'premium', 'twoyear', 'maxfive'], true) ? '210' : '211').'</RRPCode>'
        .'<IsPremium>'.$premium.'</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>'.($state === 'transferable' ? 'true' : 'false').'</Transferable>'
        .($state === 'twoyear' ? '<MinRegYear>2</MinRegYear>' : '').($state === 'maxfive' ? '<MaxRegYear>5</MaxRegYear>' : '').'</Properties>'
        .'<Prices><Currency>USD</Currency><Registration>4.44</Registration><Renewal>4.44</Renewal><Transfer>4.44</Transfer></Prices></Domain></Domains>');
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true, 'environment' => 'test']);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, new DomainBilling($operations));

$server = App\Models\Server::create(['name' => 'Client Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test', 'operations_enabled' => '1', 'billing_enabled' => '1',
    'default_nameservers' => "dns1.registrar-servers.com\ndns2.registrar-servers.com", 'dns_tools' => '0', 'privacy_price' => '5.00'] as $key => $value) {
    $server->settings()->create(['key' => $key, 'value' => $value]);
}
/** Change one server setting (and drop cached relations). */
function serverSetting(string $key, ?string $value): void {
    global $server;
    if ($value === null) { $server->settings()->where('key', $key)->delete(); }
    else { $server->settings()->updateOrCreate(['key' => $key], ['value' => $value]); }
    $server->unsetRelation('settings');
}
// Retail prices, plus Enom costs that must never reach a client.
enomTestTld(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00',
    'cost_register' => '8.91', 'cost_renew' => '8.92', 'cost_transfer' => '8.93', 'sort_order' => 1]);
enomTestTld(['server_id' => $server->id, 'tld' => 'net', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '15.00', 'renew_price' => '16.00', 'transfer_price' => '15.50',
    'cost_register' => '9.81', 'cost_renew' => '9.82', 'cost_transfer' => '9.83', 'sort_order' => 2]);
enomTestTld(['server_id' => $server->id, 'tld' => 'org', 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '11.00', 'renew_price' => '11.00', 'transfer_price' => '11.00', 'sort_order' => 3]);
const ENOM_COST_MARKERS = ['8.91', '8.92', '8.93', '9.81', '9.82', '9.83', '4.44'];
$com = Tld::where('tld', 'com')->sole();
$product = app(Catalogue::class)->productFor($com, 'register');
$plan = app(Catalogue::class)->billingPlan($product);

/** A client with a native profile, as the order page prefills it. */
function client(string $email, array $profile = []): User {
    $user = User::create(['email' => $email, 'password' => 'synthetic', 'first_name' => $profile['first_name'] ?? 'Client', 'last_name' => $profile['last_name'] ?? 'Owner', 'email_verified_at' => now()]);
    foreach (['phone' => '+1 206 555 0100', 'company_name' => '', 'address' => '1 Main Street', 'address2' => '', 'city' => 'Seattle', 'state' => 'WA', 'zip' => '98101', 'country' => 'US'] as $key => $default) {
        $value = array_key_exists($key, $profile) ? $profile[$key] : $default;
        if ($value !== '' && $value !== null) { $user->properties()->create(['key' => $key, 'name' => ucfirst($key), 'value' => $value]); }
    }
    return $user;
}
$baseContact = ['first_name' => 'Client', 'last_name' => 'Owner', 'organization_name' => '', 'address1' => '1 Main Street', 'address2' => '', 'city' => 'Seattle',
    'state_province' => 'WA', 'postal_code' => '98101', 'country' => 'US', 'email_address' => 'client.owner@example.com', 'phone' => '+1.2065550100'];
$domainCounter = 0;
/** An owned domain with a cached registrar snapshot. $attributes override the Domain row; $snapshot overrides cached facts. */
function clientDomain(User $user, array $attributes = [], array $snapshot = [], string $price = '14.00'): Domain {
    global $domainCounter, $server, $product, $plan, $baseContact;
    $domainCounter++;
    $name = $attributes['domain'] ?? 'client'.$domainCounter.'.com';
    [$sld, $tld] = explode('.', $name, 2);
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
        'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => null]);
    $contacts = ['registrant' => $baseContact, 'admin' => $baseContact, 'tech' => $baseContact, 'auxbilling' => $baseContact];
    return Domain::create($attributes + ['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => $name, 'sld' => $sld, 'tld' => $tld,
        'status' => 'active', 'provider_expires_at' => '2026-12-01', 'provider_domain_id' => '9'.$domainCounter, 'provider_order_id' => '7'.$domainCounter,
        'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1, 'renewal_policy' => ['enabled' => true], 'last_synced_at' => '2026-10-14 08:30:00',
        'snapshot' => $snapshot + ['nameservers' => ['ns1.example-host.com', 'ns2.example-host.com'], 'contacts' => $contacts, 'locked' => true,
            'privacy' => ['allowed' => true, 'exists' => false, 'enabled' => false]],
        'capabilities' => ['nameservers' => true, 'contacts' => true, 'lock' => true, 'epp_sync' => true, 'renew' => true, 'privacy_purchase' => true,
            'hosts' => true, 'srv' => true, 'dnssec_add' => true, 'dnssec_delete' => true]]);
}
/** Business rows that every refused or redirect-only request must leave unchanged. */
function clientRows(): array {
    return tableRows(['enom_domains', 'enom_operations', 'services', 'orders', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs']);
}

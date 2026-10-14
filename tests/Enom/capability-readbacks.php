<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock models, in-memory database, provider simulator only. No installed app or network.
require __DIR__.'/reconciliation-rejection.php';
$successful = false;
$checks = 0;
require_once $testRoot.'/app/Classes/helpers.php';
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
$app['view']->addLocation($release.'/themes/default/views');
app('router')->getRoutes()->refreshNameLookups();
function domainPage($domain, $user): string {
    Illuminate\Support\Facades\Auth::login($user);
    $manager = app(Paymenter\Extensions\Servers\Enom\Services\DomainManager::class);
    $snapshot = $manager->snapshot($user, $domain->id);
    $keys = array_fill_keys([...$manager::ACTIONS, 'refresh', 'renew', 'privacy_purchase', 'privacy_renew', 'renewal_preference'], str_repeat('a', 64));
    return view('enom::show', ['snapshot' => $snapshot, 'keys' => $keys])->render();
}
$user->update(['email' => 'demo@example.com']);
$product->settings()->create(['key' => 'enom_privacy_price', 'value' => '5.00']);
[$domain, $seed] = fixture('register');
$domain->update(['domain' => 'demo-domain-0000000000000099.com', 'sld' => 'demo-domain-0000000000000099', 'status' => 'active', 'provider_order_id' => '12345', 'provider_expires_at' => now()->addYear()->format('Y-m-d')]);
App\Models\Service::withoutEvents(fn () => $domain->service->update(['status' => 'active']));
$seed->update(['state' => 'rejected']); // Synthetic initial ledger is already disposed; no real operation is changed.
$providerDs = [];
$privacyExists = false;
$privacyEnabled = false;
$privacyExpiry = now()->addYear()->format('Y-m-d');
$privacyReadFails = false;
$dnssecReadFails = false;
$commands = [];
$ds = ['algorithm' => 13, 'digest_type' => 2, 'digest' => str_repeat('A', 64), 'key_tag' => 12345];
$client = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline', 'offline', function ($command) use (&$providerDs, &$privacyExists, &$privacyEnabled, &$privacyExpiry, &$privacyReadFails, &$dnssecReadFails, &$commands, $ds, $domain): string {
    $commands[] = $command;
    if (($command === 'GetDnsSec' && $dnssecReadFails) || ($command === 'GetWPPSInfo' && $privacyReadFails)) { return '<invalid'; }
    if ($command === 'AddDnsSec') { $providerDs = [$ds]; }
    if ($command === 'DeleteDnsSec') { $providerDs = []; }
    if ($command === 'PurchaseServices') { $privacyExists = true; }
    if ($command === 'RenewServices') { $privacyExpiry = (new DateTimeImmutable($privacyExpiry))->modify('+1 year')->format('Y-m-d'); }
    if ($command === 'EnableServices') { $privacyEnabled = true; }
    if ($command === 'DisableServices') { $privacyEnabled = false; }
    $body = match ($command) {
        'GetDnsSec' => '<Success>True</Success><DnsSecDataCount>'.count($providerDs).'</DnsSecDataCount><DnsSecData>'.($providerDs ? '<KeyData><Algorithm>13</Algorithm><Digest>'.str_repeat('A', 64).'</Digest><DigestType>2</DigestType><KeyTag>12345</KeyTag></KeyData>' : '').'<Result><ResponseCode>200</ResponseCode></Result></DnsSecData>',
        'GetWPPSInfo' => '<GetWPPSInfo><WPPSAllowed>True</WPPSAllowed><WPPSExists>'.($privacyExists ? 'True' : 'False').'</WPPSExists><WPPSEnabled>'.($privacyEnabled ? 'True' : 'False').'</WPPSEnabled><WPPSExpDate>'.$privacyExpiry.'</WPPSExpDate></GetWPPSInfo>',
        'PurchaseServices', 'RenewServices' => '<OrderID>123456</OrderID><RRPCode>200</RRPCode>',
        'GetOrderDetail' => '<Order><Result>True</Result><OrderID>123456</OrderID><OrderDetail><Description>'.$domain->domain.'</Description><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus></OrderDetail></Order>',
        default => '<RRPCode>200</RRPCode>',
    };
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
});
$operations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($client), fn () => []);
$app->instance(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, $operations);
$operations->refreshSnapshot($user, $domain->id);
verify($domain->fresh()->capabilities['dnssec_add'] && !$domain->fresh()->capabilities['dnssec_delete'], 'Verified empty fixture does not allow controlled add only.');
$add = $operations->enqueueManagement($user, $domain->id, 'dnssec_add', ['confirmed' => true, 'ds' => $ds], hash('sha256', 'add-first-ds'));
$commands = [];
verify($operations->execute($add->id)->state === 'succeeded', 'First controlled DS add failed its provider readback.');
verify($domain->fresh()->capabilities['dnssec_delete'] === true && count($domain->fresh()->snapshot['dnssec']) === 1, 'Confirmed DS add cached the record but left removal unavailable.');
verify(str_contains(domainPage($domain, $user), 'Remove DS record'), 'Confirmed DS add does not render its removal form.');
verify($commands === ['AddDnsSec', 'GetDnsSec'], 'DS capability update added provider requests.');
$delete = $operations->enqueueManagement($user, $domain->id, 'dnssec_delete', ['confirmed' => true, 'ds' => $ds], hash('sha256', 'remove-last-ds'));
$commands = [];
verify($operations->execute($delete->id)->state === 'succeeded' && !$domain->fresh()->capabilities['dnssec_delete'] && $domain->fresh()->capabilities['dnssec_add'], 'Deleting the last DS left stale removal capability.');
verify(!str_contains(domainPage($domain, $user), 'Remove DS record'), 'Verified empty DNSSEC still renders removal.');
verify($commands === ['DeleteDnsSec', 'GetDnsSec'], 'DS delete capability update added provider requests.');
// Read-only recovery must update capabilities from the same verified snapshot without another add.
$providerDs = [$ds];
$recovery = $operations->enqueueManagement($user, $domain->id, 'dnssec_add', ['confirmed' => true, 'ds' => $ds], hash('sha256', 'recover-ds-add'));
$recovery->update(['state' => 'uncertain']);
$commands = [];
verify($operations->reconcile($recovery->id)->state === 'succeeded' && $domain->fresh()->capabilities['dnssec_delete'], 'Reconciled DS add retained the empty-list capability.');
verify($commands === ['GetDnsSec'], 'DS reconciliation added a mutation or a full refresh.');
$delete = $operations->enqueueManagement($user, $domain->id, 'dnssec_delete', ['confirmed' => true, 'ds' => $ds], hash('sha256', 'clear-reconciled-ds'));
$operations->execute($delete->id);
// Failed verified readback does not create a delete capability from an acknowledgement.
$failedAdd = $operations->enqueueManagement($user, $domain->id, 'dnssec_add', ['confirmed' => true, 'ds' => $ds], hash('sha256', 'unknown-ds-readback'));
$dnssecReadFails = true;
verify($operations->execute($failedAdd->id)->state === 'uncertain' && !$domain->fresh()->capabilities['dnssec_delete'], 'Unverified DS acknowledgement enabled removal.');
$failedAdd->update(['state' => 'rejected']); // Simulator knows the outcome; dispose fixture before independent privacy tests.
$dnssecReadFails = false;
$orders = new Paymenter\Extensions\Servers\Enom\Services\DomainOrders($operations);
$invoice = $orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'purchase-privacy-capabilities'));
App\Helpers\ExtensionHelper::addPayment($invoice->id, null, amount: 5, isCreditTransaction: true);
$purchase = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice->id)->firstOrFail();
$commands = [];
verify($operations->execute($purchase->id)->state === 'succeeded', 'Paid privacy purchase failed its expiry readback.');
$capabilities = $domain->fresh()->capabilities;
verify(!$capabilities['privacy_purchase'] && $capabilities['privacy_renew'] && $capabilities['privacy_enable'] && $capabilities['privacy_disable'], 'Verified privacy purchase left purchase visible or management unavailable.');
$html = domainPage($domain, $user);
verify(!str_contains($html, 'Buy contact privacy, 1 year') && str_contains($html, 'Renew contact privacy, 1 year') && str_contains($html, 'Turn on privacy'), 'Privacy forms do not match the confirmed purchase capabilities.');
verify($commands === ['GetWPPSInfo', 'PurchaseServices', 'GetWPPSInfo'], 'Privacy capability update added a full refresh.');
// Simulate a confirmed provider purchase whose persistence needed read-only recovery.
$purchase->update(['state' => 'uncertain', 'completed_at' => null]);
$domain = $domain->fresh();
$domain->update(['capabilities' => array_merge($domain->capabilities, ['privacy_purchase' => true, 'privacy_renew' => false, 'privacy_enable' => false, 'privacy_disable' => false])]);
$commands = [];
verify($operations->reconcile($purchase->id)->state === 'succeeded' && !$domain->fresh()->capabilities['privacy_purchase'] && $domain->fresh()->capabilities['privacy_renew'], 'Read-only privacy recovery retained stale purchase capabilities.');
verify($commands === ['GetOrderDetail', 'GetWPPSInfo'], 'Privacy recovery added a purchase or a full refresh.');
$invoiceCount = App\Models\Invoice::count();
try { $orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'duplicate-privacy-purchase')); verify(false, 'Purchased privacy still allowed another purchase invoice.'); }
catch (RuntimeException) { verify(App\Models\Invoice::count() === $invoiceCount, 'Declined duplicate privacy purchase created an invoice.'); }
$toggle = $operations->enqueueManagement($user, $domain->id, 'privacy_enable', ['confirmed' => true], hash('sha256', 'enable-purchased-privacy'));
$commands = [];
verify($operations->execute($toggle->id)->state === 'succeeded' && $domain->fresh()->snapshot['privacy']['enabled'], 'Privacy toggle failed verified snapshot/capability update.');
verify($commands === ['EnableServices', 'GetWPPSInfo'], 'Privacy toggle added capability reads.');
$renewalInvoice = $orders->privacy($user, $domain->id, 'renew', hash('sha256', 'renew-purchased-privacy'));
App\Helpers\ExtensionHelper::addPayment($renewalInvoice->id, null, amount: 5, isCreditTransaction: true);
$renewal = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $renewalInvoice->id)->firstOrFail();
$commands = [];
verify($operations->execute($renewal->id)->state === 'succeeded' && !$domain->fresh()->capabilities['privacy_purchase'] && $domain->fresh()->capabilities['privacy_renew'], 'Privacy renewal left incorrect capabilities.');
verify($commands === ['GetWPPSInfo', 'RenewServices', 'GetWPPSInfo'], 'Privacy renewal added capability reads.');
// Missing getter support after a full refresh disables purchase/renew/toggle capabilities.
$privacyReadFails = true;
$operations->refreshSnapshot($user, $domain->id);
$unavailable = $domain->fresh()->capabilities;
verify(!$unavailable['privacy_purchase'] && !$unavailable['privacy_renew'] && !$unavailable['privacy_enable'] && !$unavailable['privacy_disable'], 'Failed privacy read enabled unavailable actions.');
$user->update(['email' => 'other-owner@example.invalid']);
$operations->refreshSnapshot($user, $domain->id);
verify(!$domain->fresh()->capabilities['dnssec_add'] && !$domain->fresh()->capabilities['dnssec_delete'], 'Capability refresh escaped controlled DNSSEC identity guard.');
$domain = $domain->fresh();
$domain->update(['capabilities' => array_merge($domain->capabilities, ['hosts' => true, 'srv' => true])]);
verify(substr_count(domainPage($domain, $user), 'At least one record must stay.') === 2, 'DNS and SRV editors do not display the last-record limitation.');
echo 'Confirmed capability readbacks: '.$checks.' passed; no extra provider calls.'.PHP_EOL;
$successful = true;

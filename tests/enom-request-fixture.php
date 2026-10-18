<?php
require_once __DIR__.'/paymenter-test-app.php';

// Real stock native models. No installed application, credentials or provider requests.
require __DIR__.'/enom-reconciliation-fixture.php';
$successful = false;
$checks = 0;
$providerCalls = 0;
$operations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline', 'offline', function () use (&$providerCalls) { $providerCalls++; throw new RuntimeException('Provider must not run while requesting changes.'); })), fn () => ['uid' => 'offline', 'token' => 'offline']);
$app->instance(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, $operations);
$orders = new Paymenter\Extensions\Servers\Enom\Services\DomainOrders($operations);
$manager = new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations);
$server->settings()->updateOrCreate(['key' => 'privacy_price'], ['value' => '5.00']);
function activeFixture(): array {
    [$domain, $seed] = fixture('register');
    $domain->update(['status' => 'active', 'provider_order_id' => '12345', 'provider_expires_at' => now()->addYear()->format('Y-m-d'), 'capabilities' => ['renew' => true, 'lock' => true, 'contacts' => true, 'privacy_purchase' => true], 'snapshot' => ['locked' => true]]);
    App\Models\Service::withoutEvents(fn () => $domain->service->update(['status' => 'active']));
    $seed->update(['state' => 'rejected']);
    return [$domain, $seed];
}
function allRows(): array {
    $rows = [];
    foreach (['enom_operations', 'enom_domains', 'invoices', 'invoice_items', 'invoice_transactions', 'services', 'jobs', 'failed_jobs'] as $table) { $rows[$table] = Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toArray(); }
    return $rows;
}

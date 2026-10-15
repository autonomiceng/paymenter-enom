<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock native models. No installed application, credentials or provider requests.
require __DIR__.'/reconciliation-rejection.php';
$successful = false;
$checks = 0;
$providerCalls = 0;
$operations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline', 'offline', function () use (&$providerCalls) { $providerCalls++; throw new RuntimeException('Provider must not run while requesting changes.'); })), fn () => []);
$app->instance(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, $operations);
$orders = new Paymenter\Extensions\Servers\Enom\Services\DomainOrders($operations);
$manager = new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations);
$product->settings()->create(['key' => 'enom_privacy_price', 'value' => '5.00']);
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
foreach (['running', 'pending', 'uncertain'] as $state) {
    [$domain, $blocker] = activeFixture();
    $nonce = hash('sha256', 'existing-management-'.$state);
    $known = $operations->enqueueManagement($user, $domain->id, 'lock', ['confirmed' => true, 'locked' => '0'], $nonce);
    $knownInvoice = $orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'known-privacy-'.$state));
    $blocker->update(['state' => $state]);
    $before = allRows();
    $snapshot = $manager->snapshot($user, $domain->id);
    verify(($snapshot['writes_blocked'] ?? false) === true && $snapshot['capabilities'] === [], 'Unresolved domain still exposes registrar/billing capabilities.');
    verify(($snapshot['needs_review'] ?? false) === ($state === 'uncertain'), 'Unresolved notice does not distinguish review from running/pending.');
    try { $operations->enqueueManagement($user, $domain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'new-management-'.$state)); verify(false, 'Unresolved domain accepted a new management request.'); }
    catch (InvalidArgumentException $e) { verify(str_contains($e->getMessage(), 'registrar request'), 'Management refusal lacks a safe unresolved-request explanation.'); }
    try { $orders->renew($user, $domain->id, hash('sha256', 'new-renewal-'.$state)); verify(false, 'Unresolved domain accepted a new renewal invoice.'); }
    catch (InvalidArgumentException $e) { verify(str_contains($e->getMessage(), 'registrar request'), 'Billing refusal lacks a safe unresolved-request explanation.'); }
    verify($operations->enqueueManagement($user, $domain->id, 'lock', ['confirmed' => true, 'locked' => '0'], $nonce)->id === $known->id, 'Exact management nonce no longer returns its recorded request.');
    verify($orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'known-privacy-'.$state))->id === $knownInvoice->id, 'Exact billing nonce no longer returns its invoice.');
    verify($orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'another-privacy-'.$state))->id === $knownInvoice->id, 'Existing same-action invoice reuse was lost.');
    verify(allRows() == $before && $providerCalls === 0, 'Refusal or duplicate changed history, native invoices/jobs or provider calls.');
    [$otherDomain] = activeFixture();
    verify($manager->snapshot($user, $otherDomain->id)['capabilities']['lock'] === true, 'One blocked domain hides another domain actions.');
    verify($operations->enqueueManagement($user, $otherDomain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'other-management-'.$state))->wasRecentlyCreated, 'One blocked domain blocks another domain request.');
    verify($orders->privacy($user, $otherDomain->id, 'purchase', hash('sha256', 'other-privacy-'.$state))->exists, 'One blocked domain blocks another domain invoice.');
}
foreach (['queued', 'awaiting_payment', 'held', 'rejected', 'succeeded'] as $state) {
    [$domain, $seed] = activeFixture();
    $seed->update(['state' => $state]);
    verify(($manager->snapshot($user, $domain->id)['writes_blocked'] ?? false) === false, 'Queued, unpaid or terminal request blanket-blocks the domain.');
    verify($operations->enqueueManagement($user, $domain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'allowed-'.$state))->wasRecentlyCreated, 'Nonblocking state prevents a new management request.');
}
require_once $testRoot.'/app/Classes/helpers.php';
Illuminate\Support\Facades\Auth::login($user);
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
$app['view']->addLocation($release.'/themes/default/views');
app('router')->getRoutes()->refreshNameLookups();
[$domain, $blocker] = activeFixture();
$blocker->update(['state' => 'uncertain']);
$snapshot = $manager->snapshot($user, $domain->id);
$keys = array_fill_keys([...$manager::ACTIONS, 'refresh', 'renew', 'privacy_purchase', 'privacy_renew', 'renewal_preference'], str_repeat('a', 64));
$html = view('enom::show', ['snapshot' => $snapshot, 'keys' => $keys])->render();
verify(str_contains($html, 'A registrar request needs review by support.') && str_contains($html, 'Refresh status') && str_contains($html, 'Recent requests'), 'Blocked page lacks review notice, refresh or history.');
verify(!str_contains($html, 'Create renewal invoice') && !str_contains($html, 'Buy contact privacy, 1 year') && !str_contains($html, 'Turn off registrar lock'), 'Blocked page renders new registrar/invoice forms.');
verify(str_contains($html, 'Turn off renewal'), 'Blocked page removes local preference disable control.');
$request = Illuminate\Http\Request::create('/domains/'.$domain->id.'/manage/contacts', 'POST', ['confirmed' => '1', 'contact_type' => 'tech', 'contact' => ['first_name' => 'Preserved', 'last_name' => 'Customer', 'address1' => '123 Example Road', 'city' => 'Example', 'postal_code' => '12345', 'country' => 'US', 'email_address' => 'offline@example.invalid', 'phone' => '+1.5551234567'], 'epp' => 'never-flash']);
$session = new Illuminate\Session\Store('offline-gate', new Illuminate\Session\ArraySessionHandler(120));
$session->start();
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$request->merge(['_request_key' => Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue($request, 'domain:'.$domain->id.':contacts')]);
$app->instance('session.store', $session);
$app['redirect']->setSession($session);
$controller = new Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController($manager, $orders, $operations);
$beforeRetry = allRows();
$response = $controller->manage($request, $domain->id, 'contacts');
verify($response->getStatusCode() === 302 && $session->get('_old_input.contact.first_name') === 'Preserved' && $session->get('_old_input.contact_type') === 'tech', 'Unresolved-request refusal lost ordinary contact input.');
verify($session->get('_old_input._enom_form') === 'domain:'.$domain->id.':contacts' && !$session->has('_old_input.epp') && allRows() == $beforeRetry, 'Blocked form retry leaked input or changed ledger/queue.');
$beforeOps = Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray();
$operations->toggleRenewalPreference($user, $domain->id, false);
try { $operations->toggleRenewalPreference($user, $domain->id, true); verify(false, 'Unresolved domain re-enabled renewal billing.'); } catch (RuntimeException) { verify(true, 'Renewal enable remains guarded.'); }
verify(Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray() == $beforeOps && $providerCalls === 0, 'Preference disable changed registrar history or called provider.');
echo 'Unresolved domain request gates: '.$checks.' passed; exact duplicates preserved; no provider calls.'.PHP_EOL;
$successful = true;

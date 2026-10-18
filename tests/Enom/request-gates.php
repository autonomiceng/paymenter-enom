<?php
require dirname(__DIR__).'/enom-request-fixture.php';

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
    catch (InvalidArgumentException $e) { verify(str_contains($e->getMessage(), 'still being processed'), 'Management refusal lacks a safe unresolved-request explanation.'); }
    try { $orders->renew($user, $domain->id, hash('sha256', 'new-renewal-'.$state)); verify(false, 'Unresolved domain accepted a new renewal invoice.'); }
    catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'lifecycle requires review'), 'Billing refusal lacks a safe unresolved-request explanation.'); }
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
require_once dirname(__DIR__).'/enom-domain-page.php';
[$domain, $blocker] = activeFixture();
$blocker->update(['state' => 'uncertain']);
$snapshot = $manager->snapshot($user, $domain->id);
// The blocked page offers no registrar or invoice form (renew, privacy purchase, management); the renewal switch stays.
$actions = enomFormActions(enomDomainPage($user, $domain->id));
$base = '/domains/'.$domain->id;
verify(!in_array($base.'/renew', $actions, true) && !in_array($base.'/privacy/purchase', $actions, true)
    && !array_filter($actions, fn ($action) => str_starts_with($action, $base.'/manage/')), 'Blocked page renders new registrar/invoice forms: '.json_encode($actions));
verify(in_array($base.'/renewal-preference', $actions, true), 'Blocked page removes local preference disable control.');
[$openDomain, $openSeed] = activeFixture();
$openActions = enomFormActions(enomDomainPage($user, $openDomain->id));
verify(in_array('/domains/'.$openDomain->id.'/manage/lock', $openActions, true), 'Unblocked page lost its registrar forms (fixture check).');
Illuminate\Support\Facades\Auth::login($user);
$request = Illuminate\Http\Request::create('/domains/'.$domain->id.'/manage/contacts', 'POST', ['confirmed' => '1', 'contact_type' => 'tech', 'contact' => ['first_name' => 'Preserved', 'last_name' => 'Customer', 'address1' => '123 Example Road', 'city' => 'Example', 'postal_code' => '12345', 'country' => 'US', 'email_address' => 'offline@example.com', 'phone' => '+1.5551234567'], 'epp' => 'never-flash']);
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

$refused = false;
try { $operations->toggleRenewalPreference($user, $domain->id, true); }
catch (RuntimeException) { $refused = true; }
verify($refused, 'Unresolved domain re-enabled renewal billing.');
verify(Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray() == $beforeOps && $providerCalls === 0, 'Preference disable changed registrar history or called provider.');
echo 'Unresolved domain request gates: '.$checks.' passed; exact duplicates preserved; no provider calls.'.PHP_EOL;
$successful = true;

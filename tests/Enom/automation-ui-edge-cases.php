<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Independent native DTO/render regressions. SQLite memory and synthetic provider only.
require __DIR__.'/domain-administration.php';
$successful = false;
$checks = 0;
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
$manager = new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations);
$disabled = $make('ui-disabled');
$admin->savePolicy($staff, $disabled->id, $policy);
$admin->savePolicy($staff, $disabled->id, array_replace($policy, ['enabled' => '0']));
$disabled->refresh()->update(['capabilities' => ['renew' => true]]);
$disabledSnapshot = $manager->snapshot($user, $disabled->id);
checkBilling(!$disabledSnapshot['automation']['enabled'] && $disabledSnapshot['renewal_enabled'] && empty($disabledSnapshot['capabilities']['renew']), 'Disabled policy changes owner preference or advertises an unavailable invoice.');
$free = $make('ui-zero', 0);
$admin->savePolicy($staff, $free->id, $policy);
$free->refresh()->update(['capabilities' => ['renew' => true]]);
$freeSnapshot = $manager->snapshot($user, $free->id);
checkBilling(empty($freeSnapshot['capabilities']['renew']), 'Zero-price customer can request an unsupported renewal invoice.');
$paid = $make('ui-paid');
$admin->savePolicy($staff, $paid->id, $policy);
$paid->refresh()->update(['capabilities' => ['renew' => true]]);
$paidSnapshot = $manager->snapshot($user, $paid->id);
checkBilling($paidSnapshot['automation']['enabled'] && $paidSnapshot['capabilities']['renew'] === true, 'Eligible positive-price renewal invoice is hidden.');
$legacy = $make('ui-legacy'); $legacy->update(['capabilities' => ['renew' => true]]);
checkBilling($manager->snapshot($user, $legacy->id)['capabilities']['renew'] === true, 'Managed presentation changes legacy availability.');
$paid->service->cancellation()->create(['type' => 'end_of_period', 'reason' => 'Synthetic owner preference']);
$cancelled = $manager->snapshot($user, $paid->id);
checkBilling(!$cancelled['renewal_enabled'] && !$cancelled['automation']['enabled'] && empty($cancelled['capabilities']['renew']), 'Owner cancellation still advertises scheduled invoices.');
$paid->service->cancellation()->delete();
$paid->service->update(['status' => 'suspended']);
checkBilling(!$manager->snapshot($user, $paid->id)['automation']['enabled'] && empty($manager->snapshot($user, $paid->id)['capabilities']['renew']), 'Suspended service advertises enabled renewal invoices.');
$paid->service->update(['status' => 'active']);
foreach (['billing_enabled', 'operations_enabled'] as $setting) {
    $server->settings()->where('key', $setting)->update(['value' => '0']);
    $snapshot = $manager->snapshot($user, $paid->id);
    checkBilling(!$snapshot['automation']['enabled'] && empty($snapshot['capabilities']['renew']), 'Disabled '.$setting.' still advertises automatic billing.');
    $server->settings()->where('key', $setting)->update(['value' => '1']);
}
$reviewPolicy = $paid->fresh()->renewal_policy; $reviewPolicy['automatic_spend_paused'] = true;
$reviewObservation = $paid->fresh()->observation;
$reviewObservation['cost_health'] = ['state' => 'actual_cost_exceeded', 'operation_id' => 902, 'observed_at' => '2026-10-13T10:00:00+02:00', 'private' => 'private-cost-health-marker'];
$reviewObservation['renewal_quote'] = ['state' => 'advisory', 'amount' => '12.35', 'currency' => 'USD', 'private' => 'private-quote-marker'];
$paid->refresh()->update(['renewal_policy' => $reviewPolicy, 'observation' => $reviewObservation]);
$costData = $admin->get($costStaff, $paid->id);
checkBilling($costData['automatic_spend_paused'] === true && $costData['cost_health']['operation_id'] === 902 && $costData['renewal_quote']['amount'] === '12.35', 'Cost-authorized staff lack pause/health/current quote diagnostics.');
$restricted = $admin->get($staff, $paid->id);
checkBilling(!array_intersect(['automatic_spend_paused', 'cost_health', 'renewal_quote'], array_keys($restricted)), 'Staff without cost permission receive private diagnostics.');
$customer = $manager->snapshot($user, $paid->id);
checkBilling(!preg_match('/automatic_spend_paused|cost_health|private-cost|private-quote|12.35/', json_encode($customer)), 'Private cost diagnostics entered customer DTO.');


$revocable = $make('ui-revoke', 0); $admin->savePolicy($staff, $revocable->id, $policy);
$admin->saveSponsorship($staff, $revocable->id, $sponsorship);
$quoteObservation = $revocable->fresh()->observation;
$quoteObservation['renewal_quote'] = ['state' => 'quoted', 'complete' => true, 'amount' => '11.23', 'currency' => 'USD',
    'observed_at' => now()->toIso8601String(), 'domain' => $revocable->domain, 'action' => 'renew', 'term' => 1, 'base_expires_at' => '2026-12-01'];
$revocable->refresh()->update(['observation' => $quoteObservation]);
$queued = $billing->sponsor($revocable->id, $staff);
checkBilling($queued && $queued->state === 'queued', 'Synthetic sponsorship did not queue.');
$providerRequests = 0;
$transport = function () use (&$providerRequests): string { $providerRequests++; throw new RuntimeException('Revoked approval reached provider transport.'); };
$guarded = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(
    new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('test-account', 'offline', $transport)),
    fn () => ['uid' => 'test-account', 'token' => 'offline', 'sandbox' => true, 'operations_enabled' => true]);
checkBilling($guarded->funded($queued->fresh()), 'Original synthetic sponsor approval is not funded.');
$financeBefore = unchangedFinance(); $issuedPolicy = $queued->fresh()->policy; $approvalBefore = $revocable->fresh()->sponsorship;
rejectedAdmin(fn () => $admin->saveSponsorship($staff, $revocable->id, array_replace($sponsorship, ['limit_amount' => '999.00'])), 'Outstanding request admitted an increased approval.');
checkBilling($revocable->fresh()->sponsorship === $approvalBefore && unchangedFinance() === $financeBefore, 'Rejected approval increase changed evidence.');
$admin->saveSponsorship($staff, $revocable->id, ['enabled' => '0', 'limit_amount' => '999.00', 'reason' => 'Untrusted replacement reason']);
$withdrawn = $revocable->fresh()->sponsorship;
checkBilling($withdrawn['enabled'] === false && $withdrawn['revoked_by'] === $staff->id && $withdrawn['limit_minor'] === $approvalBefore['limit_minor'] && $withdrawn['reason'] === $approvalBefore['reason'], 'Withdrawal rewrote original approval terms or lost revoking staff.');
checkBilling(unchangedFinance() === $financeBefore && $queued->fresh()->policy === $issuedPolicy, 'Revocation rewrote issued approval snapshots or financial history.');
checkBilling(!$guarded->funded($queued->fresh()), 'Revoked sponsor request remains funded.');
$result = $guarded->execute($queued->id);
checkBilling($result->state === 'held' && $result->started_at === null && $providerRequests === 0, 'Revoked queued request performed a provider send.');
checkBilling($result->policy === $issuedPolicy && $result->invoice_id === null && $revocable->fresh()->service->expires_at === null, 'Revoked execution changed payment/binding history.');
foreach (['running', 'pending', 'uncertain'] as $state) {
    $revocable->refresh()->update(['sponsorship' => $approvalBefore]);
    $result->refresh()->update(['state' => $state]);
    $before = unchangedFinance();
    $admin->saveSponsorship($staff, $revocable->id, ['enabled' => '0']);
    checkBilling($revocable->fresh()->sponsorship['enabled'] === false && unchangedFinance() === $before && $result->fresh()->state === $state && $result->fresh()->policy === $issuedPolicy, 'Revocation changed unresolved '.$state.' evidence.');
}
$cache = sys_get_temp_dir().'/enom-ui-edges-'.bin2hex(random_bytes(6));
mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
file_put_contents($cache.'/layouts/app.blade.php', '<!doctype html><html><body>{{ $slot }}</body></html>');
file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
config(['view.paths' => [$cache, $release.'/themes/default/views'], 'view.compiled' => $cache]);
$app['view']->getFinder()->setPaths([$cache, $release.'/themes/default/views']);
$app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
foreach (['enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'services.show' => '/services/{service}', 'enom.domains.index' => '/domains', 'enom.domains.show' => '/domains/{domain}', 'enom.domains.refresh' => '/domains/{domain}/refresh', 'enom.domains.preference' => '/domains/{domain}/renewal-preference', 'enom.domains.renew' => '/domains/{domain}/renew', 'enom.domains.manage' => '/domains/{domain}/manage/{action}', 'enom.domains.privacy' => '/domains/{domain}/privacy/{action}', 'invoices.show' => '/invoices/{invoice}', 'enom.admin.index' => '/admin/enom', 'enom.admin.policy' => '/admin/enom/domains/{domain}/policy', 'enom.admin.sponsorship' => '/admin/enom/domains/{domain}/sponsorship', 'enom.admin.refresh' => '/admin/enom/domains/{domain}/refresh', 'enom.admin.sponsored-renewal' => '/admin/enom/domains/{domain}/sponsored-renewal'] as $name => $path) {
    $app['router']->get($path, fn () => null)->name($name);
}
$app['router']->getRoutes()->refreshNameLookups();
$request = Illuminate\Http\Request::create('http://enom-tests.invalid/domains');
$session = new Illuminate\Session\Store('independent-edges', new Illuminate\Session\ArraySessionHandler(60)); $session->start(); $request->setLaravelSession($session);
$app->instance('request', $request); $app->instance('session', $session); $app['url']->setRequest($request);
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
$keys = array_fill_keys([...Paymenter\Extensions\Servers\Enom\Services\DomainManager::ACTIONS, 'refresh', 'renew', 'renewal_preference', 'privacy_purchase', 'privacy_renew'], str_repeat('a', 64));
try {
    $pages = [];
    foreach (['disabled' => $disabledSnapshot, 'zero' => $freeSnapshot, 'paid' => $paidSnapshot] as $name => $snapshot) {
        $pages[$name] = $app['view']->make('enom::show', ['snapshot' => $snapshot, 'keys' => $keys])->render();
        checkBilling(!str_contains($pages[$name], 'Renew automatically') && str_contains($pages[$name], 'Turn off renewal'), 'Customer preference claims automatic renewal for '.$name);
        checkBilling(str_contains($pages[$name], 'Create renewal invoice') === ($name === 'paid'), 'Invoice action disagrees with billing availability for '.$name);
        checkBilling(preg_match('/renewal invoices/i', $pages[$name]) === 1 && !str_contains($pages[$name], 'No billing date is set'), 'Managed null native date claims billing is unscheduled.');
    }
    $offset = $paidSnapshot; $offset['last_synced_at'] = null; $offset['automation']['observed_at'] = '2026-10-13T10:00:00+02:00';
    $pages['offset'] = $app['view']->make('enom::show', ['snapshot' => $offset, 'keys' => $keys])->render();
    checkBilling(str_contains($pages['offset'], 'Oct 13, 2026, 08:00 UTC'), 'Customer observation timezone is incorrectly labeled.');
    $adminKeys = array_fill_keys(['policy', 'sponsorship', 'refresh', 'sponsored-renewal'], str_repeat('b', 64));
    $pages['admin-cost'] = $app['view']->make('enom::admin-domain', ['domain' => $costData, 'keys' => $adminKeys])->render();
    checkBilling(str_contains($pages['admin-cost'], 'Automatic registrar spending is paused') && str_contains($pages['admin-cost'], 'confirmed charge was above the approved limit') && str_contains($pages['admin-cost'], 'USD 12.35') && str_contains($pages['admin-cost'], '08:00 UTC'), 'Authorized admin HTML hides financial pause/quote/health or mislabels its timestamp.');
    $pages['admin-restricted'] = $app['view']->make('enom::admin-domain', ['domain' => $restricted, 'keys' => $adminKeys])->render();
    checkBilling(!preg_match('/Automatic registrar spending is paused|confirmed charge was above the approved limit|12.35|private-cost|private-quote/', $pages['admin-restricted']), 'Restricted admin HTML exposes private cost diagnostics.');
    checkBilling(!preg_match('/automatic_spend_paused|cost_health|12.35|private-cost|private-quote/', $pages['paid']), 'Customer HTML exposes cost metadata.');

    $admin->saveSponsorship($staff, $free->id, $sponsorship);
    $full = $admin->get($staff, $free->id);
    $pages['admin-full'] = $app['view']->make('enom::admin-domain', ['domain' => $full, 'keys' => $adminKeys])->render();
    $document = new DOMDocument; @$document->loadHTML($pages['admin-full']); $xpath = new DOMXPath($document);
    $incompleteApproval = $full; unset($incompleteApproval['sponsorship']['current_cycle']);
    $incompleteHtml = $app['view']->make('enom::admin-domain', ['domain' => $incompleteApproval, 'keys' => $adminKeys])->render();
    $incompleteDocument = new DOMDocument; @$incompleteDocument->loadHTML($incompleteHtml); $incompleteXpath = new DOMXPath($incompleteDocument);
    checkBilling($incompleteXpath->query('//form[@action="'.route('enom.admin.sponsored-renewal', $full['id']).'"]')->length === 0, 'A missing approval-cycle proof exposed a sponsorship spending form.');
    $revokes = $xpath->query('//form[input[@name="enabled" and @value="0"] and .//button[contains(., "Withdraw approval")]]');
    checkBilling($revokes->length === 1 && $xpath->query('.//*[@required]', $revokes->item(0))->length === 1, 'Dedicated revoke form requires unrelated approval fields.');
    // Zero retail is a sponsorship calendar, including while every server flag is on.
    $zeroCalendar = $full; $zeroCalendar['zero_retail'] = true; $zeroCalendar['enabled'] = true;
    $zeroCalendar['server_flags'] = array_fill_keys(['server_enabled', 'sandbox', 'reads_enabled', 'operations_enabled', 'billing_enabled', 'observations_enabled', 'grace_renewals_enabled'], true);
    $financeBeforeCalendar = unchangedFinance();
    $zeroCalendarHtml = $app['view']->make('enom::admin-domain', ['domain' => $zeroCalendar, 'keys' => $adminKeys])->render();
    $zeroCalendarDocument = new DOMDocument; @$zeroCalendarDocument->loadHTML($zeroCalendarHtml);
    $zeroCalendarText = preg_replace('/\s+/', ' ', $zeroCalendarDocument->textContent);
    checkBilling(str_contains($zeroCalendarText, 'No customer invoice') && str_contains($zeroCalendarText, 'Configured renewal review'), 'Enabled zero-retail calendar promises a customer invoice or hides the review dates.');
    checkBilling(!str_contains($zeroCalendarText, 'Next invoice') && !str_contains($zeroCalendarText, 'Configured invoice date:'), 'Zero-retail calendar uses customer invoice schedule labels.');
    $zeroCalendarXpath = new DOMXPath($zeroCalendarDocument);
    $zeroPolicyLabel = $zeroCalendarXpath->query('//label[@for="enom-admin-'.$full['id'].'-policy-enabled"]')->item(0)?->textContent ?? '';
    checkBilling(!str_contains(strtolower($zeroPolicyLabel), 'invoice'), 'Zero-retail policy control implies enabling customer invoices.');
    $positiveCalendar = $zeroCalendar; $positiveCalendar['zero_retail'] = false;
    $positiveCalendarHtml = $app['view']->make('enom::admin-domain', ['domain' => $positiveCalendar, 'keys' => $adminKeys])->render();
    checkBilling(str_contains($positiveCalendarHtml, 'Configured invoice date:') && str_contains($positiveCalendarHtml, 'Payment due:'), 'Positive-retail calendar lost its configured invoice dates.');
    $positiveCalendar['server_flags']['billing_enabled'] = false;
    $disabledCalendarHtml = $app['view']->make('enom::admin-domain', ['domain' => $positiveCalendar, 'keys' => $adminKeys])->render();
    checkBilling(str_contains($disabledCalendarHtml, 'Invoices are not being issued now.'), 'Disabled invoice scheduling presents configured dates as issued invoices.');
    checkBilling(unchangedFinance() === $financeBeforeCalendar, 'Calendar display changed native finances or requests.');
    if (getenv('ENOM_UI_FIXTURE_DIR')) {
        $out = realpath(getenv('ENOM_UI_FIXTURE_DIR'));
        if (!$out || !str_starts_with($out.'/', $repo.'/.scratch/')) { throw new RuntimeException('Synthetic fixture destination must be within repository scratch.'); }
        foreach ($pages as $name => $html) { file_put_contents($out.'/'.$name.'-after.html', $html); }
    }
    echo 'Automation independent UI edges: '.$checks.' passed; native DTO/Blade, SQLite memory, no provider writes.'.PHP_EOL;
    $successful = true;
} finally {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache);
}

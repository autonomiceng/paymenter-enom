<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real native DTOs and Blade, synthetic in-memory SQLite. No installed app or transport.
require __DIR__.'/automation-ui-edge-cases.php';
$successful = false;
$checks = 0;
$cache = sys_get_temp_dir().'/enom-presentation-'.bin2hex(random_bytes(8));
mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
file_put_contents($cache.'/layouts/app.blade.php', '<!doctype html><html><body>{{ $slot }}</body></html>');
file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
config(['view.paths' => [$cache, $release.'/themes/default/views'], 'view.compiled' => $cache]);
$app['view']->getFinder()->setPaths([$cache, $release.'/themes/default/views']);
$app['view']->addNamespace('legacy-views', $repo.'/tests/fixtures/legacy-views');
foreach (['enom.admin.reconcile' => '/admin/enom/{operation}/reconcile', 'enom.admin.fund' => '/admin/enom/{operation}/fund', 'enom.admin.domain' => '/admin/enom/domains/{domain}', 'enom.admin.readmit' => '/admin/enom/{operation}/readmit', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) {
    $app['router']->get($path, fn () => null)->name($name);
}
$app['router']->getRoutes()->refreshNameLookups();
function presentationDocument(string $html): array {
    $document = new DOMDocument; @$document->loadHTML($html);
    return [$document, new DOMXPath($document), preg_replace('/\s+/', ' ', $document->textContent)];
}
function presentationForm(DOMXPath $xpath, string $route): ?DOMElement {
    return $xpath->query('//form[@action="'.$route.'"]')->item(0);
}
function presentationPayload(?DOMElement $form): array {
    if (!$form) { return []; }
    $out = [];
    foreach ($form->getElementsByTagName('input') as $input) {
        $out[$input->getAttribute('name')] = ['value' => $input->getAttribute('value'), 'type' => $input->getAttribute('type'), 'required' => $input->hasAttribute('required')];
    }
    ksort($out); return $out;
}
try {
    // Pending registration/transfer has no confirmed registration expiry to become stale.
    foreach (['registration_pending', 'transfer_pending'] as $status) {
        $pending = $make('presentation-'.$status);
        $pending->update(['status' => $status, 'provider_expires_at' => null, 'last_synced_at' => null]);
        $pending->service->update(['status' => 'pending', 'expires_at' => null]);
        $pendingSnapshot = $manager->snapshot($user, $pending->id);
        checkBilling($pendingSnapshot['provider_expires_at'] === null && $pendingSnapshot['renewal_enabled'], 'Pending native DTO lost its future renewal preference.');
        // Both legacy and future managed enrollment display the pending state truthfully.
        foreach ([false, true] as $managedPending) {
            $candidate = $pendingSnapshot;
            $candidate['automation']['managed'] = $managedPending;
            $candidate['automation']['observation_status'] = 'unknown';
            [$document, $xpath, $text] = presentationDocument($app['view']->make('enom::show', ['snapshot' => $candidate, 'keys' => $keys])->render());
            checkBilling(!preg_match('/dates (?:shown )?may be out of date|dates have not been confirmed recently|No billing date is set/i', $text), 'Pending '.$status.' warns about a nonexistent previous date.');
            checkBilling(preg_match('/future|after .*?(?:registration|transfer|complete|confirm)|once .*?(?:complete|confirm|active)/i', $text) === 1, 'Pending '.$status.' describes its future renewal preference as current renewal.');
            checkBilling(!str_contains($text, 'Renewing now is not available'), 'Pending '.$status.' advertises an unavailable current renewal instead of the order state.');
            $preferenceForm = presentationForm($xpath, route('enom.domains.preference', $pending->id));
            checkBilling($preferenceForm !== null, 'Pending state removed the existing guarded preference form.');
            [, $oldPreferenceXpath] = presentationDocument($app['view']->make('legacy-views::show', ['snapshot' => $candidate, 'keys' => $keys])->render());
            checkBilling(presentationPayload($preferenceForm) === presentationPayload(presentationForm($oldPreferenceXpath, route('enom.domains.preference', $pending->id))), 'Pending preference changed its CSRF/nonce/confirmation/enabled fields.');
            checkBilling(preg_match('/not .*?(?:sent|send)|stops? .*?(?:sent|send)|blocks? .*?(?:registration|transfer)/i', $preferenceForm->textContent) === 1 && preg_match('/(?:after|even).*?pa(?:id|y)/i', $preferenceForm->textContent) === 1, 'Pending preference hides its ability to block the paid unsent order.');
            checkBilling(preg_match('/(?:does not|will not|not).*?(?:cancel|refund).*?invoice|invoice.*?(?:not|stays).*?(?:cancel|refund|payable|open)/i', $preferenceForm->textContent) === 1, 'Pending preference falsely implies cancellation or refund of its order invoice.');
            checkBilling(preg_match('/support/i', $preferenceForm->textContent) === 1, 'Pending preference hides support-needed re-enable restriction.');
        }
        $orderInvoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $orderItem = $orderInvoice->items()->create(['description' => 'Synthetic pending order', 'price' => '15.00', 'quantity' => 1, 'reference_type' => App\Models\Service::class, 'reference_id' => $pending->service_id]);
        Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $pending->id, 'service_id' => $pending->service_id, 'invoice_id' => $orderInvoice->id, 'invoice_item_id' => $orderItem->id, 'operation_key' => 'presentation-pending-'.$status, 'action' => $status === 'transfer_pending' ? 'transfer' : 'register', 'state' => 'awaiting_payment', 'funding_basis' => 'paid_invoice', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
        $operations->toggleRenewalPreference($user, $pending->id, false);
        $offPending = $manager->snapshot($user, $pending->id);
        checkBilling(!$offPending['renewal_enabled'] && $orderInvoice->fresh()->status === 'pending', 'Pending renewal-off changed its order invoice or lost the native cancellation.');
        [, $offXpath, $offText] = presentationDocument($app['view']->make('enom::show', ['snapshot' => $offPending, 'keys' => $keys])->render());
        $summaryText = preg_replace('/\s+/', ' ', $offXpath->query('//section[@aria-labelledby="enom-summary-heading"]')->item(0)->textContent);
        checkBilling(preg_match('/Pay invoice #\d+ in full to start/i', $summaryText) === 0, 'Pending renewal-off still promises paying will start its blocked unsent order.');
        checkBilling($offXpath->query('//a[@href="'.route('invoices.show', $orderInvoice->id).'"]')->length > 0, 'Pending renewal-off removed access to the unchanged order invoice.');
        refusedBilling(fn () => $operations->toggleRenewalPreference($user, $pending->id, true), 'Pending registration/transfer unexpectedly permits re-enable without active future expiry.');
        checkBilling(!$manager->snapshot($user, $pending->id)['renewal_enabled'] && $pending->fresh()->provider_expires_at === null, 'Refused pending re-enable changed its cancellation or fabricated expiry.');
    }
    // Existing managed active domains still warn when their actual dated observation is stale.
    $stale = $paidSnapshot; $stale['automation']['observation_status'] = 'stale';
    [, , $staleText] = presentationDocument($app['view']->make('enom::show', ['snapshot' => $stale, 'keys' => $keys])->render());
    checkBilling(str_contains($staleText, 'dates shown may be out of date'), 'Pending-state correction suppressed active stale-date evidence.');

    // Zero retail prevents NEW invoices while an earlier invoice remains its own obligation.
    $earlierInvoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-11-24']);
    $earlierItem = $earlierInvoice->items()->create(['description' => 'Earlier positive retail renewal', 'price' => '15.00', 'quantity' => 1]);
    $earlierRequest = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $free->id, 'service_id' => $free->service_id, 'invoice_id' => $earlierInvoice->id, 'invoice_item_id' => $earlierItem->id, 'operation_key' => 'presentation-earlier-invoice', 'action' => 'renew', 'state' => 'awaiting_payment', 'funding_basis' => 'paid_invoice', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
    $zero = $manager->snapshot($user, $free->id);
    checkBilling($zero['zero_retail'] && in_array($earlierInvoice->id, array_column($zero['automation']['invoices'], 'id'), true), 'Zero retail removed a native earlier-invoice link from its DTO.');
    $finance = unchangedFinance();
    [, $zeroXpath, $zeroText] = presentationDocument($app['view']->make('enom::show', ['snapshot' => $zero, 'keys' => $keys])->render());
    checkBilling(!str_contains($zeroText, 'Create renewal invoice'), 'Zero retail exposes a new customer renewal-invoice action.');
    checkBilling($zeroXpath->query('//section[@aria-labelledby="enom-renewal-heading"]//a[@href="'.route('invoices.show', $earlierInvoice->id).'"]')->length === 1, 'Zero retail concealed an earlier renewal invoice.');
    checkBilling(preg_match('/new renewal invoice|new customer invoice/i', $zeroText) === 1 && preg_match('/earlier|existing|previous/i', $zeroText) === 1, 'Zero-retail copy makes an absolute no-invoice claim despite earlier history.');
    checkBilling(preg_match('/issued while .*?(?:had|has) .*?(?:price|priced)/i', $zeroText) === 0, 'Customer view invents earlier invoice price provenance absent from its DTO.');
    $zeroOffForm = presentationForm($zeroXpath, route('enom.domains.preference', $free->id));
    checkBilling(preg_match('/eligible/i', $zeroOffForm->textContent) === 1 && preg_match('/registrar attempt|not .*?(?:sent|send)|never .*?(?:sent|send)|no .*?(?:attempt|send)/i', $zeroOffForm->textContent) === 1, 'Zero-retail OFF copy promises to cancel invoices with registrar-attempt evidence.');
    checkBilling(unchangedFinance() === $finance, 'Zero-retail rendering changed native financial history.');
    $operations->toggleRenewalPreference($user, $free->id, false);
    checkBilling($earlierInvoice->fresh()->status === 'cancelled' && $earlierRequest->fresh()->safe_code === 'renewal_cancelled_unpaid' && $earlierItem->fresh()->price == 15, 'Zero retail bypassed eligible earlier-invoice cancellation or rewrote its amount.');
    $zero = $manager->snapshot($user, $free->id); $finance = unchangedFinance();
    [, , $cancelledZeroText] = presentationDocument($app['view']->make('enom::show', ['snapshot' => $zero, 'keys' => $keys])->render());
    checkBilling(preg_match('/[Ee]ligible unpaid renewal invoices are cancelled/', $cancelledZeroText) === 1 || preg_match('/cancelled invoices stay cancelled/i', $cancelledZeroText) === 1, 'Zero-retail customer cancellation loses the existing-invoice rules.');
    checkBilling(unchangedFinance() === $finance, 'Presentation changed invoices, payments, requests or queue.');

    // Charge forms stay exactly guarded, reachable without the table's horizontal scrolling.
    $costDomain = $zeroCalendar;
    $costDomain['permissions']['costs'] = true;
    $costDomain['costs'] = [];
    foreach ([7025 => true, 7026 => false, 7027 => true] as $operationId => $checkable) {
        $costDomain['costs'][] = ['operation_id' => $operationId, 'action' => 'renew', 'state' => 'succeeded', 'quoted' => ['currency' => 'USD', 'amount' => '8.95', 'state' => 'quoted'], 'confirmed' => null, 'poll_attempts' => 1, 'poll_reason' => 'order_bill_amount_missing', 'checkable' => $checkable];
    }
    $chargeKeys = $adminKeys; $chargeKeys['charge'] = [7025 => str_repeat('b', 64), 7026 => str_repeat('c', 64)];
    [, $beforeXpath] = presentationDocument($app['view']->make('legacy-views::admin-domain', ['domain' => $costDomain, 'keys' => $chargeKeys])->render());
    [, $afterXpath] = presentationDocument($app['view']->make('enom::admin-domain', ['domain' => $costDomain, 'keys' => $chargeKeys])->render());
    foreach ([7025 => true, 7026 => false, 7027 => false] as $operationId => $expected) {
        $action = route('enom.admin.reconcile', $operationId);
        $beforeForm = presentationForm($beforeXpath, $action); $afterForm = presentationForm($afterXpath, $action);
        checkBilling(($afterForm !== null) === $expected && ($beforeForm !== null) === $expected, 'Charge-evidence control changed its checkable/key guard for '.$operationId.'.');
        checkBilling(presentationPayload($beforeForm) === presentationPayload($afterForm), 'Charge-evidence CSRF, nonce or confirmation changed for '.$operationId.'.');
        if ($afterForm) {
            checkBilling($afterForm->getAttribute('method') === 'POST', 'Charge evidence ceased using the guarded POST route.');
            checkBilling($afterXpath->query('ancestor::table|ancestor::*[contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]', $afterForm)->length === 0, 'Charge check still requires a horizontally scrolling table.');
        }
    }
    $costDomain['permissions']['costs'] = false;
    [, $restrictedXpath] = presentationDocument($app['view']->make('enom::admin-domain', ['domain' => $costDomain, 'keys' => $chargeKeys])->render());
    checkBilling(presentationForm($restrictedXpath, route('enom.admin.reconcile', 7025)) === null, 'Charge-action move escaped private-cost permission.');

    // Labels preserve the zero-retail saved-offset versus actual sponsorship calendar distinction.
    [, , $calendarText] = presentationDocument($app['view']->make('enom::admin-domain', ['domain' => $zeroCalendar, 'keys' => $adminKeys])->render());
    checkBilling(str_contains($calendarText, 'Configured renewal review') && str_contains($calendarText, 'no customer invoice or sponsorship due-day deadline'), 'Zero-price calendar invents an invoice/sponsorship deadline.');
    [, , $renewalHelp] = presentationDocument($app['view']->make('enom::help.customer.renewal')->render());
    checkBilling(preg_match('/new renewal invoice|new customer invoice/i', $renewalHelp) === 1 && preg_match('/earlier|existing|previous/i', $renewalHelp) === 1, 'Customer Help contradicts zero-retail existing-invoice history.');
    [, , $calendarHelp] = presentationDocument($app['view']->make('enom::help.admin.calendar')->render());
    checkBilling(preg_match('/zero|0\.00/', $calendarHelp) === 1 && preg_match('/review start|renewal review|review starts/i', $calendarHelp) === 1 && preg_match('/no .*?deadline|does not .*?deadline/i', $calendarHelp) === 1, 'Staff Help treats zero-price due offset as sponsorship deadline.');

    // Page counters count only this page; the filters separately describe the complete group.
    $fundDomain = $make('presentation-generic-funding');
    $funding = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $fundDomain->id, 'service_id' => $fundDomain->service_id, 'operation_key' => 'presentation-funding', 'action' => 'privacy_purchase', 'state' => 'awaiting_payment', 'funding_basis' => 'unfunded', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
    $paginator = new Illuminate\Pagination\LengthAwarePaginator([$funding], 250, 100, 2, ['path' => route('enom.admin.index')]);
    $adminData = ['operations' => $paginator, 'domainCount' => 17, 'reviewCount' => 4, 'domains' => collect(), 'domainProgress' => [], 'show' => null, 'groupCounts' => ['review' => 120, 'waiting' => 125, 'history' => 5], 'fundingBlockers' => [], 'readmittable' => [], 'canFund' => true, 'keys' => [$funding->id => ['fund' => str_repeat('d', 64)]]];
    [, $staffXpath, $staffText] = presentationDocument($app['view']->make('enom::admin', $adminData)->render());
    $filterText = $staffXpath->query('//nav[@aria-label="Filter requests"]')->item(0)?->textContent ?? '';
    checkBilling(str_contains($filterText, '125') && preg_match('/total|all pages|see all/i', $staffText) === 1, 'Filter counts fail to distinguish all requests from this page.');
    $groupText = $staffXpath->query('//h2[@id="enom-group-waiting"]/parent::*')->item(0)?->textContent ?? '';
    checkBilling(preg_match('/1 .*?(?:this|current) page|(?:this|current) page .*?1/i', preg_replace('/\s+/', ' ', $groupText)) === 1 && str_contains($groupText, '125'), 'Group heading does not distinguish its one current-page request from125 total requests.');
    $fundForm = presentationForm($staffXpath, route('enom.admin.fund', $funding->id));
    checkBilling($fundForm !== null && presentationPayload($fundForm)['amount']['value'] === '15.00' && presentationPayload($fundForm)['currency']['value'] === 'USD', 'Funding clarification changed exact amount/currency authorization fields.');
    [, $oldStaffXpath] = presentationDocument($app['view']->make('legacy-views::admin', $adminData)->render());
    checkBilling(presentationPayload($fundForm) === presentationPayload(presentationForm($oldStaffXpath, route('enom.admin.fund', $funding->id))), 'Funding wording changed existing CSRF/nonce/confirmation or authorization fields.');
    checkBilling(preg_match('/recorded amount|recorded .*?amount/i', $fundForm->textContent) === 1 && preg_match('/(?:final|actual).*?(?:charge|cost).*?(?:differ|vary|higher|not capped)|(?:does not|cannot|not).*?cap.*?(?:charge|cost)/i', $fundForm->textContent) === 1, 'Funding copy promises a quote-backed cap on final registrar charge.');
    checkBilling(preg_match('/does not record a customer payment|no customer payment .*?recorded/i', $fundForm->textContent) === 1, 'Funding explanation implies a customer payment.');
    foreach (['permission', 'peer-blocker'] as $denial) {
        $denied = $adminData;
        if ($denial === 'permission') { $denied['canFund'] = false; }
        else { $denied['fundingBlockers'][$funding->id] = true; }
        [, $deniedXpath] = presentationDocument($app['view']->make('enom::admin', $denied)->render());
        checkBilling(presentationForm($deniedXpath, route('enom.admin.fund', $funding->id)) === null, 'Funding wording escaped the '.$denial.' guard.');
    }
    foreach (['register', 'transfer', 'renew', 'privacy_renew'] as $action) {
        $otherAction = clone $funding; $otherAction->action = $action;
        $actionData = $adminData;
        $actionData['operations'] = new Illuminate\Pagination\LengthAwarePaginator([$otherAction], 250, 100, 2, ['path' => route('enom.admin.index')]);
        [, $actionXpath] = presentationDocument($app['view']->make('enom::admin', $actionData)->render());
        $actionForm = presentationForm($actionXpath, route('enom.admin.fund', $funding->id));
        checkBilling($actionForm !== null && preg_match('/(?:does not|cannot|not).*?cap.*?(?:charge|cost)|(?:final|actual).*?(?:charge|cost).*?(?:differ|vary|higher|not capped)/i', $actionForm->textContent) === 1, 'Generic '.$action.' funding promises a final registrar charge cap.');
        checkBilling(presentationPayload($actionForm) === presentationPayload($fundForm), 'Generic funding wording changed exact authorization fields for '.$action.'.');
    }
    echo 'UI/UX 0.2.5 native presentation boundaries: '.$checks.' passed; offline synthetic records.'.PHP_EOL;
    $successful = true;
} finally {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache);
}

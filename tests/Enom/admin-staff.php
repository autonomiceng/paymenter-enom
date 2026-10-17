<?php
// Staff side: policies deny create/delete and require admin.enom.manage; StaffDomains checks manage, fund and
// costs per action; the 0.2 /admin/enom URLs only redirect (GET) or change nothing (POST); the Filament pages
// keep encrypted payload/policy/cost columns and Enom costs out of Livewire state, HTML and global search.
$enomTestLabel = 'admin-staff';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';
require dirname(__DIR__).'/enom-admin-panel.php';

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

enomAdminPanel();
$staffUser = function (string $name, array $permissions): User {
    $role = Role::create(['name' => $name, 'permissions' => $permissions]);
    return User::create(['email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => 'synthetic', 'first_name' => $name, 'last_name' => 'Staff', 'role_id' => $role->id, 'email_verified_at' => now()]);
};
$super = $staffUser('Super', ['*']);
$manager = $staffUser('Manager', ['admin.enom.manage']);
$funder = $staffUser('Funder', ['admin.enom.manage', 'admin.enom.fund']);
$coster = $staffUser('Coster', ['admin.enom.manage', 'admin.enom.costs']);
$fundOnly = $staffUser('Fund only', ['admin.enom.fund']);
$helpdesk = $staffUser('Helpdesk', ['admin.tickets.view']);
$client = client('client@example.com');
$staffDomains = app(StaffDomains::class);

// Connection refusals keep provider error text out of responses and logs.
$providerError = 'Synthetic account detail connection-error-7f3a';
$connectionCommands = [];
$connectionOperations = new \Paymenter\Extensions\Servers\Enom\Services\DomainOperations(
    new \Paymenter\Extensions\Servers\Enom\Api\EnomApi(new \Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline-account', 'offline-token',
        function (string $command) use ($providerError, &$connectionCommands): string {
            $connectionCommands[] = $command;
            return '<interface-response><Command>GetBalance</Command><ErrCount>1</ErrCount><errors><Err1>'.$providerError.'</Err1></errors><Done>true</Done></interface-response>';
        })), fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test']);
$logHandler = new \Monolog\Handler\TestHandler(bubble: false);
$logger = \Illuminate\Support\Facades\Log::channel()->getLogger();
$logger->pushHandler($logHandler);
try {
    $connection = (new StaffDomains($connectionOperations))->testConnection($server->id);
} finally {
    $logger->popHandler();
}
verify($connectionCommands === ['GetBalance'] && $connection['ok'] === false
    && $connection['message'] === 'Enom did not accept the request. Check the account ID, API token and IP allowlist.',
    'Connection test did not return the safe provider refusal.');
verify(!str_contains(json_encode($connection), $providerError), 'Connection response exposed provider error text.');
$logRecords = $logHandler->getRecords();
verify(count($logRecords) === 1, 'Connection refusal did not produce one diagnostic log entry.');
foreach ($logRecords as $record) {
    verify(!str_contains($record->message.' '.(string) ($record->context['exception'] ?? '').' '.json_encode($record->context), $providerError),
        'Connection test logged provider error text.');
    verify($record->message === 'Enom rejected the connection test.', 'Connection refusal did not log the fixed diagnostic.');
}

// ---------------------------------------------------------------- policies
$domain = clientDomain($client, ['domain' => 'policy-check.com']);
$operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id, 'operation_key' => 'policy-op', 'action' => 'lock', 'state' => 'succeeded',
    'currency' => 'USD', 'term' => 1, 'amount' => '0.00', 'payload' => ['locked' => true]]);
$tld = Tld::where('tld', 'com')->sole();
$records = [Domain::class => $domain, Operation::class => $operation, Tld::class => $tld];
foreach ($records as $class => $record) {
    foreach ([$super, $manager, $funder, $coster, $fundOnly, $helpdesk, $client] as $user) {
        $gate = Gate::forUser($user);
        foreach (['create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'] as $ability) {
            verify($gate->denies($ability, $class), class_basename($class).' '.$ability.' allowed for '.$user->email);
        }
        foreach (['delete', 'forceDelete', 'restore', 'replicate'] as $ability) {
            verify($gate->denies($ability, $record), class_basename($class).' '.$ability.' allowed for '.$user->email);
        }
        $manages = $user->hasPermission('admin.enom.manage');
        verify($gate->allows('viewAny', $class) === $manages && $gate->allows('view', $record) === $manages, class_basename($class).' view does not follow admin.enom.manage for '.$user->email);
        verify($gate->allows('update', $record) === ($manages && $class !== Operation::class), class_basename($class).' update wrong for '.$user->email);
    }
}
foreach ([DomainResource::class, OperationResource::class, TldResource::class] as $resource) {
    Illuminate\Support\Facades\Auth::setUser($super);
    verify(!$resource::canCreate() && !$resource::canDeleteAny() && $resource::canViewAny(), class_basename($resource).' offers create or delete to a superadmin.');
    Illuminate\Support\Facades\Auth::setUser($helpdesk);
    verify(!$resource::canViewAny(), class_basename($resource).' visible without admin.enom.manage.');
}
$app['auth']->forgetGuards();

// ---------------------------------------------------------------- StaffDomains permission matrix
$refused = function (callable $action, string $message) {
    $before = clientRows();
    try { $action(); verify(false, $message.' (allowed)'); }
    catch (AuthorizationException) { verify(clientRows() == $before, $message.' (changed data while refusing)'); }
};
// Changes: manage only.
$refused(fn () => $staffDomains->requestChange($helpdesk, $domain->id, 'lock', ['locked' => '0']), 'Helpdesk queued a registrar change');
$refused(fn () => $staffDomains->requestChange($fundOnly, $domain->id, 'lock', ['locked' => '0']), 'Fund-only staff queued a registrar change');
$change = $staffDomains->requestChange($manager, $domain->id, 'lock', ['locked' => '0']);
verify($change->state === 'queued' && (int) $change->authorized_by === $manager->id, 'Manager change was not queued as staff.');
$change->update(['state' => 'succeeded']);
$refused(fn () => $staffDomains->setRenewal($helpdesk, $domain->id, false), 'Helpdesk changed auto-renew');
// Who pays: standard and custom need manage; anything that is complimentary before or after needs fund.
$priced = clientDomain($client, ['domain' => 'who-pays.com']);
$refused(fn () => $staffDomains->setPricing($helpdesk, $priced->id, 'custom', '20.00'), 'Helpdesk changed who pays');
$customised = $staffDomains->setPricing($manager, $priced->id, 'custom', '20.00');
verify($customised->price_mode === 'custom' && (float) $priced->fresh()->service->price === 20.0, 'Manager could not set a custom price: '.json_encode([$customised->price_mode, $priced->fresh()->service->price]));
verify($staffDomains->setPricing($coster, $priced->id, 'standard')->price_mode === 'standard', 'Manager could not return to the standard price.');
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'complimentary'), 'Manager made a domain complimentary');
$refused(fn () => $staffDomains->setPricing($coster, $priced->id, 'complimentary'), 'Costs permission made a domain complimentary');
$refused(fn () => $staffDomains->setPricing($fundOnly, $priced->id, 'complimentary'), 'Fund without manage made a domain complimentary');
verify($staffDomains->setPricing($funder, $priced->id, 'complimentary')->price_mode === 'complimentary', 'Funder could not make a domain complimentary.');
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'standard'), 'Manager removed complimentary pricing');
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'inherit'), 'Manager removed complimentary pricing through inherit');
verify($staffDomains->setPricing($funder, $priced->id, 'standard')->price_mode === 'standard', 'Funder could not remove complimentary pricing.');
// Client grants: fund (with manage) only; the client default makes "inherit" complimentary.
$refused(fn () => $staffDomains->setClientComplimentary($manager, $client, true), 'Manager granted complimentary domains');
$refused(fn () => $staffDomains->setClientComplimentary($fundOnly, $client, true), 'Fund without manage granted complimentary domains');
refuses(fn () => $staffDomains->setClientComplimentary($funder, $client, true, 0), 'domain limit', 'Zero domain limit accepted.', [InvalidArgumentException::class]);
$inheriting = Domain::where('user_id', $client->id)->whereNull('price_mode')->count();
refuses(fn () => $staffDomains->setClientComplimentary($funder, $client, true, $inheriting - 1), '', 'Domain limit below the client\'s inheriting domains accepted.', [InvalidArgumentException::class, RuntimeException::class]);
$staffDomains->setClientComplimentary($funder, $client, true);
verify(Pricing::clientComplimentary($client) && Pricing::isComplimentary($domain->fresh()), 'Grant did not make inheriting domains complimentary.');
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'inherit'), 'Manager made a domain complimentary through the client default');
$refused(fn () => $staffDomains->setPricing($manager, $domain->id, 'standard'), 'Manager took an inheriting complimentary domain off the client grant');
verify($staffDomains->setPricing($funder, $priced->id, 'inherit')->price_mode === null && Pricing::isComplimentary($priced->fresh()), 'Funder could not follow the complimentary client default.');
// Renew now: invoices need manage; complimentary renewals are spending and need fund.
$refused(fn () => $staffDomains->renewNow($manager, $domain->id), 'Manager queued a complimentary renewal');
$refused(fn () => $staffDomains->renewNow($helpdesk, $domain->id), 'Helpdesk renewed a domain');
$freeRenewal = $staffDomains->renewNow($funder, $domain->id);
verify($freeRenewal instanceof Operation && $freeRenewal->funding_basis === 'complimentary' && $freeRenewal->action === 'renew' && (int) $freeRenewal->authorized_by === $funder->id, 'Funder complimentary renewal was not queued at the reseller\'s cost.');
$staffDomains->setClientComplimentary($funder, $client, false);
$paying = clientDomain($client, ['domain' => 'paying-site.com']);
$invoice = $staffDomains->renewNow($manager, $paying->id);
verify($invoice instanceof Invoice && $invoice->user_id === $client->id && $invoice->status === 'pending' && (string) $invoice->items()->sole()->price === '14.00', 'Manager renew-now did not issue the client\'s renewal invoice.');
verify($staffDomains->renewNow($manager, $paying->id)->id === $invoice->id, 'Repeated renew-now issued a second invoice for the cycle.');

// ---------------------------------------------------------------- legacy /admin/enom URLs: GET redirects, POST changes nothing
foreach ([$super, $manager] as $staff) { enomResetThrottle($staff); }
$requestsUrl = OperationResource::getUrl('index');
verify(enomHttp($manager, 'GET', '/admin/enom')->headers->get('Location') === $requestsUrl, 'Old staff overview does not redirect to Registrar requests.');
verify(enomHttp($manager, 'GET', '/admin/enom/domains/'.$paying->id)->headers->get('Location') === DomainResource::getUrl('view', ['record' => $paying->id]), 'Old domain page does not redirect to the Filament domain.');
verify(enomHttp($manager, 'GET', '/admin/enom/domains/999999')->headers->get('Location') === DomainResource::getUrl('index'), 'Unknown old domain page does not redirect to the domain list.');
verify(enomHttp($manager, 'GET', '/admin/enom/help/costs')->headers->get('Location') === 'http://enom-tests.invalid/admin/enom-setup', 'Old staff help does not redirect to Enom setup.');
foreach (['/admin/enom', '/admin/enom/domains/'.$paying->id, '/admin/enom/help'] as $uri) {
    verify(enomHttp($client, 'GET', $uri)->getStatusCode() === 403 && enomHttp($helpdesk, 'GET', $uri)->getStatusCode() === 403, 'Non-Enom user reached '.$uri);
    verify(enomRedirectPath(enomHttp(null, 'GET', $uri)) === '/login', 'Guest reached '.$uri);
}
$pendingRenewal = Operation::where('invoice_id', $invoice->id)->sole();
$legacy = [
    '/admin/enom/domains/'.$paying->id.'/policy' => ['confirmed' => '1', 'enabled' => '0'],
    '/admin/enom/domains/'.$paying->id.'/sponsorship' => ['confirmed' => '1', 'reason' => 'Gift', 'limit' => '99.00'],
    '/admin/enom/domains/'.$paying->id.'/refresh' => ['confirmed' => '1'],
    '/admin/enom/domains/'.$paying->id.'/sponsored-renewal' => ['confirmed' => '1', 'reason' => 'Gift'],
    '/admin/enom/'.$pendingRenewal->id.'/reconcile' => ['confirmed' => '1'],
    '/admin/enom/'.$pendingRenewal->id.'/readmit' => ['confirmed' => '1', 'reason' => 'Resume it'],
    '/admin/enom/'.$pendingRenewal->id.'/provider-fact' => ['confirmed' => '1'],
    '/admin/enom/'.$pendingRenewal->id.'/disposition' => ['confirmed' => '1', 'outcome' => 'provider_completed', 'financial' => 'manual_review_required', 'reason' => 'Closing it by hand', 'fact_digest' => str_repeat('a', 64)],
    '/admin/enom/'.$pendingRenewal->id.'/fund' => ['confirmed' => '1', 'reason' => 'Fund it', 'amount' => '14.00', 'currency' => 'USD'],
];
$commands = count($providerCommands);
$before = clientRows();
foreach ($legacy as $uri => $input) {
    enomResetThrottle($super);
    $response = enomHttp($super, 'POST', $uri, $input + ['_request_key' => str_repeat('d', 64)]);
    $target = str_contains($uri, '/domains/') ? DomainResource::getUrl('view', ['record' => $paying->id]) : $requestsUrl;
    verify($response->getStatusCode() === 302 && $response->headers->get('Location') === $target, 'Old staff POST '.$uri.' did not redirect into Filament.');
    verify(enomHttp($client, 'POST', $uri, $input)->getStatusCode() === 403, 'Client reached old staff POST '.$uri);
}
verify(clientRows() == $before && count($providerCommands) === $commands, 'Old staff POSTs changed data or called Enom.');
verify($pendingRenewal->fresh()->state === 'awaiting_payment' && $pendingRenewal->fresh()->funding_basis === 'paid_invoice', 'Old funding POST funded an unpaid renewal.');

// ---------------------------------------------------------------- Filament pages: no costs or encrypted columns in state or HTML
$secret = ['payload' => 'PAYLOAD-SECRET-7Q', 'policy' => 'POLICY-SECRET-7Q', 'cost' => 'COST-SECRET-7Q', 'snapshot' => 'SNAPSHOT-SECRET-7Q', 'observation' => 'OBSERVATION-SECRET-7Q', 'renewal' => 'RENEWAL-SECRET-7Q'];
$domainForAdmin = clientDomain($client, ['domain' => 'admin-view.com', 'observation' => ['note' => $secret['observation']], 'renewal_policy' => ['enabled' => true, 'note' => $secret['renewal']]],
    ['contacts' => ['registrant' => ['first_name' => 'Staff', 'last_name' => 'Admin', 'email_address' => 'staff.contact@example.com', 'note' => $secret['snapshot']]]]);
$charged = Operation::create(['domain_id' => $domainForAdmin->id, 'service_id' => $domainForAdmin->service_id, 'operation_key' => 'admin-view-renew', 'action' => 'renew', 'state' => 'succeeded',
    'funding_basis' => 'paid_invoice', 'currency' => 'USD', 'term' => 1, 'amount' => '14.00', 'payload' => ['note' => $secret['payload']], 'policy' => ['note' => $secret['policy']],
    'cost' => ['execution' => ['command' => 'Extend', 'status' => 'success', 'orderid' => '55'], 'actual' => ['state' => 'confirmed', 'amount' => '8.47', 'note' => $secret['cost']]]]);
$encrypted = (string) Illuminate\Support\Facades\DB::table('enom_operations')->where('id', $charged->id)->value('cost');
/** Rendered HTML and the Livewire snapshot (component state sent to the browser). */
$render = function (User $user, string $component, array $parameters = []) {
    Illuminate\Support\Facades\Auth::setUser($user);
    $testable = Livewire::actingAs($user)->test($component, $parameters);
    return [$testable->html(), json_encode($testable->snapshot)];
};
$privateIn = function (string $body) use ($secret, $encrypted): array {
    $found = array_values(array_filter($secret, fn ($marker) => str_contains($body, $marker)));
    if (str_contains($body, substr($encrypted, 0, 40))) { $found[] = 'ciphertext'; }
    return $found;
};
$noCost = function (string $html, array $values): bool {
    $html = preg_replace('#<(svg|style|script)\b.*?</\1>#si', '', $html);
    foreach ($values as $value) { if (str_contains($html, '$'.$value) || preg_match('/>\s*'.preg_quote($value, '/').'\s*</', $html)) { return false; } }
    return true;
};
// The TLD table has bulk actions, whose selection counter needs PHP intl; it is checked through its table definition below.
$tldPage = extension_loaded('intl') ? [[ListTlds::class, []]] : [];
foreach ([[ListDomains::class, []], [ListOperations::class, []], [ViewDomain::class, ['record' => $domainForAdmin->id]], ...$tldPage] as [$component, $parameters]) {
    foreach (['manager' => $manager, 'costs' => $coster] as $label => $user) {
        [$html, $state] = $render($user, $component, $parameters);
        $rendered[class_basename($component)][$label] = $html;
        verify(!str_contains($html, '403 Forbidden') && !str_contains($html, 'An Error Occurred'), class_basename($component).' did not render for '.$label);
        verify($privateIn($html.$state) === [], class_basename($component).' exposes encrypted columns to '.$label.': '.json_encode($privateIn($html.$state)));
        if ($label === 'manager') {
            verify($noCost($html, ['8.47', '8.91', '8.92', '8.93', '9.81', '9.82', '9.83']) && $noCost($state, ['8.47', '8.91', '8.92', '8.93']), class_basename($component).' shows Enom costs without admin.enom.costs.');
        }
    }
}
foreach (['ListDomains', 'ListOperations', 'ViewDomain'] as $page) {
    verify(str_contains($rendered[$page]['manager'], 'admin-view.com') && str_contains($rendered[$page]['costs'], 'admin-view.com'), $page.' did not render the domain for staff.');
}
[$listHtml] = $render($manager, ListDomains::class);
verify(str_contains($listHtml, 'admin-view.com') && str_contains($listHtml, 'who-pays.com'), 'Domain list does not render its rows.');
/** What the TLD table shows one staff member for one ending: visible columns (state, text, description, tooltip) and actions. */
$tldTable = function (User $user, Tld $record): array {
    Illuminate\Support\Facades\Auth::setUser($user);
    $page = app(ListTlds::class);
    $page->bootedInteractsWithTable();
    $table = $page->getTable();
    $out = ['columns' => [], 'record_actions' => [], 'bulk_actions' => []];
    foreach ($table->getColumns() as $name => $column) {
        if ($column->isHidden()) { continue; }
        $column->record($record);
        $state = $column->getState();
        $out['columns'][$name] = [is_scalar($state) || $state === null ? $state : json_encode($state),
            method_exists($column, 'formatState') ? (string) $column->formatState($state) : null,
            method_exists($column, 'getDescriptionBelow') ? (string) $column->getDescriptionBelow() : null,
            method_exists($column, 'getTooltip') ? (string) $column->getTooltip($state) : null];
    }
    foreach ($table->getRecordActions() as $action) { $action->record($record); if (!$action->isHidden()) { $out['record_actions'][] = $action->getName(); } }
    foreach ($table->getToolbarActions() as $group) { foreach (method_exists($group, 'getActions') ? $group->getActions() : [$group] as $action) { if (!$action->isHidden()) { $out['bulk_actions'][] = $action->getName(); } } }
    return $out;
};
$markup = Tld::where('tld', 'net')->sole();
$markup->update(['pricing_mode' => 'markup_percent', 'markup' => '63.10']);
foreach (['com' => $tld->fresh(), 'net' => $markup->fresh()] as $ending => $record) {
    $plain = $tldTable($manager, $record);
    $withCosts = $tldTable($coster, $record);
    verify(!str_contains(json_encode($plain), '8.9') && !str_contains(json_encode($plain), '9.8') && !str_contains(json_encode($plain), '63.1') && !isset($plain['columns']['margin']), '.'.$ending.' TLD row shows costs or markup size without admin.enom.costs: '.json_encode($plain));
    verify($plain['record_actions'] === [] && $plain['bulk_actions'] === ['enable', 'disable'], '.'.$ending.' pricing actions offered without admin.enom.costs: '.json_encode($plain));
    verify(isset($withCosts['columns']['margin']) && $withCosts['record_actions'] === ['edit'] && $withCosts['bulk_actions'] === ['enable', 'disable', 'markup', 'fixed'] && str_contains(json_encode($withCosts), 'Cost $'), '.'.$ending.' costs hidden from admin.enom.costs: '.json_encode($withCosts));
}
verify($tldTable($manager, $markup->fresh())['columns']['tld'][2] === 'Markup' && str_contains($tldTable($coster, $markup->fresh())['columns']['tld'][2], '63.1% markup'), 'Markup size is not limited to admin.enom.costs.');
foreach ([[ListDomains::class, []], [ListOperations::class, []], ...$tldPage, [ViewDomain::class, ['record' => $domainForAdmin->id]]] as [$component, $parameters]) {
    // Livewire's test request renders the abort(403) as an error page.
    try { [$html, $state] = $render($helpdesk, $component, $parameters); $denied = str_contains($html, '403 Forbidden') && !str_contains($html, 'admin-view.com') && $privateIn($html.$state) === []; }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { $denied = $exception->getStatusCode() === 403; }
    verify($denied, class_basename($component).' rendered without admin.enom.manage.');
}
// Global search: domain, client and status facts only.
Illuminate\Support\Facades\Auth::setUser($manager);
$details = DomainResource::getGlobalSearchResultDetails($domainForAdmin->fresh());
verify(array_keys($details) === ['Client', 'Status', 'Expires'] && $privateIn(json_encode($details)) === [] && !preg_match('/\$|8\.\d\d/', json_encode($details)), 'Global search details carry more than client, status and expiry: '.json_encode($details));
verify(!array_intersect(DomainResource::getGloballySearchableAttributes(), ['snapshot', 'renewal_policy', 'observation', 'sponsorship', 'provider_domain_id']) && !OperationResource::canGloballySearch(), 'Global search reaches private columns or registrar requests.');
// Models never serialize their encrypted or cost columns.
verify(!array_intersect(array_keys($domainForAdmin->fresh()->toArray()), ['snapshot', 'renewal_policy', 'sponsorship', 'observation', 'provider_domain_id'])
    && !array_intersect(array_keys($charged->fresh()->toArray()), ['payload', 'policy', 'cost', 'funding_reason', 'authorized_by'])
    && !array_intersect(array_keys($tld->fresh()->toArray()), ['cost_register', 'cost_renew', 'cost_transfer']), 'A model serializes private columns.');
$app['auth']->forgetGuards();

echo 'Admin and staff: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; real routes, Filament via Livewire, SQLite in memory.'.PHP_EOL;
$successful = true;

<?php
// Staff side: policies deny create/delete and require admin.enom.manage; StaffDomains checks manage, fund and
// costs per action; the Filament pages
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
$refused(fn () => $staffDomains->setPricing($helpdesk, $priced->id, 'standard'), 'Helpdesk changed who pays');
verify($staffDomains->setPricing($manager, $priced->id, 'standard')->price_mode === 'standard', 'Manager could not set standard renewal.');
refuses(fn()=> $staffDomains->setPricing($manager,$priced->id,'custom'),'standard or complimentary','Custom pricing remains available.',[InvalidArgumentException::class]);
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'complimentary'), 'Manager made a domain complimentary');
$refused(fn () => $staffDomains->setPricing($coster, $priced->id, 'complimentary'), 'Costs permission made a domain complimentary');
$refused(fn () => $staffDomains->setPricing($fundOnly, $priced->id, 'complimentary'), 'Fund without manage made a domain complimentary');
verify($staffDomains->setPricing($funder, $priced->id, 'complimentary')->price_mode === 'complimentary', 'Funder could not make a domain complimentary.');
$refused(fn () => $staffDomains->setPricing($manager, $priced->id, 'standard'), 'Manager removed complimentary pricing');
verify($staffDomains->setPricing($funder, $priced->id, 'standard')->price_mode === 'standard', 'Funder could not remove complimentary pricing.');
$staffDomains->setPricing($funder, $domain->id, 'complimentary');
// Renew now: invoices need manage; complimentary renewals are spending and need fund.
$refused(fn () => $staffDomains->renewNow($manager, $domain->id), 'Manager queued a complimentary renewal');
$refused(fn () => $staffDomains->renewNow($helpdesk, $domain->id), 'Helpdesk renewed a domain');
$freeRenewal = $staffDomains->renewNow($funder, $domain->id);
verify($freeRenewal instanceof Operation && $freeRenewal->funding_basis === 'complimentary' && $freeRenewal->action === 'renew' && (int) $freeRenewal->authorized_by === $funder->id, 'Funder complimentary renewal was not queued at the reseller\'s cost.');
$staffDomains->setPricing($funder, $domain->id, 'standard');
$paying = clientDomain($client, ['domain' => 'paying-site.com']);
$invoice = $staffDomains->renewNow($manager, $paying->id);
verify($invoice instanceof Invoice && $invoice->user_id === $client->id && $invoice->status === 'pending' && (string) $invoice->items()->sole()->price === '14.00', 'Manager renew-now did not issue the client\'s renewal invoice.');
verify($staffDomains->renewNow($manager, $paying->id)->id === $invoice->id, 'Repeated renew-now issued a second invoice for the cycle.');

// The real header action must reload both its record and cached facts after a registrar read.
$refreshDomain = clientDomain($client, ['domain' => 'staff-refresh.com']);
$refreshCommands = [];
$refreshOperations = new \Paymenter\Extensions\Servers\Enom\Services\DomainOperations(
    new \Paymenter\Extensions\Servers\Enom\Api\EnomApi(new \Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline-account', 'offline-token',
        function (string $command) use (&$refreshCommands): string {
            $refreshCommands[] = $command;
            return enomXml($command, match ($command) {
                'GetDomainInfo' => '<GetDomainInfo><domainname>staff-refresh.com</domainname></GetDomainInfo>',
                'GetDNS' => '<dns>ns1.refreshed.example.com</dns><dns>ns2.refreshed.example.com</dns><UseDNS>custom</UseDNS>',
                'GetRegLock' => '<reg-lock>0</reg-lock>',
                default => '',
            });
        })), fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test']);
$app->instance(StaffDomains::class, new StaffDomains($refreshOperations));
try {
    $refreshPage = Livewire::actingAs($manager)->test(ViewDomain::class, ['record' => $refreshDomain->id]);
    verify(str_contains($refreshPage->html(), 'ns1.example-host.com'), 'Staff refresh did not start with cached nameservers.');
    $refreshPage->callAction('refresh');
    verify($refreshCommands === ['GetDomainInfo', 'GetContacts', 'GetDNS', 'GetRegLock', 'GetWPPSInfo', 'GetHosts', 'GetDomainSRVHosts', 'GetDnsSec', 'GetRenew'],
        'Staff refresh action did not execute the real bounded registrar read.');
    verify(str_contains($refreshPage->html(), 'ns1.refreshed.example.com') && !str_contains($refreshPage->html(), 'ns1.example-host.com'),
        'Staff refresh left stale record or cached facts on the page.');
    verify($refreshPage->instance()->getRecord()->snapshot['locked'] === false, 'Staff refresh did not reload its record.');
} finally {
    $app->instance(StaffDomains::class, $staffDomains);
    $app['auth']->forgetGuards();
}

// A failed registrar read keeps the cached record and reports the failure.
$failedDomain = clientDomain($client, ['domain' => 'staff-refresh-failed.com']);
$failedSnapshot = $failedDomain->fresh()->snapshot;
$app->instance(StaffDomains::class, new StaffDomains(new \Paymenter\Extensions\Servers\Enom\Services\DomainOperations(
    new \Paymenter\Extensions\Servers\Enom\Api\EnomApi(new \Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline-account', 'offline-token',
        function (string $command): string { throw new RuntimeException('Injected registrar transport failure'); })),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test'])));
session()->forget('filament.notifications');
try {
    $failedPage = Livewire::actingAs($manager)->test(ViewDomain::class, ['record' => $failedDomain->id]);
    $failedPage->callAction('refresh');
    $failedNotice = collect(session('filament.claimed_notifications', session('filament.notifications', [])))->last();
    verify(($failedNotice['status'] ?? null) === 'danger', 'Failed staff refresh did not report the failure.');
    verify($failedDomain->fresh()->snapshot === $failedSnapshot && str_contains($failedPage->html(), 'ns1.example-host.com'),
        'Failed staff refresh changed the cached record.');
} finally {
    $app->instance(StaffDomains::class, $staffDomains);
    $app['auth']->forgetGuards();
}

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
    verify($plain['record_actions'] === [] && $plain['bulk_actions'] === ['disable'], '.'.$ending.' pricing actions offered without admin.enom.costs: '.json_encode($plain));
    verify(isset($withCosts['columns']['margin']) && $withCosts['record_actions'] === ['edit'] && $withCosts['bulk_actions'] === ['disable', 'default'] && str_contains(json_encode($withCosts), 'Cost $'), '.'.$ending.' costs hidden from admin.enom.costs: '.json_encode($withCosts));
}
verify($tldTable($manager, $markup->fresh())['columns']['tld'][2] === 'Own rule' && $tldTable($manager, $markup->fresh())['columns']['tld'][3] === ''
    && $tldTable($coster, $markup->fresh())['columns']['tld'][3] === 'Own rule: Cost + percent', 'Markup size is not limited to admin.enom.costs.');
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
verify(!array_intersect(DomainResource::getGloballySearchableAttributes(), ['snapshot', 'renewal_policy', 'observation', 'provider_domain_id']) && !OperationResource::canGloballySearch(), 'Global search reaches private columns or registrar requests.');
// Models never serialize their encrypted or cost columns.
verify(!array_intersect(array_keys($domainForAdmin->fresh()->toArray()), ['snapshot', 'renewal_policy', 'observation', 'provider_domain_id'])
    && !array_intersect(array_keys($charged->fresh()->toArray()), ['payload', 'policy', 'cost', 'funding_reason', 'authorized_by'])
    && !array_intersect(array_keys($tld->fresh()->toArray()), ['cost_register_terms', 'cost_renew', 'cost_transfer']), 'A model serializes private columns.');
Carbon\Carbon::setTestNow('2026-10-17 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
// Staff see the verified code deadline and recovery reason, never the transfer code or encrypted policy.
$codeRequest = Operation::create(['domain_id' => $domainForAdmin->id, 'service_id' => $domainForAdmin->service_id,
    'operation_key' => 'admin-transfer-code', 'action' => 'transfer', 'state' => 'awaiting_code', 'term' => 1,
    'amount' => '13.00', 'currency' => 'USD', 'funding_basis' => 'paid_invoice',
    'policy' => ['paid_at' => '2026-10-17T12:00:00Z'], 'payload' => ['epp' => 'PRIVATE-TRANSFER-CODE']]);
[$html, $state] = $render($manager, ListOperations::class, []);
verify(str_contains($html, 'Code deadline') && str_contains($html, 'Nov 16, 2026, 12:00 UTC')
    && !str_contains($html.$state, 'PRIVATE-TRANSFER-CODE') && !str_contains($state, '2026-10-17T12:00:00Z'), 'Staff deadline missing or private transfer fields leaked.');
$page = app(ListOperations::class); $page->bootedInteractsWithTable();
$paidColumn = $page->getTable()->getColumn('transfer_paid_at'); $paidColumn->record($codeRequest);
verify($paidColumn->getState()->format('c') === '2026-10-17T12:00:00+00:00', 'Staff payment date did not use the verified transfer clock.');
$labels = \Paymenter\Extensions\Servers\Enom\Admin\Support\Labels::class;
// Five display lookups per queued row share settings only within the current request, per server.
$otherServer = App\Models\Server::create(['name' => 'Other queue', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$otherServer->settings()->create(['key' => 'operations_enabled', 'value' => '1']);
$originalRequest = request();
$queueQueries = function (int $rows, bool $processing) use ($app, $labels, $server, $otherServer): int {
    $app->instance('request', Illuminate\Http\Request::create('/admin/enom/operations'));
    Illuminate\Support\Facades\DB::enableQueryLog();
    Illuminate\Support\Facades\DB::flushQueryLog();
    $correct = true;
    for ($row = 0; $row < $rows; $row++) {
        foreach ([$server->id => $processing, $otherServer->id => true] as $serverId => $enabled) {
            $queued = (new Operation(['id' => $row + 1, 'state' => 'queued']))->setRelation('domain', new Domain(['server_id' => $serverId]));
            for ($display = 0; $display < 5; $display++) { $correct = $labels::notSent($queued) === !$enabled && $correct; }
        }
    }
    $queries = count(Illuminate\Support\Facades\DB::getQueryLog());
    Illuminate\Support\Facades\DB::disableQueryLog();
    echo 'Queued display: '.$rows.' rows per server, processing '.($processing ? 'on' : 'off').', '.$queries.' queries.'.PHP_EOL;
    verify($correct, 'Queued display ignored current request or server flag: '.($processing ? 'on' : 'off'));
    return $queries;
};
try {
    serverSetting('operations_enabled', '0');
    $singleQueries = $queueQueries(1, false);
    verify($singleQueries > 0 && $singleQueries <= 4, 'Single queued display did not read bounded server settings.');
    serverSetting('operations_enabled', '1');
    verify($queueQueries(20, true) === $singleQueries, 'Fresh request did not read bounded current settings.');
    serverSetting('operations_enabled', '0');
    verify($queueQueries(20, false) === $singleQueries, 'Queued flag queries scale with row count.');
} finally {
    $app->instance('request', $originalRequest);
    serverSetting('operations_enabled', '1');
    $otherServer->settings()->delete(); $otherServer->delete();
}

verify(str_contains($labels::stateHint('held', new Operation(['safe_code' => 'contact_details_required'])), 'Needs account details'), 'Contact hold has no staff recovery instruction.');
verify($labels::money(-4) === '-$4.00' && $labels::money('8.955') === '$8.955' && $labels::money(-1.5, 'EUR') === '-1.50 EUR', 'Negative amounts put the sign after the currency symbol.');
verify(str_contains($labels::stateHint('uncertain', new Operation(['action' => 'nameservers', 'operation_key' => 'initial-nameservers:123'])), 'Registration succeeded'), 'Nameserver failure hides the successful registration.');
// Non-billable request kinds ignore the legacy/default invoice funding basis on both staff tables.
$fundingColumn = $page->getTable()->getColumn('funding_basis');
foreach ([...\Paymenter\Extensions\Servers\Enom\Services\DomainManager::ACTIONS, 'provider_renewal_disable',
    ...\Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::FINANCIAL_ACTIONS] as $action) {
    foreach (['paid_invoice', 'complimentary', 'non_billable_management'] as $basis) {
        $request = new Operation(['action' => $action, 'funding_basis' => $basis, 'amount' => '0.00']);
        $request->id = 100000 + $checks;
        $fundingColumn->record($request);
        $billable = in_array($action, ['register', 'transfer', 'renew', 'privacy_purchase', 'privacy_renew'], true);
        $expected = $billable ? $labels::funding($basis) : 'No charge';
        verify($fundingColumn->formatState($fundingColumn->getState()) === $expected, 'Who pays mislabels '.$action.' / '.$basis);
        verify($billable || $fundingColumn->getColor($fundingColumn->getState()) === 'gray', 'Non-billable request uses an invoice/complimentary color: '.$action);
    }
}
// Pending requests that cannot be reconciled remain on every staff-attention surface.
Illuminate\Support\Facades\Auth::setUser($manager);
$labels = \Paymenter\Extensions\Servers\Enom\Admin\Support\Labels::class;
$staff = \Paymenter\Extensions\Servers\Enom\Admin\Support\Staff::class;
$facts = \Paymenter\Extensions\Servers\Enom\Admin\Support\DomainFacts::class;
foreach (['register', 'renew', 'transfer', 'epp_sync', 'epp_email'] as $action) {
    $stuckDomain = clientDomain($client, ['domain' => 'stuck-'.$action.'.com']);
    $beforeBadge = (int) OperationResource::getNavigationBadge();
    $beforeDomains = (int) DomainResource::getNavigationBadge();
    $stuck = Operation::create(['domain_id' => $stuckDomain->id, 'service_id' => $stuckDomain->service_id,
        'operation_key' => 'stuck-'.$action, 'action' => $action, 'state' => 'pending', 'provider_order_id' => null,
        'term' => 1, 'amount' => '0.00', 'currency' => 'USD']);
    $tabs = app(ListOperations::class)->getTabs();
    verify($tabs['attention']->modifyQuery(Operation::query())->whereKey($stuck->id)->exists()
        && !$tabs['progress']->modifyQuery(Operation::query())->whereKey($stuck->id)->exists(), 'Stuck request is missing from attention or duplicated in progress: '.$action);
    verify(app(ListOperations::class)->getDefaultActiveTab() === 'attention', 'Stuck request does not open the attention tab.');
    verify((int) OperationResource::getNavigationBadge() === $beforeBadge + 1 && (int) DomainResource::getNavigationBadge() === $beforeDomains + 1,
        'Stuck request is missing from sidebar badges: '.$action);
    $stats = (new ReflectionMethod(\Paymenter\Extensions\Servers\Enom\Admin\Pages\Widgets\DomainCounts::class, 'getStats'))
        ->invoke(app(\Paymenter\Extensions\Servers\Enom\Admin\Pages\Widgets\DomainCounts::class));
    verify((int) $stats[2]->getValue() === count($staff::attentionDomainIds())
        && $stats[2]->getDescription() === ($n = $labels::needsStaff(Operation::query())->count()).' registrar '.($n === 1 ? 'request' : 'requests'), 'Dashboard misses stuck request: '.$action);
    $facts::forget($stuckDomain->id);
    verify($facts::for($stuckDomain->fresh())['attention']->contains('id', $stuck->id), 'Domain callout misses stuck request: '.$action);
    [$callout] = $render($manager, ViewDomain::class, ['record' => $stuckDomain->id]);
    verify(str_contains($callout, 'Needs attention') && str_contains($callout, '#'.$stuck->id.': not confirmed.'), 'Rendered domain callout misses stuck request: '.$action);
    foreach ([null, '', '0', '12345'] as $orderId) {
        $stuck->update(['provider_order_id' => $orderId]);
        $unconfirmed = $orderId !== '12345' || in_array($action, ['epp_sync', 'epp_email'], true);
        verify($labels::notConfirmed($stuck) === $unconfirmed
            && $labels::needsStaff(Operation::query())->whereKey($stuck->id)->exists() === $unconfirmed,
            'Pending display and staff-attention predicates differ: '.$action.' / '.$orderId);
        verify($labels::requestState($stuck) === ($unconfirmed ? 'Not confirmed' : 'In progress at Enom')
            && $labels::stateHint('pending', $stuck) === ($unconfirmed ? 'No reply from Enom. Check with Enom before retrying.' : 'Enom is still working on it.'),
            'Pending request label or hint claims the wrong provider status: '.$action.' / '.$orderId);
    }
    $stuck->update(['provider_order_id' => null]);
    foreach ([[ListOperations::class, []], [\Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager::class,
        ['ownerRecord' => $stuckDomain, 'pageClass' => ViewDomain::class]]] as [$component, $parameters]) {
        [$html] = $render($manager, $component, $parameters);
        verify(str_contains($html, 'Not confirmed') && str_contains($html, 'No reply from Enom. Check with Enom before retrying.'), 'Staff request table lacks shared pending wording: '.$action.' / '.$component);
    }
    verify(str_contains($callout, 'No reply from Enom. Check with Enom before retrying.'), 'Domain callout lacks pending guidance: '.$action);
    if (in_array($action, ['register', 'renew', 'transfer'], true)) {
        $stuck->update(['provider_order_id' => '12345']);
        verify(!$labels::needsStaff(Operation::query())->whereKey($stuck->id)->exists()
            && $labels::inGroup(Operation::query(), 'progress')->whereKey($stuck->id)->exists(), 'Readable pending request is not solely in progress: '.$action);
    }
}
// Every state, including both held kinds and pending variants, belongs to exactly one tab group.
foreach (array_keys($labels::stateOptions()) as $state) {
    foreach ([null, 'contact_details_required'] as $code) {
        $stuck->update(['state' => $state, 'safe_code' => $code]);
        $groups = array_filter(array_keys($labels::STATE_GROUPS), fn ($group) => $labels::inGroup(Operation::query(), $group)->whereKey($stuck->id)->exists());
        verify(count($groups) === 1, 'Tab groups overlap or omit '.$state.' / '.$code);
    }
}
$app['auth']->forgetGuards();

echo 'Admin and staff: '.$checks.' passed; real routes, Filament via Livewire, SQLite in memory.'.PHP_EOL;
$successful = true;

<?php
$enomTestLabel = 'registration-setup';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-cart-order.php';

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup;

// Core invoice-created mail is unrelated here; the native flag avoids repeated shutdown mail in one test process.
Invoice::creating(function (Invoice $invoice) { $invoice->send_create_email = false; });
Carbon\Carbon::setTestNow('2026-10-17 12:00:00');
Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
RegistrationSetup::boot();
app('router')->getRoutes()->refreshNameLookups();
$commands = [];
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', function ($command, $params) use (&$commands, &$setupAction, &$lastPurchaseName) {
    $commands[] = [$command, $params];
    $name = ($params['SLD'] ?? '').'.'.($params['TLD'] ?? '');
    if ($command === 'Check') {
        return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.(($setupAction ?? 'register') === 'transfer' ? '211' : '210').'</RRPCode><Properties><Transferable>true</Transferable></Properties><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Prices><Currency>USD</Currency><Registration>8.95</Registration><Renewal>9.95</Renewal></Prices></Domain></Domains>');
    }
    if ($command === 'PE_GetProductPrice') {
        return enomXml($command, '<productprice><productenabled>true</productenabled><price>8.95</price></productprice>');
    }
    if ($command === 'Purchase') {
        $lastPurchaseName = $name;
        return enomXml($command, '<RRPCode>200</RRPCode><OrderID>12345</OrderID><DomainInfo><RegistryExpDate>2027-10-17</RegistryExpDate></DomainInfo>');
    }
    if ($command === 'GetDomainExp') { return enomXml($command, '<ExpirationDate>2027-10-17</ExpirationDate>'); }
    if ($command === 'GetDomainInfo') {
        return enomXml($command, '<GetDomainInfo><domainname domainnameid="4242">'.$name.'</domainname><status><expiration>2027-10-17</expiration><belongs-to>offline-account</belongs-to></status></GetDomainInfo>');
    }
    if ($command === 'GetOrderDetail') { return enomXml($command, '<Order><Result>True</Result><OrderID>12345</OrderID><OrderDetail><Description>'.$lastPurchaseName.'</Description><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><ExpiryDatetime>2027-10-17</ExpiryDatetime></OrderDetail></Order>'); }
    if ($command === 'ModifyNS') {
        return '<interface-response><Command>ModifyNS</Command><ErrCount>1</ErrCount><RRPCode>541</RRPCode><errors><Err1>Nameservers refused</Err1></errors><Done>true</Done></interface-response>';
    }
    throw new RuntimeException('Unexpected registrar request '.$command);
})), fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true, 'environment' => 'test']);
$app->instance(DomainOperations::class, $operations);
$server = App\Models\Server::create(['name' => 'Test registrar', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test', 'operations_enabled' => '1',
    'default_nameservers' => "NS1.Example.com.\nns2.example.com\nns1.example.com"] as $key => $value) {
    $server->settings()->create(['key' => $key, 'value' => $value]);
}
$tld = enomTestTld(['server_id' => $server->id, 'tld' => 'com']);
$category = app(Paymenter\Extensions\Servers\Enom\Services\Catalogue::class)->productFor($tld, 'register')->category;
$app->instance(Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class, new Paymenter\Extensions\Servers\Enom\Services\TransferOrders(new Paymenter\Extensions\Servers\Enom\Services\Rdap(fn () => throw new RuntimeException('offline'))));
$role = App\Models\Role::create(['name' => 'Domain manager', 'permissions' => ['admin.enom.manage']]);
$staff = User::create(['first_name' => 'Support', 'last_name' => 'Staff', 'email' => 'support@example.com', 'password' => 'synthetic', 'role_id' => $role->id]);
$other = User::create(['first_name' => 'Other', 'last_name' => 'Owner', 'email' => 'other@example.com', 'password' => 'synthetic', 'email_verified_at' => now()]);
$owner = User::create(['first_name' => 'Contact', 'last_name' => 'Owner', 'email' => 'contact@example.com', 'password' => 'synthetic', 'email_verified_at' => now()]);
foreach (['company_name' => 'Test Organization', 'address' => '1 Main Street', 'address2' => 'Suite 2', 'city' => 'Seattle', 'state' => 'WA',
    'zip' => '98101', 'country' => 'United States', 'phone' => '(206) 555-0100'] as $key => $value) {
    $owner->properties()->create(['key' => $key, 'name' => ucfirst($key), 'value' => $value]);
}
function setupProperty(User $user, string $key, string $value): void {
    $user->properties()->updateOrCreate(['key' => $key], ['name' => ucfirst($key), 'value' => $value]);
}
/** Native checkout binds every financial identity before a deliberately varied snapshot. */
function setupOrder(User $user, string $name, array $payload, string $action = 'register', bool $paid = true): Operation {
    global $commands, $setupAction;
    $setupAction = $action;
    $order = enomCartOrder($user, $name, $action);
    $operation = $order['operation'];
    $operation->update(['payload' => $payload]);
    if ($paid) { ExtensionHelper::addPayment($order['invoice']->id, null, amount: (float) $order['item']->price, isCreditTransaction: true); }
    $commands = []; // Admission reads precede the contact-only assertions.
    return $operation->fresh();
}
function contactError(Operation $operation, string $key): void {
    try { RegistrationSetup::validate($operation); }
    catch (InputValidationException $error) {
        verify(isset($error->errors[$key]), 'Missing safe contact error '.$key);
        verify(!str_contains($error->getMessage(), 'private-value'), 'Contact error exposed account data.');
        return;
    }
    throw new RuntimeException('Invalid contacts passed validation: '.$key);
}

$payload = RegistrationSetup::payload($owner, $server->id, 'register');
$contact = $payload['contacts']['registrant'];
verify(array_keys($payload['contacts']) === ['registrant', 'admin', 'tech', 'auxbilling'], 'Four contact roles were not copied.');
verify($contact === ['first_name' => 'Contact', 'last_name' => 'Owner', 'organization_name' => 'Test Organization', 'address1' => '1 Main Street',
    'address2' => 'Suite 2', 'city' => 'Seattle', 'state_province' => 'WA', 'postal_code' => '98101', 'country' => 'US',
    'email_address' => 'contact@example.com', 'phone' => '+1.2065550100'], 'Native property mapping or normalization differs.');
verify(count(array_unique(array_map('serialize', $payload['contacts']))) === 1, 'Contact roles differ.');
verify($payload['nameservers'] === ['ns1.example.com', 'ns2.example.com'], 'Default nameservers were not normalized.');
verify(RegistrationSetup::payload($owner, $server->id, 'transfer')['nameservers'] === [], 'Transfer copied registration nameservers.');
RegistrationSetup::validate(new Operation(['action' => 'register', 'payload' => $payload]));
verify(true, 'Complete mapped contacts did not pass.');

// Country labels must identify exactly one ISO key; unknown and ambiguous labels fail closed.
setupProperty($owner, 'country', 'ca'); setupProperty($owner, 'phone', '416 555 0100');
verify(RegistrationSetup::payload($owner, $server->id, 'register')['contacts']['registrant']['phone'] === '+1.4165550100', 'Canadian phone normalization differs.');
setupProperty($owner, 'country', 'United Kingdom'); setupProperty($owner, 'phone', '+44 20 7946 0123');
verify(RegistrationSetup::payload($owner, $server->id, 'register')['contacts']['tech']['phone'] === '+44.2079460123', 'International phone normalization differs.');
$countries = config('app.countries');
config(['app.countries' => ['AA' => 'Duplicate', 'BB' => 'Duplicate']]); setupProperty($owner, 'country', 'Duplicate');
verify(RegistrationSetup::payload($owner, $server->id, 'register')['contacts']['admin']['country'] === '', 'Ambiguous country label was guessed.');
config(['app.countries' => $countries]);
setupProperty($owner, 'country', 'private-value');
contactError(new Operation(['action' => 'register', 'payload' => RegistrationSetup::payload($owner, $server->id, 'register')]), 'contacts.registrant.country');
setupProperty($owner, 'country', 'United States'); setupProperty($owner, 'phone', '(206) 555-0100');
foreach (['registrant', 'admin', 'tech', 'auxbilling'] as $roleName) {
    foreach (['first_name', 'last_name', 'address1', 'city', 'postal_code', 'country', 'email_address', 'phone', 'state_province'] as $field) {
        $invalid = $payload;
        $invalid['contacts'][$roleName][$field] = '';
        contactError(new Operation(['action' => 'register', 'payload' => $invalid]), 'contacts.'.$roleName.'.'.$field);
    }
    foreach (['email_address' => 'private-value', 'phone' => 'private-value', 'address1' => "private-value\n", 'city' => str_repeat('x', 256)] as $field => $value) {
        $invalid = $payload; $invalid['contacts'][$roleName][$field] = $value;
        contactError(new Operation(['action' => 'transfer', 'payload' => $invalid]), 'contacts.'.$roleName.'.'.$field);
    }
    $invalid = $payload; unset($invalid['contacts'][$roleName]);
    contactError(new Operation(['action' => 'register', 'payload' => $invalid]), 'contacts.'.$roleName.'.first_name');
}
setupProperty($owner, 'phone', "\t2065550100");
contactError(new Operation(['action' => 'register', 'payload' => RegistrationSetup::payload($owner, $server->id, 'register')]), 'contacts.registrant.phone');
setupProperty($owner, 'phone', '(206) 555-0100');
foreach (['US', 'CA', 'AU'] as $country) {
    $missingState = $payload;
    $missingState['contacts']['registrant']['country'] = $country;
    $missingState['contacts']['registrant']['state_province'] = '';
    contactError(new Operation(['action' => 'register', 'payload' => $missingState]), 'contacts.registrant.state_province');
}
$noState = $payload;
$noState['contacts']['registrant']['country'] = 'GB';
$noState['contacts']['registrant']['state_province'] = '';
RegistrationSetup::validate(new Operation(['action' => 'register', 'payload' => $noState]));
verify(true, 'A country without a required state was refused.');
$server->settings()->where('key', 'default_nameservers')->update(['value' => '']);
verify(RegistrationSetup::payload($owner, $server->id, 'register')['nameservers'] === [], 'Blank defaults must use registrar DNS.');
foreach (['ns1.example.com', 'bad-name ns2.example.com', '192.0.2.1 ns2.example.com'] as $nameservers) {
    $server->settings()->where('key', 'default_nameservers')->update(['value' => $nameservers]);
    refuses(fn () => RegistrationSetup::payload($owner, $server->id, 'register'), '', 'Invalid default nameservers passed.');
}
$server->settings()->where('key', 'default_nameservers')->update(['value' => 'ns1.example.com ns2.example.com']);

// Hosting with the same domain has its own service and is unaffected by contact recovery.
$hostingProduct = App\Models\Product::create(['name' => 'Test hosting', 'category_id' => $category->id]);
$hostingPlan = $hostingProduct->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
$hosting = Service::create(['user_id' => $owner->id, 'product_id' => $hostingProduct->id, 'plan_id' => $hostingPlan->id,
    'price' => '5.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
$hosting->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'contact-hold.com']);
$hostingBefore = $hosting->fresh()->getAttributes();

// Invalid paid contacts are held with their frozen payload and one safe staff notice.
$invalid = $payload; $invalid['contacts']['tech']['phone'] = 'private-value';
$held = setupOrder($owner, 'contact-hold.com', $invalid + ['registry' => ['us_nexus' => 'C31'], 'epp' => null], paid: false);
$beforePayload = $held->payload;
$heldItem = InvoiceItem::findOrFail($held->invoice_item_id);
$staffQueries = [];
$captureStaffQueries = true;
DB::listen(function ($query) use (&$staffQueries, &$captureStaffQueries) {
    if ($captureStaffQueries) { $staffQueries[] = $query->sql; }
});
// Payment invokes the first, notifying hold; exclude checkout and subsequent idempotent holds.
ExtensionHelper::addPayment($held->invoice_id, null, amount: (float) $heldItem->price, isCreditTransaction: true);
$captureStaffQueries = false;
verify(collect($staffQueries)->contains(fn ($sql) => str_contains($sql, 'from "users"') && str_contains($sql, 'exists') && str_contains($sql, '"roles"')), 'Contact hold loaded users without a staff role.');
verify($operations->funded($held), 'Native payment did not fund the setup fixture.');
$beforeMoney = tableRows(['invoice_items', 'invoice_transactions']);
RegistrationSetup::hold($held);
$held = $held->fresh();
verify($held->state === 'held' && $held->safe_code === 'contact_details_required' && $held->payload === $beforePayload, 'Contact hold lost payload or state.');
verify($beforeMoney === tableRows(['invoice_items', 'invoice_transactions']), 'Contact hold changed paid money.');
verify($commands === [], 'Contact hold made a registrar request.');
$notices = App\Models\Notification::where('user_id', $staff->id)->get();
verify($notices->count() === 1 && str_contains($notices->sole()->body, 'phone number') && !str_contains($notices->sole()->body, 'private-value'), 'Staff notice duplicated or leaked contact data.');
verify(App\Models\Notification::where('user_id', $other->id)->count() === 0, 'Customer received staff notice.');
verify(App\Models\Notification::where('user_id', $owner->id)->count() === 0, 'Order owner received staff notice.');
verify($hosting->fresh()->getAttributes() === $hostingBefore && $hosting->properties()->where('key', 'domain')->value('value') === 'contact-hold.com', 'Contact hold changed same-domain hosting.');
$raw = DB::table('enom_operations')->where('id', $held->id)->first();
verify(!str_contains($raw->payload, 'private-value') && !str_contains($raw->payload, 'contact@example.com')
    && !str_contains($raw->policy, 'contact_hold_notified_at') && !array_key_exists('payload', $held->toArray()), 'Snapshot is not encrypted and hidden.');
verify($held->domain->service->properties()->pluck('key')->all() === ['domain'], 'Contacts leaked into service properties.');
setupProperty($owner, 'address', '2 Updated Street');
RegistrationSetup::hold($held->fresh());
$held->refresh();
verify($held->payload === $beforePayload && $held->state === 'held'
    && $held->payload['contacts']['registrant']['address1'] === '1 Main Street', 'Account edit replaced the bound snapshot.');

$unpaid = setupOrder($owner, 'unpaid-contacts.com', $invalid, paid: false);
RegistrationSetup::hold($unpaid);
verify($unpaid->fresh()->state === 'awaiting_payment', 'Unpaid request became a paid contact hold.');

$sent = setupOrder($owner, 'sent-contacts.com', $invalid);
$sent->update(['policy' => $sent->policy + ['dispatch_attempted_at' => now()->toIso8601String()], 'state' => 'uncertain']);
$sentBefore = $sent->fresh()->getAttributes();
RegistrationSetup::hold($sent);
verify($sent->fresh()->getAttributes() === $sentBefore, 'Contact validation converted a sent request to an unsent hold.');

// Recovery uses real owner, lifecycle and unsent checks before replacing only contacts.
refuses(fn () => RegistrationSetup::replaceContacts($other, $held->domain_id), '', 'Another owner replaced contacts.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
refuses(fn () => RegistrationSetup::replaceContacts($staff, $held->domain_id), '', 'Staff bypassed owner recovery.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
$reject = function (array $changes) use ($held, $owner) {
    DB::beginTransaction();
    try {
        $held->update($changes);
        refuses(fn () => RegistrationSetup::replaceContacts($owner, $held->domain_id), 'cannot be replaced', 'Unsafe contact replacement passed.');
    } finally { DB::rollBack(); $held->refresh(); }
};
$reject(['policy' => $held->policy + ['dispatch_attempted_at' => null]]);
$reject(['cost' => ['execution' => null]]);
$reject(['provider_order_id' => '1234']);
$reject(['state' => 'running']);
$reject(['safe_code' => 'different_hold']);
$reject(['completed_at' => now()]);
DB::beginTransaction();
try {
    $held->domain->service->update(['status' => 'cancelled']);
    refuses(fn () => RegistrationSetup::replaceContacts($owner, $held->domain_id), 'cannot be replaced', 'Cancelled service recovered.');
} finally { DB::rollBack(); }
setupProperty($owner, 'phone', 'private-value');
refuses(fn () => RegistrationSetup::replaceContacts($owner, $held->domain_id), 'phone number', 'Invalid corrected details replaced the snapshot.');
verify($held->fresh()->payload === $beforePayload, 'Failed recovery modified the encrypted snapshot.');
setupProperty($owner, 'phone', '(206) 555-0100');
$lockQueries = [];
DB::listen(function ($query) use (&$lockQueries) {
    if (preg_match('/^select .* from "(users|enom_domains|enom_operations)"/', $query->sql, $match)) { $lockQueries[] = $match[1]; }
});
DB::beginTransaction();
try {
    $updated = RegistrationSetup::replaceContacts($owner, $held->domain_id);
    verify(array_slice(array_values(array_unique($lockQueries)), 0, 3) === ['users', 'enom_domains', 'enom_operations'], 'Recovery did not read user, domain and request in lock order.');
    verify($updated->payload['contacts']['registrant']['address1'] === '2 Updated Street'
        && $updated->payload['contacts']['auxbilling']['phone'] === '+1.2065550100', 'Recovery did not re-read native account details.');
    verify($updated->payload['registry'] === $beforePayload['registry'] && $updated->payload['nameservers'] === $beforePayload['nameservers']
        && $updated->payload['epp'] === $beforePayload['epp'], 'Recovery overwrote another frozen field.');
    verify($commands === [], 'Recovery sent a registrar request before commit.');
} finally { DB::rollBack(); }

verify($hosting->fresh()->getAttributes() === $hostingBefore, 'Contact recovery changed same-domain hosting.');
// A saved code passes the deadline gate; recovery still waits for commit before enqueueing.
$waitingTransfer = setupOrder($owner, 'waiting-transfer.com', $invalid + ['epp' => 'SYNTHETIC-CODE', 'registry' => []], 'transfer');
RegistrationSetup::hold($waitingTransfer);
$jobsBeforeRecovery = tableRows(['jobs']);
DB::beginTransaction();
try {
    $waitingTransfer->refresh();
    $waitingTransfer->update(['policy' => array_replace($waitingTransfer->policy, ['paid_at' => now()->subDays(31)->toIso8601String()])]);
    $updated = RegistrationSetup::replaceContacts($owner, $waitingTransfer->domain_id);
    verify($updated->payload['epp'] === 'SYNTHETIC-CODE' && $updated->policy['paid_at'] === $waitingTransfer->policy['paid_at'], 'Contact recovery reset the transfer code or paid deadline.');
    app(Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class)->assertReady($updated);
    $updated->refresh();
    verify($updated->state === 'held' && $updated->safe_code === 'contact_details_required', 'Uncommitted contact recovery changed the transfer hold.');
    verify(tableRows(['jobs']) === $jobsBeforeRecovery, 'Contact recovery queued a transfer before commit.');
} finally { DB::rollBack(); }

// Render the owned partial through web middleware so the form key is session-bound.
$app['router']->get('/__enom-test-setup/{domain}', function (Illuminate\Http\Request $request, int $domain) {
    $owned = app(Paymenter\Extensions\Servers\Enom\Services\DomainManager::class)->owned($request->user(), $domain);
    return view('enom::partials.registration-setup', ['domain' => ['id' => $owned->id, 'domain' => $owned->domain]]);
})->middleware(['web', 'auth']);
$app['router']->getRoutes()->refreshNameLookups();
$url = '/domains/'.$held->domain_id.'/registration-contacts';
$page = enomHttp($owner, 'GET', '/__enom-test-setup/'.$held->domain_id);
verify($page->getStatusCode() === 200 && str_contains($page->getContent(), 'Use updated account details')
    && str_contains($page->getContent(), 'phone number') && !str_contains($page->getContent(), 'private-value'), 'Recovery partial failed or leaked contacts.');
$form = enomFormFields($page->getContent(), $url);
verify(enomHttp(null, 'POST', $url, $form)->getStatusCode() === 302, 'Guest recovery was admitted.');
$missingCsrf = enomHttp($owner, 'POST', $url, array_diff_key($form, ['_token' => true]), ['csrf' => true, 'token' => false]);
verify($missingCsrf->getStatusCode() === 419, 'Recovery accepted missing CSRF: '.$missingCsrf->getStatusCode());
verify(enomHttp($owner, 'POST', $url, ['_request_key' => str_repeat('0', 64)], ['csrf' => true])->getStatusCode() === 419, 'Recovery accepted a forged form key.');
verify(enomHttp($other, 'POST', $url, array_diff_key($form, ['_token' => true]), ['csrf' => true])->getStatusCode() === 404, 'Recovery exposed another owner domain.');
setupProperty($owner, 'phone', 'private-value');
$response = enomHttp($owner, 'POST', $url, $form, ['csrf' => true]);
verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/domains/'.$held->domain_id, 'Invalid corrected account did not return to the domain page.');
verify(!str_contains($response->getContent(), 'private-value'), 'Recovery response exposed account values.');
for ($i = 0; $i < 12; $i++) { $response = enomHttp($owner, 'POST', $url, $form, ['csrf' => true]); }
verify($response->getStatusCode() === 429, 'Contact recovery has no rate limit.');
setupProperty($owner, 'phone', '(206) 555-0100');
enomResetThrottle($owner);

// Nameserver changes use the frozen registration defaults and a unique request key.
$registered = setupOrder($owner, 'registered-setup.com', $payload);
$registered->domain->service->update(['status' => Service::STATUS_ACTIVE]);
$registered->domain->update(['status' => 'active', 'capabilities' => ['nameservers' => true]]);
$registered->update(['state' => 'succeeded', 'completed_at' => now()]);
$server->settings()->where('key', 'default_nameservers')->update(['value' => 'ns3.example.com ns4.example.com']);
foreach (['domain' => 'different.com', 'user_id' => (int) $other->id, 'service_id' => 999999,
    'server_id' => 999999, 'authority_generation' => 99, 'account_fingerprint' => 'changed-account'] as $key => $value) {
    DB::beginTransaction();
    try {
        $registered->update(['policy' => array_replace($registered->policy, [$key => $value])]);
        verify(RegistrationSetup::queueNameservers($registered) === null, 'Nameserver follow-up accepted a changed '.$key.'.');
    } finally { DB::rollBack(); $registered->refresh(); }
}
$jobsBefore = count(queuedJobs(ExecuteOperation::class));
DB::beginTransaction();
$follow = RegistrationSetup::queueNameservers($registered);
verify($follow && count(queuedJobs(ExecuteOperation::class)) === $jobsBefore, 'Nameserver job escaped its transaction.');
DB::rollBack();
verify(!Operation::where('operation_key', 'initial-nameservers:'.$registered->id)->exists(), 'Nameserver change survived a rollback.');
$follow = RegistrationSetup::queueNameservers($registered);
verify($follow && $follow->payload['nameservers'] === $payload['nameservers'] && $follow->operation_key === 'initial-nameservers:'.$registered->id,
    'Nameservers were re-read instead of using the bound snapshot.');
verify($follow->funding_basis === 'non_billable_management' && (int) $follow->authorized_by === (int) $owner->id
    && $follow->amount === '0.00' && (int) $follow->term === 1 && $follow->policy === [],
    'Nameserver authority or funding differs.');
verify(RegistrationSetup::queueNameservers($registered)->id === $follow->id
    && count(queuedJobs(ExecuteOperation::class)) === $jobsBefore + 1, 'Repeated confirmation queued a second nameserver change.');
$registeredSnapshot = $registered->fresh();
$registered->update(['payload' => null]);
verify(RegistrationSetup::queueNameservers($registeredSnapshot)->id === $follow->id, 'Payload purge broke follow-up idempotency.');
$commands = [];
$result = $operations->execute($follow->id);
verify($result->state === 'rejected' && array_column($commands, 0) === ['ModifyNS'], 'Rejected nameserver change did not execute independently: '.$result->state.' '.$result->safe_code);
verify($registered->fresh()->state === 'succeeded' && $registered->domain->fresh()->status === 'active', 'Nameserver refusal undid registration.');

$page = enomHttp($owner, 'GET', '/__enom-test-setup/'.$registered->domain_id);
verify($page->getStatusCode() === 200 && str_contains($page->getContent(), 'Registered; nameservers need attention'), 'Nameserver-only failure has no customer notice.');
$transfer = setupOrder($owner, 'transfer-setup.com', $payload, 'transfer');
$transfer->domain->service->update(['status' => Service::STATUS_ACTIVE]);
$transfer->domain->update(['status' => 'active']); $transfer->update(['state' => 'succeeded']);
verify(RegistrationSetup::queueNameservers($transfer) === null, 'Transfer queued registration nameservers.');
$blank = setupOrder($owner, 'blank-setup.com', array_replace($payload, ['nameservers' => []]));
$blank->domain->service->update(['status' => Service::STATUS_ACTIVE]);
$blank->domain->update(['status' => 'active']); $blank->update(['state' => 'succeeded']);
verify(RegistrationSetup::queueNameservers($blank) === null, 'Blank defaults queued a change.');
verify(RegistrationSetup::queueNameservers($unpaid) === null, 'Unconfirmed registration queued nameservers.');

// Confirmation can pass its original snapshot after terminal private data has been purged.
$purged = setupOrder($owner, 'purged-setup.com', $payload);
$purged->domain->service->update(['status' => Service::STATUS_ACTIVE]);
$purged->domain->update(['status' => 'active']); $purged->update(['state' => 'succeeded']);
$confirmation = $purged->fresh();
$purged->update(['payload' => null]);
$purgedFollow = RegistrationSetup::queueNameservers($confirmation);
verify($purgedFollow && $purgedFollow->payload['nameservers'] === $payload['nameservers'], 'Confirmation lost the original nameserver snapshot after payload purge.');

// Confirmation and its follow-up are one durable transaction, even if inserting the follow-up fails.
$failFollowup = true;
Operation::creating(function (Operation $operation) use (&$failFollowup) {
    if ($failFollowup && str_starts_with($operation->operation_key, 'initial-nameservers:')) {
        throw new RuntimeException('Injected follow-up persistence failure');
    }
});
$crashed = setupOrder($owner, 'followup-crash.com', $payload);
$operations->execute($crashed->id);
$crashed = Operation::findOrFail($crashed->id);
verify($crashed->state === 'uncertain' && $crashed->payload === $payload
    && $crashed->domain->status === 'registration_pending', 'Follow-up insert failure committed registration and lost its snapshot.');
verify(!Operation::where('operation_key', 'initial-nameservers:'.$crashed->id)->exists(), 'Failed confirmation retained a partial follow-up.');
$failFollowup = false;
// Retry confirmation from fresh rows after provider reconciliation, without repeating Purchase.
$commands = [];
$recovered = $operations->reconcile(Operation::findOrFail($crashed->id)->id);
verify(!in_array('Purchase', array_column($commands, 0), true), 'Confirmation recovery repeated Purchase.');
$recoveredFollow = Operation::where('operation_key', 'initial-nameservers:'.$crashed->id)->sole();
verify($recovered->state === 'succeeded' && $recovered->payload === null
    && $recoveredFollow->payload === ['nameservers' => $payload['nameservers']], 'Fresh-row confirmation retry lost the follow-up.');
$rawFollow = DB::table('enom_operations')->where('id', $recoveredFollow->id)->value('payload');
verify(!str_contains($rawFollow, 'ns1.example.com'), 'Durable nameserver payload is not encrypted.');
verify(RegistrationSetup::queueNameservers($recovered->fresh())->id === $recoveredFollow->id, 'Fresh-row retry duplicated the follow-up.');

// Simulate process death after commit but before the queue dispatch reaches the jobs table.
$loseDispatch = false;
DB::listen(function ($query) use (&$loseDispatch) {
    if ($loseDispatch && str_starts_with($query->sql, 'insert into "jobs"')) {
        $loseDispatch = false;
        DB::table('jobs')->delete();
        throw new RuntimeException('Injected death before job publication');
    }
});
$unstarted = setupOrder($owner, 'unstarted-followup.com', $payload);
$loseDispatch = true;
$operations->execute($unstarted->id);
$unstarted = Operation::findOrFail($unstarted->id);
$unstartedFollow = Operation::where('operation_key', 'initial-nameservers:'.$unstarted->id)->sole();
verify($unstarted->state === 'succeeded' && $unstarted->payload === null && $unstartedFollow->state === 'queued'
    && $unstartedFollow->payload['nameservers'] === $payload['nameservers'], 'Post-commit crash lost the durable follow-up.');
verify(queuedJobs(ExecuteOperation::class) === [], 'Dispatch-crash fixture retained execution jobs.');
Illuminate\Support\Facades\Cache::forget('enom:sweep:operations');
$operations->sweep();
$recoveredJobIds = array_map(fn ($job) => unserialize(json_decode($job->payload, true)['data']['command'])->operationId, queuedJobs(ExecuteOperation::class));
verify(in_array($unstartedFollow->id, $recoveredJobIds, true), 'Sweep did not dispatch the persisted, unstarted nameserver request.');

// A successful Purchase and a refused ModifyNS have separate results and no refund.
$purchase = setupOrder($owner, 'purchase-setup.com', $payload);
$commands = [];
$purchaseResult = $operations->execute($purchase->id);
verify($purchaseResult->state === 'succeeded', 'Paid registration did not succeed: '.$purchaseResult->state.' '.$purchaseResult->safe_code);
$purchases = array_values(array_filter($commands, fn ($request) => $request[0] === 'Purchase'));
verify(count($purchases) === 1 && !array_key_exists('NS1', $purchases[0][1]) && !array_key_exists('NS2', $purchases[0][1]), 'Purchase included the follow-up nameservers.');
$purchaseFollow = Operation::where('operation_key', 'initial-nameservers:'.$purchase->id)->sole();
$moneyBeforeNs = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
$commands = [];
verify($operations->execute($purchaseFollow->id)->state === 'rejected', 'Nameserver refusal was not independent of Purchase.');
verify($purchaseResult->fresh()->state === 'succeeded' && $purchaseResult->domain->fresh()->status === 'active'
    && $moneyBeforeNs === tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']), 'Nameserver refusal changed registration or refunded it.');
verify(array_column($commands, 0) === ['ModifyNS'], 'Nameserver failure repeated Purchase.');

// Staff recovery is a separate audited action; customer recovery remains strictly owner-only.
$staffHeld = setupOrder($owner, 'staff-contact-hold.com', $invalid + ['registry' => [], 'epp' => null]);
RegistrationSetup::hold($staffHeld);
$staffHeld->refresh();
verify(RegistrationSetup::canResumeContacts($staffHeld, $staff), 'Managing staff cannot resume a proven-unsent initial contact hold.');
verify(!RegistrationSetup::canResumeContacts($staffHeld, $other), 'Non-staff can see staff contact recovery.');
$staffBefore = $staffHeld->getAttributes();
refuses(fn () => RegistrationSetup::resumeContacts($other, $staffHeld->id, 'Account details corrected'), '', 'Missing manage permission admitted staff recovery.', [Symfony\Component\HttpKernel\Exception\HttpException::class]);
verify($staffHeld->fresh()->getAttributes() === $staffBefore, 'Permission refusal changed the held request.');
foreach (['', 'bad', "bad\nreason", str_repeat('x', 501)] as $reason) {
    refuses(fn () => RegistrationSetup::resumeContacts($staff, $staffHeld->id, $reason), 'review reason', 'Invalid staff audit reason passed.');
}
foreach ([['policy' => $staffHeld->policy + ['dispatch_attempted_at' => null]], ['cost' => ['execution' => null]],
    ['policy' => $staffHeld->policy + ['contact_readmissions' => array_fill(0, 10, [])]],
    ['provider_order_id' => '1234'], ['completed_at' => now()], ['state' => 'uncertain'], ['safe_code' => 'different_hold']] as $changes) {
    DB::beginTransaction();
    try {
        $staffHeld->update($changes);
        verify(!RegistrationSetup::canResumeContacts($staffHeld, $staff), 'Unsafe contact hold exposed staff recovery.');
        refuses(fn () => RegistrationSetup::resumeContacts($staff, $staffHeld->id, 'Account details corrected'), 'cannot be replaced', 'Staff replayed a sent or ineligible request.');
    } finally { DB::rollBack(); $staffHeld->refresh(); }
}
// Frozen commercial and ownership authority still gates the staff action.
foreach (['owner', 'funding', 'account', 'cancelled'] as $change) {
    DB::beginTransaction();
    try {
        if ($change === 'owner') { $staffHeld->domain->service->update(['user_id' => $other->id]); }
        elseif ($change === 'funding') { DB::table('invoice_transactions')->where('invoice_id', $staffHeld->invoice_id)->delete(); }
        elseif ($change === 'account') { $staffHeld->update(['policy' => array_replace($staffHeld->policy, ['account_fingerprint' => 'changed-account'])]); }
        else { $staffHeld->domain->service->update(['status' => Service::STATUS_CANCELLED]); }
        verify(!RegistrationSetup::canResumeContacts($staffHeld->fresh(), $staff), 'Changed '.$change.' authority exposed staff recovery.');
        refuses(fn () => RegistrationSetup::resumeContacts($staff, $staffHeld->id, 'Account details corrected'), '', 'Staff bypassed '.$change.' authority.', [InvalidArgumentException::class, Symfony\Component\HttpKernel\Exception\HttpException::class]);
    } finally { DB::rollBack(); $staffHeld->refresh(); }
}
setupProperty($owner, 'phone', 'private-value');
refuses(fn () => RegistrationSetup::resumeContacts($staff, $staffHeld->id, 'Account details corrected'), 'phone number', 'Staff copied invalid owner account contacts.');
verify($staffHeld->fresh()->getAttributes() === $staffBefore, 'Invalid account details changed payload or audit.');
setupProperty($owner, 'phone', '(206) 555-0100');
$staffPolicy = $staffHeld->policy;
$staffPayload = $staffHeld->payload;
$moneyBeforeResume = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
$commands = [];
require dirname(__DIR__).'/enom-admin-panel.php';
enomAdminPanel();
$staffPage = Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource\Pages\ListOperations::class)->set('activeTab', 'all');
$staffPage->assertTableActionVisible('resumeContacts', $staffHeld)
    ->assertTableActionHidden('resumeContacts', $sent)
    ->callTableAction('resumeContacts', $staffHeld, data: ['reason' => 'Account details corrected'])
    ->assertHasNoTableActionErrors();
$staffResumed = $staffHeld->fresh();
verify($staffResumed->state === 'queued' && $staffResumed->safe_code === null, 'Staff contact recovery did not use the ordinary initial gate.');
verify($staffResumed->payload['contacts'] === RegistrationSetup::payload($owner->fresh(), $server->id, 'register')['contacts']
    && array_diff_key($staffResumed->payload, ['contacts' => true]) === array_diff_key($staffPayload, ['contacts' => true]), 'Staff recovery replaced fields other than contacts or used staff account details.');
$review = $staffResumed->policy['contact_readmissions'][0] ?? [];
verify($review === ['staff_id' => (int) $staff->id, 'reason' => 'Account details corrected', 'at' => now()->toIso8601String(), 'previous_code' => 'contact_details_required']
    && array_diff_key($staffResumed->policy, ['contact_readmissions' => true]) === $staffPolicy, 'Staff recovery lost frozen policy or its audit.');
verify($moneyBeforeResume === tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']) && $commands === [], 'Staff recovery changed payment evidence or contacted the registrar.');
refuses(fn () => RegistrationSetup::resumeContacts($staff, $staffHeld->id, 'Account details corrected'), 'cannot be replaced', 'Repeated staff action recovered a queued request again.');
foreach (['missing' => null, 'ready' => 'SYNTHETIC-STAFF-CODE', 'expired' => null] as $case => $code) {
    $staffTransfer = setupOrder($owner, 'staff-'.$case.'-transfer.com', $invalid + ['epp' => $code, 'registry' => []], 'transfer');
    RegistrationSetup::hold($staffTransfer);
    $staffTransfer->refresh();
    if ($case === 'expired') { $staffTransfer->update(['policy' => array_replace($staffTransfer->policy, ['paid_at' => now()->subDays(31)->toIso8601String()])]); }
    $paidAt = $staffTransfer->policy['paid_at'];
    $result = RegistrationSetup::resumeContacts($staff, $staffTransfer->id, 'Owner corrected account details');
    verify($result->state === ($case === 'ready' ? 'queued' : 'awaiting_code') && $result->payload['epp'] === $code
        && $result->policy['paid_at'] === $paidAt
        && ($case !== 'expired' || $result->safe_code === 'transfer_code_timeout'), 'Staff transfer recovery bypassed the code/deadline gate or reset its snapshot: '.$case);
}

$resumePage = enomHttp($owner, 'GET', '/__enom-test-setup/'.$held->domain_id);
$response = enomHttp($owner, 'POST', $url, enomFormFields($resumePage->getContent(), $url), ['csrf' => true]);
verify($response->getStatusCode() === 302 && $held->fresh()->state === 'queued', 'Corrected contact recovery did not resume through the engine.');
verify(enomHttp($owner, 'POST', $url, enomFormFields($resumePage->getContent(), $url), ['csrf' => true])->getStatusCode() === 419, 'Successful recovery did not consume its form key.');
// A separate live seller can take over sales while old test services retain their identities.
$catalogue = app(Paymenter\Extensions\Servers\Enom\Services\Catalogue::class);
$oldService = $purchaseResult->domain->service;
$oldServiceBefore = $oldService->getAttributes();
$oldRegister = $catalogue->productFor($tld, 'register');
$oldTransfer = $catalogue->productFor($tld, 'transfer');
$oldIdentities = [$oldRegister->id, $oldTransfer->id, $oldRegister->plans()->pluck('id')->all(), $oldTransfer->plans()->pluck('id')->all()];
$tld->enabled = false;
$catalogue->publish($tld);
$liveServer = App\Models\Server::create(['name' => 'Live registrar', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'live-account', 'token' => 'live-token', 'environment' => 'live'] as $key => $value) {
    $liveServer->settings()->create(['key' => $key, 'value' => $value]);
}
$liveTld = enomTestTld(['server_id' => $liveServer->id, 'tld' => 'com']);
$liveIdentities = [];
foreach (['register' => 'registration', 'transfer' => 'transfer'] as $action => $suffix) {
    $oldProduct = $catalogue->productFor($tld, $action);
    $liveProduct = $catalogue->productFor($liveTld, $action);
    verify($oldProduct->slug === 'com-'.$suffix && $liveProduct->slug === 'com-'.$suffix.'-s'.$liveServer->id, 'Seller slugs are not distinct and deterministic.');
    verify($oldProduct->hidden && $liveProduct->hidden && $oldProduct->plans()->get()->sum(fn ($p) => $p->prices()->count()) === 0,
        'Disabled seller retained orderable inventory.');
    $liveIdentities[$action] = [$liveProduct->id, $liveProduct->slug, $liveProduct->plans()->pluck('id')->all()];
}
verify(!$tld->orderable() && $liveTld->orderable() && $catalogue->resolve('new-live.com')['tld']->id === $liveTld->id, 'Sales did not move to the live seller.');
verify($oldService->fresh()->getAttributes() === $oldServiceBefore
    && $operations->validService($oldService->fresh(), $purchaseResult->domain->fresh()), 'Seller switch broke the retained service.');
$purchaseResult->domain->update(['capabilities' => ['nameservers' => true, 'renew' => true]]);
$retainedChange = $operations->enqueueManagement($owner, $purchaseResult->domain_id, 'nameservers', ['nameservers' => $payload['nameservers']], str_repeat('a', 32));
$commands = [];
verify($operations->execute($retainedChange->id)->state === 'rejected' && array_column($commands, 0) === ['ModifyNS'], 'Retained test service could not dispatch a management request through its original seller.');
$tld->enabled = true;
refuses(fn () => $catalogue->publish($tld), 'already sold', 'First seller was re-enabled alongside the live seller.');
$tld->refresh();
verify(!$tld->enabled && $liveTld->fresh()->enabled, 'Seller exclusion failure changed enabled flags.');
$catalogue->publish($tld);
$catalogue->publish($liveTld);
verify([$catalogue->productFor($tld, 'register')->id, $catalogue->productFor($tld, 'transfer')->id,
    $oldRegister->plans()->pluck('id')->all(), $oldTransfer->plans()->pluck('id')->all()] === $oldIdentities, 'Seller switch replaced historical products or plans.');
foreach ($liveIdentities as $action => $identity) {
    $product = $catalogue->productFor($liveTld, $action);
    verify([$product->id, $product->slug, $product->plans()->pluck('id')->all()] === $identity, 'Republication changed live seller identities or slugs.');
}
// Switching back keeps both URL namespaces and excludes simultaneous sellers.
$liveTld->enabled = false;
$catalogue->publish($liveTld);
$tld->enabled = true;
$catalogue->publish($tld);
verify($tld->orderable() && !$liveTld->orderable() && $catalogue->productFor($tld, 'register')->slug === 'com-registration', 'Returning to the first seller changed its URL or failed admission.');
$liveTld->enabled = true;
refuses(fn () => $catalogue->publish($liveTld), 'already sold', 'Replacement seller bypassed exclusion after switching back.');

echo json_encode(['checks' => $checks, 'registration_setup' => 'passed', 'resume_integration' => 'available'], JSON_PRETTY_PRINT).PHP_EOL;
$successful = true;

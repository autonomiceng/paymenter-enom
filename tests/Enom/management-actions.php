<?php
// 0.3 management: transfer-code email with its 10-minute cooldown, one-request all-roles contacts with
// four-role readback, the DNS tools switch, and staff-initiated changes. Disposable native app; injected provider.
$enomTestLabel = 'management';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Service;
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$roles = ['Registrant', 'Admin', 'Tech', 'AuxBilling'];
$fields = ['first_name' => 'FirstName', 'last_name' => 'LastName', 'organization_name' => 'OrganizationName', 'address1' => 'Address1', 'address2' => 'Address2',
    'city' => 'City', 'state_province' => 'StateProvince', 'postal_code' => 'PostalCode', 'country' => 'Country', 'email_address' => 'EmailAddress',
    'phone' => 'Phone', 'phone_ext' => 'PhoneExt', 'job_title' => 'JobTitle', 'fax' => 'Fax'];
$original = ['first_name' => 'Old', 'last_name' => 'Owner', 'address1' => '1 Old Road', 'city' => 'Oldtown', 'postal_code' => '11111', 'country' => 'US',
    'email_address' => 'client.owner@example.com', 'phone' => '+1.5550000000'];
$provider = array_fill_keys($roles, $original);
$ignoreRole = null;
$notCollected = [];
$requests = [];
$transport = function (string $command, array $params) use (&$provider, &$ignoreRole, &$notCollected, &$requests, $roles, $fields): string {
    $requests[] = [$command, $params];
    if ($command === 'Contacts') {
        foreach ($roles as $role) {
            if ($role === $ignoreRole) { continue; }
            foreach ($fields as $key => $suffix) { if (array_key_exists($role.$suffix, $params)) { $provider[$role][$key] = (string) $params[$role.$suffix]; } }
        }
        return enomXml($command, '<RRPCode>200</RRPCode>');
    }
    if ($command === 'GetContacts') {
        $body = '<GetContacts>';
        foreach ($roles as $role) {
            $body .= '<'.$role.'>'.(in_array($role, $notCollected, true) ? '<'.$role.'ContactMatch>NotCollected</'.$role.'ContactMatch>' : '');
            foreach ($fields as $key => $suffix) { $body .= '<'.$role.$suffix.'>'.htmlspecialchars($provider[$role][$key] ?? '', ENT_XML1).'</'.$role.$suffix.'>'; }
            $body .= '</'.$role.'>';
        }
        return enomXml($command, $body.'</GetContacts>');
    }
    if ($command === 'SynchAuthInfo') { return enomXml($command, '<EPPEmailMessage>Sent to the registrant.</EPPEmailMessage><RRPCode>200</RRPCode>'); }
    if ($command === 'SetRegLock') { return enomXml($command, '<RRPCode>200</RRPCode>'); }
    if ($command === 'GetRegLock') { return enomXml($command, '<reg-lock>1</reg-lock>'); }
    throw new RuntimeException('Unexpected command '.$command);
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$manager = new DomainManager($operations);
$staffDomains = new StaffDomains($operations);

$owner = User::create(['email' => 'owner@example.invalid', 'password' => 'synthetic']);
$client = User::create(['email' => 'client@example.invalid', 'password' => 'synthetic']);
$managerRole = App\Models\Role::create(['name' => 'Domain staff', 'permissions' => ['admin.enom.manage']]);
$staff = User::create(['email' => 'staff@example.invalid', 'password' => 'synthetic', 'role_id' => $managerRole->id]);
$otherRole = App\Models\Role::create(['name' => 'Ticket staff', 'permissions' => ['admin.tickets.view']]);
$helpdesk = User::create(['email' => 'helpdesk@example.invalid', 'password' => 'synthetic', 'role_id' => $otherRole->id]);
$server = App\Models\Server::create(['name' => 'Managed Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'dns_tools' => '0'] as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
$category = App\Models\Category::create(['name' => 'Domains', 'slug' => 'domains']);
$product = App\Models\Product::create(['name' => 'Domain name', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Yearly', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$sequence = 0;
function managedDomain(User $user, array $capabilities = []): Domain {
    global $sequence, $server, $product, $plan, $original;
    $sequence++;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1,
        'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
    return Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'managed'.$sequence.'.com',
        'sld' => 'managed'.$sequence, 'tld' => 'com', 'status' => 'active', 'provider_order_id' => '7'.$sequence, 'provider_expires_at' => '2027-10-14',
        'snapshot' => ['contacts' => ['registrant' => $original, 'admin' => $original, 'tech' => $original, 'auxbilling' => $original]],
        'capabilities' => $capabilities + ['contacts' => true, 'lock' => true, 'epp_sync' => true, 'hosts' => true, 'srv' => true, 'dnssec_add' => true, 'dnssec_delete' => true]]);
}
function key64(string $seed): string { return hash('sha256', $seed); }
$domain = managedDomain($owner);

// Transfer code by email: owner request, one SynchAuthInfo with EmailEPP=True, success means "Enom accepted", never delivery.
$snapshot = $manager->snapshot($owner, $domain->id);
verify(($snapshot['capabilities']['epp_email'] ?? false) === true && $snapshot['epp_email_available_at'] === null, 'epp_email not offered with epp_sync.');
verify($snapshot['registrant_email_masked'] === 'c••••••@example.com' && DomainManager::maskEmail('masked@example.com') === 'm•••••@example.com' && DomainManager::maskEmail('x@example.com') === 'x••@example.com' && DomainManager::maskEmail('no-at') === null, 'Registrant email was not masked: '.$snapshot['registrant_email_masked']);
$first = $manager->request($owner, $domain->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('epp-1')]);
verify($first->state === 'queued' && $first->funding_basis === 'non_billable_management' && (int) $first->authorized_by === $owner->id && $first->payload === [], 'Transfer-code request was not queued for the owner.');
$requests = [];
$sent = $operations->execute($first->id);
verify($sent->state === 'succeeded' && $sent->safe_code === 'epp_email_accepted', 'Accepted transfer-code email did not succeed: '.$sent->safe_code);
verify(count($requests) === 1 && $requests[0][0] === 'SynchAuthInfo' && $requests[0][1]['EmailEPP'] === 'True', 'Transfer-code email sent other requests.');
// Cooldown: a second request within 10 minutes is refused without writing; the exact same request key returns the first.
verify($manager->request($owner, $domain->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('epp-1')])->id === $first->id, 'Same request key did not return the first request.');
$before = tableRows(['enom_operations', 'jobs']);
Carbon\Carbon::setTestNow(now()->addMinutes(9)); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
refuses(fn () => $manager->request($owner, $domain->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('epp-2')]), 'last 10 minutes', 'Second transfer-code email within 10 minutes was accepted.');
refuses(fn () => $staffDomains->requestChange($staff, $domain->id, 'epp_email', []), 'last 10 minutes', 'Staff bypassed the transfer-code cooldown.');
verify(tableRows(['enom_operations', 'jobs']) == $before, 'Cooldown refusal wrote history or jobs.');
$waiting = $manager->snapshot($owner, $domain->id)['epp_email_available_at'];
verify($waiting === Carbon\CarbonImmutable::parse($first->created_at)->addMinutes(10)->toIso8601String(), 'Snapshot does not report when the next email is allowed.');
Carbon\Carbon::setTestNow(now()->addMinutes(1)->addSecond()); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
verify($manager->snapshot($owner, $domain->id)['epp_email_available_at'] === null, 'Cooldown still shown after 10 minutes.');
$second = $manager->request($owner, $domain->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('epp-2')]);
verify($second->wasRecentlyCreated && $second->state === 'queued', 'Transfer-code email after 10 minutes was refused.');
// A refused request does not start a cooldown; a queued one does.
$second->update(['state' => 'rejected']);
verify($manager->request($owner, $domain->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('epp-3')])->wasRecentlyCreated, 'Rejected request blocked a new transfer-code email.');
Operation::where('domain_id', $domain->id)->where('state', 'queued')->update(['state' => 'rejected']);
// Unavailable without the EPP capability.
$noEpp = managedDomain($owner, ['epp_sync' => false]);
refuses(fn () => $manager->request($owner, $noEpp->id, 'epp_email', ['confirmed' => '1', '_request_key' => key64('no-epp')]), 'not available', 'epp_email accepted without capability.');
refuses(fn () => $operations->enqueueManagement($owner, $noEpp->id, 'epp_email', [], key64('no-epp-direct')), 'not available', 'Backend epp_email accepted without capability.');
verify(!isset($manager->snapshot($owner, $noEpp->id)['capabilities']['epp_email']), 'epp_email offered without capability.');

// Contacts for every role: one Contacts request with all four prefixed sets; readback must match all four roles.
$new = ['first_name' => 'New', 'last_name' => 'Holder', 'organization_name' => 'Holder Ltd', 'address1' => '2 New Street', 'city' => 'Newtown', 'state_province' => 'CA',
    'postal_code' => '22222', 'country' => 'US', 'email_address' => 'new.holder@example.com', 'phone' => '+1.5552223333'];
$allRoles = $manager->request($owner, $domain->id, 'contacts', ['confirmed' => '1', 'contact_type' => 'all', 'contact' => $new, '_request_key' => key64('contacts-all')]);
verify($allRoles->payload['contact_type'] === 'all', 'All-roles contact request lost its type.');
$requests = [];
verify($operations->execute($allRoles->id)->state === 'succeeded', 'All-roles contacts did not confirm.');
$writes = array_values(array_filter($requests, fn ($request) => $request[0] === 'Contacts'));
verify(count($writes) === 1 && array_column($requests, 0) === ['Contacts', 'GetContacts'], 'All-roles change was not one Contacts request and one readback: '.json_encode(array_column($requests, 0)));
$params = $writes[0][1];
verify(!array_key_exists('ContactType', $params) && $params['SLD'] === $domain->sld && $params['TLD'] === 'com', 'All-roles request carried a single ContactType.');
foreach ($roles as $role) {
    verify(($params[$role.'FirstName'] ?? null) === 'New' && ($params[$role.'EmailAddress'] ?? null) === 'new.holder@example.com'
        && ($params[$role.'Phone'] ?? null) === '+1.5552223333' && ($params[$role.'OrganizationName'] ?? null) === 'Holder Ltd', $role.' set missing or different in the all-roles request.');
}
$expectedKeys = ['SLD', 'TLD'];
foreach ($roles as $role) { foreach ($fields as $suffix) { $expectedKeys[] = $role.$suffix; } }
verify(array_keys($params) === $expectedKeys, 'All-roles request carried unexpected or missing parameters.');
$cached = $domain->fresh()->snapshot['contacts'];
verify(($cached['tech']['first_name'] ?? null) === 'New' && ($cached['auxbilling']['email_address'] ?? null) === 'new.holder@example.com', 'Readback did not refresh every role.');
verify(DomainManager::contactsMatch($cached), 'Matching roles not reported as one contact.');
// If any role does not read back as requested, the change stays unconfirmed.
$ignoreRole = 'Tech';
$partial = $manager->request($owner, $domain->id, 'contacts', ['confirmed' => '1', 'contact_type' => 'all', 'contact' => ['first_name' => 'Third'] + $new, '_request_key' => key64('contacts-partial')]);
$result = $operations->execute($partial->id);
verify($result->state === 'uncertain' && $result->safe_code === 'management_unconfirmed', 'Three of four roles confirmed an all-roles change.');
verify(($domain->fresh()->snapshot['contacts']['registrant']['first_name'] ?? null) === 'New', 'Unconfirmed change overwrote the cached contacts.');
$ignoreRole = null;
$result->update(['state' => 'rejected']); // Simulator knows the outcome; dispose before the next request.
// Registries that collect only the registrant report the other roles as NotCollected; readback checks the collected roles only.
$notCollected = ['Admin', 'Tech', 'AuxBilling'];
$ignoreRole = 'Tech';
$registrantOnly = $manager->request($owner, $domain->id, 'contacts', ['confirmed' => '1', 'contact_type' => 'all', 'contact' => ['first_name' => 'Gdpr'] + $new, '_request_key' => key64('contacts-registrant-only')]);
verify($operations->execute($registrantOnly->id)->state === 'succeeded', 'Uncollected role blocked an all-roles change.');
$cached = $domain->fresh()->snapshot;
knownBug(($cached['contact_roles'] ?? null) === ['registrant'], 'contact-roles-not-merged', 'DomainOperations::mergedSnapshot() keeps contact_status but drops contact_roles from a contacts readback, so the page keeps showing all four roles (and contacts_match over uncollected roles) until a full refresh.');
$domain->update(['snapshot' => ['contact_roles' => ['registrant']] + $cached]); // As a full refresh would record it.
verify($manager->snapshot($owner, $domain->id)['contact_roles'] === ['registrant'] && $manager->snapshot($owner, $domain->id)['contacts_match'] === true, 'Page model did not limit roles to those collected.');
$ignoreRole = 'Registrant';
$unconfirmed = $manager->request($owner, $domain->id, 'contacts', ['confirmed' => '1', 'contact_type' => 'all', 'contact' => ['first_name' => 'Ignored'] + $new, '_request_key' => key64('contacts-registrant-ignored')]);
verify($operations->execute($unconfirmed->id)->state === 'uncertain', 'All-roles change confirmed without the registrant reading back.');
$unconfirmed->update(['state' => 'rejected']);
$notCollected = []; $ignoreRole = null;
$snapshotAll = $domain->fresh()->snapshot; unset($snapshotAll['contact_roles']);
$domain->update(['snapshot' => $snapshotAll]);
// A single-role change still sends only that role's set.
$tech = $manager->request($owner, $domain->id, 'contacts', ['confirmed' => '1', 'contact_type' => 'tech', 'contact' => ['first_name' => 'Techie'] + $new, '_request_key' => key64('contacts-tech')]);
$requests = [];
verify($operations->execute($tech->id)->state === 'succeeded', 'Tech-only change did not confirm.');
verify($requests[0][1]['ContactType'] === 'TECH' && isset($requests[0][1]['TechFirstName']) && !isset($requests[0][1]['RegistrantFirstName']), 'Single-role request carried other roles.');
verify(!DomainManager::contactsMatch($domain->fresh()->snapshot['contacts']), 'Differing roles reported as one contact.');

// DNS tools off: DNS actions are hidden and refused in both paths; other actions are unaffected.
$dnsDomain = managedDomain($owner);
$dnsSnapshot = $manager->snapshot($owner, $dnsDomain->id);
verify($dnsSnapshot['dns_tools'] === false && !array_intersect(array_keys($dnsSnapshot['capabilities']), DomainManager::DNS_ACTIONS) && $dnsSnapshot['capabilities']['lock'] === true, 'DNS tools off still exposes DNS actions or hides others.');
$before = tableRows(['enom_operations']);
$ds = ['algorithm' => 13, 'digest_type' => 2, 'digest' => str_repeat('A', 64), 'key_tag' => 12345];
foreach (['hosts' => ['records' => []], 'srv' => ['records' => []], 'dnssec_add' => ['ds' => $ds], 'dnssec_delete' => ['ds' => $ds]] as $action => $payload) {
    refuses(fn () => $manager->request($owner, $dnsDomain->id, $action, $payload + ['confirmed' => '1', '_request_key' => key64('dns-'.$action)]), 'DNS tools are turned off', 'Client '.$action.' accepted with DNS tools off.');
    refuses(fn () => $staffDomains->requestChange($staff, $dnsDomain->id, $action, $payload + ['confirmed' => '1']), 'DNS tools are turned off', 'Staff '.$action.' accepted with DNS tools off.');
}
verify(tableRows(['enom_operations']) == $before, 'Refused DNS requests wrote history.');
$server->settings()->where('key', 'dns_tools')->update(['value' => '1']);
verify($manager->snapshot($owner, $dnsDomain->id)['dns_tools'] === true && ($manager->snapshot($owner, $dnsDomain->id)['capabilities']['dnssec_add'] ?? false) === true, 'DNS tools on did not restore DNS actions.');
verify($manager->request($owner, $dnsDomain->id, 'dnssec_add', ['confirmed' => '1', 'ds' => $ds, '_request_key' => key64('dns-on')])->wasRecentlyCreated, 'DNS action refused with DNS tools on.');
$server->settings()->where('key', 'dns_tools')->update(['value' => '0']);

// Staff with admin.enom.manage may request changes on any client's domain; the request records the staff member.
$clientDomain = managedDomain($client);
$staffLock = $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1']);
verify($staffLock->state === 'queued' && (int) $staffLock->authorized_by === $staff->id && $staffLock->funding_basis === 'non_billable_management' && $operations->funded($staffLock), 'Staff change was not recorded as staff or not funded.');
verify($operations->execute($staffLock->id)->state === 'succeeded', 'Staff lock change did not execute.');
verify($staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1', '_request_key' => key64('staff-lock')])->id
    === $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1', '_request_key' => key64('staff-lock')])->id, 'Staff request key was not idempotent.');
Operation::where('domain_id', $clientDomain->id)->where('state', 'queued')->update(['state' => 'rejected']);
// Owners only change their own domains; other clients and staff without the permission get not-found or refusal.
$before = tableRows(['enom_operations']);
refuses(fn () => $operations->enqueueManagement($owner, $clientDomain->id, 'lock', ['locked' => '1'], key64('foreign-owner')), '', 'Owner changed another client\'s domain.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
refuses(fn () => $manager->request($owner, $clientDomain->id, 'lock', ['confirmed' => '1', 'locked' => '1', '_request_key' => key64('foreign-manager')]), '', 'Client page changed another client\'s domain.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
refuses(fn () => $operations->enqueueManagement($helpdesk, $clientDomain->id, 'lock', ['locked' => '1'], key64('helpdesk')), '', 'Staff without admin.enom.manage changed a client\'s domain.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
refuses(fn () => $staffDomains->requestChange($helpdesk, $clientDomain->id, 'lock', ['locked' => '1']), 'permission', 'StaffDomains accepted a user without admin.enom.manage.', [Illuminate\Auth\Access\AuthorizationException::class]);
refuses(fn () => $staffDomains->requestChange($staff, $clientDomain->id, 'renew', []), 'supported change', 'Staff requested an unsupported change.', [InvalidArgumentException::class]);
verify(tableRows(['enom_operations']) == $before, 'Refused requests wrote history.');
// Staff are bound by the same gates: unresolved requests and staff holds.
$blocker = $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '0']);
$blocker->update(['state' => 'uncertain']);
refuses(fn () => $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1']), 'still being processed', 'Staff bypassed an unresolved request.');
$blocker->update(['state' => 'rejected']);
Illuminate\Database\Eloquent\Model::withoutEvents(fn () => $clientDomain->update(['billing_managed' => true, 'renewal_policy' => ['enabled' => true, 'staff_disposition_hold' => true]]));
refuses(fn () => $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1']), 'on hold for staff review', 'Staff bypassed a staff disposition hold.');
Illuminate\Database\Eloquent\Model::withoutEvents(fn () => $clientDomain->update(['billing_managed' => false, 'renewal_policy' => null]));
// A staff request queued before the staff member lost the permission is no longer funded.
$queued = $staffDomains->requestChange($staff, $clientDomain->id, 'lock', ['locked' => '1']);
$managerRole->update(['permissions' => ['admin.tickets.view']]);
$staff = $staff->fresh();
verify(!$operations->funded($queued->fresh()), 'Staff request stayed funded after the permission was removed.');
$requests = [];
verify($operations->execute($queued->id)->state === 'held' && $requests === [], 'Unauthorized staff request reached Enom.');

echo 'Management actions: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;

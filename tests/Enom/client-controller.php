<?php
// Client area through real routes and middleware: authentication and ownership on every route, whitelisted list
// parameters, the search page and its JSON availability check, quote and order, domain management, renewal and
// ID Protect invoices. Disposable native app, real sessions and form keys, injected Enom transport (Check only).
$enomTestLabel = 'client-controller';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Models\Invoice;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
require_once dirname(__DIR__).'/enom-cart-order.php';
use Paymenter\Extensions\Servers\Enom\Services\Pricing;

$owner = client('client.owner@example.com');
$other = client('other.client@example.com', ['first_name' => 'Other', 'last_name' => 'Other']);
/** Domain names linked from the list table, in order. */
function listed(string $html): array {
    return array_map(fn ($node) => trim($node->textContent), iterator_to_array(enomDocument($html)->query('//table//a[contains(concat(" ", @class, " "), " enom-domain ")]')));
}
/** No Enom cost in markup, attributes or text. Icon path data and the theme's styles and scripts are skipped. */
function noCosts(string $body, string $message): void {
    $body = preg_replace('#<(svg|style|script)\b.*?</\1>#si', '', $body);
    foreach (ENOM_COST_MARKERS as $marker) { verify(!str_contains($body, $marker), $message.' (contains '.$marker.')'); }
}
/** Flash errors of the next page view, as plain text. */
function pageText(string $html): string { return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)))); }

// ---------------------------------------------------------------- authentication and ownership
$mine = clientDomain($owner, ['domain' => 'owned-site.com']);
$theirs = clientDomain($other, ['domain' => 'other-secret.com']);
$before = clientRows();
foreach (['/domains', '/domains/transfer', '/domains/help', '/domains/'.$mine->id] as $uri) {
    $response = enomHttp(null, 'GET', $uri);
    verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/login', 'Guest reached GET '.$uri);
}
$posts = ['/domains/'.$mine->id.'/refresh', '/domains/'.$mine->id.'/renew', '/domains/'.$mine->id.'/privacy/purchase',
    '/domains/'.$mine->id.'/renewal-preference', '/domains/'.$mine->id.'/manage/lock'];
foreach ($posts as $uri) {
    $response = enomHttp(null, 'POST', $uri, ['domain' => 'x.com', 'locked' => '0', '_request_key' => str_repeat('a', 64)]);
    verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/login', 'Guest reached POST '.$uri);
}
// Search, the price list and the availability check are public; ordering is not.
verify(enomHttp(null, 'GET', '/domains/register')->getStatusCode() === 200 && enomHttp(null, 'GET', '/domains/pricing')->getStatusCode() === 200, 'Guest could not open the public search or price list.');
$alias = enomHttp(null, 'GET', '/domains/search?name=hello');
verify($alias->getStatusCode() === 301 && enomRedirectPath($alias) === '/domains/register', '/domains/search does not redirect to the canonical search page.');
verify(enomHttp(null, 'POST', '/domains/check', ['domain' => 'x'], ['json' => true])->getStatusCode() === 422, 'Guest JSON check did not validate the name.');
// Another client's domain does not exist for this user: every page and action is a 404 before any form key check.
verify(enomHttp($owner, 'GET', '/domains/'.$theirs->id)->getStatusCode() === 404, 'Owner opened another client\'s domain page.');
foreach (['refresh', 'renew', 'privacy/purchase', 'privacy/renew', 'renewal-preference', 'manage/lock', 'manage/nameservers', 'manage/contacts', 'manage/epp_email', 'manage/hosts'] as $action) {
    $response = enomHttp($owner, 'POST', '/domains/'.$theirs->id.'/'.$action, ['locked' => '0', 'enabled' => '0', 'nameservers' => "ns1.evil.com\nns2.evil.com", '_request_key' => str_repeat('b', 64)]);
    verify($response->getStatusCode() === 404, 'Owner POST '.$action.' on another client\'s domain was '.$response->getStatusCode());
}
verify(enomHttp($owner, 'GET', '/domains/999999')->getStatusCode() === 404 && enomHttp($owner, 'GET', '/domains/abc')->getStatusCode() === 404, 'Unknown domain ids are not 404.');
verify(enomHttp($owner, 'POST', '/domains/'.$mine->id.'/manage/renew', ['_request_key' => str_repeat('b', 64)])->getStatusCode() === 404, 'Unknown management action was not 404.');
verify(clientRows() == $before && $providerCommands === [], 'Refused guest or foreign requests changed data or reached Enom.');
// A domain row alone is not ownership: the native service, its product server and the extension must agree.
$mapped = clientDomain($owner, ['domain' => 'mapping-check.com']);
App\Models\Service::withoutEvents(fn () => $mapped->service->update(['user_id' => $other->id]));
verify(enomHttp($owner, 'GET', '/domains/'.$mapped->id)->getStatusCode() === 404, 'Domain with another client\'s service was shown.');
App\Models\Service::withoutEvents(fn () => $mapped->service->update(['user_id' => $owner->id]));
$plesk = App\Models\Server::create(['name' => 'Other provider', 'type' => 'server', 'extension' => 'Plesk', 'enabled' => true]);
$mapped->update(['server_id' => $plesk->id]);
verify(enomHttp($owner, 'GET', '/domains/'.$mapped->id)->getStatusCode() === 404, 'Domain mapped to a different server was shown.');
$mapped->update(['server_id' => $server->id]);
verify(enomHttp($owner, 'GET', '/domains/'.$mapped->id)->getStatusCode() === 200, 'Correctly mapped domain was refused.');
$mapped->service->delete(); $mapped->delete();
// Help is for signed-in clients.
verify(enomHttp($owner, 'GET', '/domains/help')->getStatusCode() === 200, 'Help pages ignore their audience.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- list: sort, filter, search and pagination are whitelisted
$mine->update(['domain' => 'bravo.net', 'reservation_name' => 'bravo.net', 'sld' => 'bravo', 'tld' => 'net', 'provider_expires_at' => '2027-06-01']);
clientDomain($owner, ['domain' => 'alpha.com', 'provider_expires_at' => '2026-10-20']);
clientDomain($owner, ['domain' => 'charlie.com', 'status' => 'registration_pending', 'provider_expires_at' => null]);
clientDomain($owner, ['domain' => 'delta.com', 'status' => 'expired', 'provider_expires_at' => '2026-09-01']);
clientDomain($owner, ['domain' => 'echo.com', 'status' => 'needs_review', 'provider_expires_at' => '2027-03-01']);
for ($i = 1; $i <= 22; $i++) { clientDomain($owner, ['domain' => sprintf('zz-bulk-%02d.com', $i), 'provider_expires_at' => sprintf('2027-%02d-15', ($i % 12) + 1)]); }
$page = enomHttp($owner, 'GET', '/domains');
$rows = listed($page->getContent());
verify($page->getStatusCode() === 200 && count($rows) === 25 && $rows[0] === 'delta.com' && $rows[1] === 'alpha.com' && !in_array('charlie.com', $rows, true), 'Default list is not 25 rows by expiry: '.json_encode(array_slice($rows, 0, 3)));
verify(str_contains(pageText($page->getContent()), '27 domains in your account') && !str_contains($page->getContent(), 'other-secret.com'), 'List counts or shows another client\'s domains.');
$second = listed(enomHttp($owner, 'GET', '/domains?page=2')->getContent());
verify(count($second) === 2 && end($second) === 'charlie.com', 'Second page does not end with the undated pending domain: '.json_encode($second));
$byName = listed(enomHttp($owner, 'GET', '/domains?sort=name')->getContent());
verify($byName[0] === 'alpha.com' && $byName[1] === 'bravo.net', 'Name sort is not alphabetical.');
verify(listed(enomHttp($owner, 'GET', '/domains?sort=-name')->getContent())[0] === 'zz-bulk-22.com', 'Reverse name sort is wrong.');
foreach (['provider_expires_at desc', 'id', 'expiry;drop', '-domain', 'user_id'] as $sort) {
    $response = enomHttp($owner, 'GET', '/domains?sort='.rawurlencode($sort));
    verify($response->getStatusCode() === 200 && listed($response->getContent())[0] === 'delta.com', 'Unlisted sort '.$sort.' was not ignored.');
}
$filters = ['pending' => ['charlie.com'], 'expired' => ['delta.com'], 'attention' => ['echo.com'], 'expiring' => ['alpha.com']];
foreach ($filters as $filter => $expected) {
    verify(listed(enomHttp($owner, 'GET', '/domains?status='.$filter)->getContent()) === $expected, 'Filter '.$filter.' returned the wrong domains.');
}
verify(count(listed(enomHttp($owner, 'GET', '/domains?status=deleted')->getContent())) === 25, 'Unknown status filter was not ignored.');
verify(listed(enomHttp($owner, 'GET', '/domains?q=BRA')->getContent()) === ['bravo.net'], 'Search does not match case-insensitively.');
foreach (['%', '_', 'other-secret', "' OR 1=1 --"] as $q) {
    verify(listed(enomHttp($owner, 'GET', '/domains?q='.rawurlencode($q))->getContent()) === [], 'Search '.$q.' matched rows (wildcards not escaped or foreign rows).');
}
$withParams = enomHttp($owner, 'GET', '/domains?sort=name&q=zz-bulk&status=active')->getContent();
$links = array_map(fn ($node) => $node->getAttribute('href'), iterator_to_array(enomDocument($withParams)->query('//a[contains(@href, "page=2")]')));
verify(count(listed($withParams)) === 22 && $links === [], 'Filtered list paginates or loses rows.');
$paged = enomHttp($owner, 'GET', '/domains?sort=-name')->getContent();
$pageLinks = array_map(fn ($node) => html_entity_decode($node->getAttribute('href')), iterator_to_array(enomDocument($paged)->query('//a[contains(@href, "page=2")]')));
verify($pageLinks && str_contains($pageLinks[0], 'sort=-name'), 'Pagination links drop the chosen sort.');
foreach (['/domains?sort[]=name', '/domains?q[]=x', '/domains?page=-3', '/domains?page=abc', '/domains?q='.str_repeat('a', 400)] as $uri) {
    verify(enomHttp($owner, 'GET', $uri)->getStatusCode() === 200, 'Malformed list parameter failed: '.$uri);
}
$arrayFilter = enomHttp($owner, 'GET', '/domains?status[]=active');
verify($arrayFilter->getStatusCode() === 200, 'index-array-status-500: DomainController::index() line 62 passes ?status[]=… to array_key_exists(), which throws a TypeError, so the list returns 500 instead of ignoring the filter.');

// ---------------------------------------------------------------- search page: only enabled endings, retail prices only
$search = enomHttp($owner, 'GET', '/domains/register');
$xpath = enomDocument($search->getContent());
$options = array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="tld"]/option')));
verify($options === ['com', 'net'], 'Search offers endings that are not enabled: '.json_encode($options));
$endingsText = preg_replace('/\s+/', ' ', trim($xpath->query('//p[contains(@class, "enom-endings")]')->item(0)?->textContent ?? ''));
verify($endingsText === '.com $12.00/yr .net $15.00/yr All prices', 'Search does not list exactly the enabled endings with retail prices: '.$endingsText);
verify($xpath->query('//p[contains(@class, "enom-endings")]/a[@href="'.route('enom.domains.pricing').'"]')->length === 1, 'Search does not link the public price list.');
noCosts($search->getContent(), 'Search page shows Enom costs');
$results = fn (string $html) => array_map(fn ($node) => $node->getAttribute('data-domain'), iterator_to_array(enomDocument($html)->query('//*[@data-enom-result]')));
$org = enomHttp($owner, 'GET', '/domains/register?name=hello.org')->getContent();
verify($results($org) === ['hello.com', 'hello.net'] && str_contains(pageText($org), '.org isn’t available here'), 'Disabled ending was searched or not explained.');
verify($results(enomHttp($owner, 'GET', '/domains/register?name=hello.net')->getContent()) === ['hello.net', 'hello.com'], 'Typed ending is not checked first.');
verify($results(enomHttp($owner, 'GET', '/domains/register?name='.rawurlencode('https://www.Hello.com/path?x=1'))->getContent()) === ['hello.com', 'hello.net'], 'Pasted URL was not reduced to its name.');
verify($results(enomHttp($owner, 'GET', '/domains/register?name=hello&tld=org')->getContent())[0] === 'hello.com', 'Disabled ending chosen in the URL was preferred.');
$sub = enomHttp($owner, 'GET', '/domains/register?name=shop.hello.com')->getContent();
verify($results($sub) === [] && str_contains(pageText($sub), 'without a subdomain'), 'Subdomain input was searched.');
$idn = enomHttp($owner, 'GET', '/domains/register?name='.rawurlencode('héllo'))->getContent();
verify($results($idn) === [] && str_contains(pageText($idn), 'International characters aren’t supported'), 'IDN input was searched.');
verify(enomDocument($org)->query('//ul[@data-enom-results="http://enom-tests.invalid/domains/check"]')->length === 1, 'Results do not use the check endpoint.');
verify($providerCommands === [], 'Rendering search results called Enom before the browser asked.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- JSON check: auth, CSRF, validation, public fields, throttle
$check = fn ($user, $domain, array $options = []) => enomHttp($user, 'POST', '/domains/check', ['domain' => $domain], ['json' => true] + $options);
$probe = client('probe@example.com'); // Spreads checks over two clients so the 60-a-minute limit is only reached on purpose.
verify($check($owner, 'hello.com', ['csrf' => true, 'token' => false])->getStatusCode() === 419, 'Check accepted a request without a CSRF token.');
$ok = $check($owner, 'hello.com', ['csrf' => true]);
verify($ok->getStatusCode() === 200, 'Check refused a request with the session CSRF token.');
$data = json_decode($ok->getContent(), true);
verify(array_keys($data) === ['domain', 'status', 'price', 'transfer_price', 'renew_price', 'register_url', 'transfer_url', 'warning', 'min_years', 'max_years', 'terms', 'alternative'] && $data['status'] === 'available'
    && $data['price'] === '$12.00' && $data['transfer_price'] === '$13.00' && $data['renew_price'] === '$14.00', 'Available answer has unexpected fields or prices: '.$ok->getContent());
// Each sale plan supplies its own total for 1-10 years.
verify($data['min_years'] === 1 && $data['max_years'] === 10 && array_keys($data['terms']) === range(1, 10) && $data['terms']['1'] === '$12.00'
    && $data['terms']['2'] === '$24.00' && $data['terms']['10'] === '$120.00', 'Check answer terms differ: '.json_encode($data['terms']));
foreach ([null, ['hello.com'], '', 'bad_name.com', 'hello', str_repeat('a', 64).'.com', str_repeat('a', 60).'.'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.com', 'hello.com/x', '<script>.com'] as $bad) {
    $response = $check($probe, $bad);
    verify($response->getStatusCode() === 422 && json_decode($response->getContent(), true) === ['status' => 'invalid', 'message' => 'Enter a valid domain name.'], 'Malformed check input was accepted: '.json_encode($bad));
}
$commandsBefore = count($providerCommands);
$unsupported = json_decode($check($probe, 'hello.org')->getContent(), true);
verify($unsupported['status'] === 'invalid' && count($providerCommands) === $commandsBefore, 'Unsupported ending was checked with Enom: '.json_encode($unsupported));
verify(json_decode($check($probe, '  HELLO.COM ')->getContent(), true)['domain'] === 'hello.com', 'Check does not normalize case and spaces.');
$availability += ['taken-name.com' => 'taken', 'moveme.com' => 'transferable', 'fancy.com' => 'premium', 'flaky.com' => 'error', 'longterm.com' => 'twoyear'];
// 0.4: a name with a two-year registry minimum is available from two years. Another client's portal name is just taken.
$expected = ['taken-name.com' => 'taken', 'moveme.com' => 'transferable', 'fancy.com' => 'premium', 'flaky.com' => 'error', 'longterm.com' => 'available', 'bravo.net' => 'yours', 'other-secret.com' => 'taken'];
foreach ($expected as $name => $status) {
    $response = $check($owner, $name);
    $body = $response->getContent();
    verify($response->getStatusCode() === 200 && json_decode($body, true)['status'] === $status, $name.' was not reported as '.$status.': '.$body);
    noCosts($body, 'Check answer for '.$name.' shows Enom costs');
    // Term keys ("2": "$26.00") are numbers too, so look for the id as a value rather than any digit-quote pair.
    $decoded = json_decode($body, true);
    verify(!str_contains($body, '7f3a') && !array_key_exists('id', $decoded) && !in_array($theirs->id, array_values(array_diff_key($decoded, ['terms' => 1, 'min_years' => 1, 'max_years' => 1])), true)
        && !str_contains($body, '/domains/'.$theirs->id), 'Check answer for '.$name.' leaks provider text or another client\'s domain id.');
}
verify(json_decode($check($owner, 'bravo.net')->getContent(), true)['url'] === 'http://enom-tests.invalid/domains/'.$mine->id, 'Own domain answer lacks its page link.');
$longTerm = json_decode($check($owner, 'longterm.com')->getContent(), true);
verify($longTerm['min_years'] === 2 && $longTerm['max_years'] === 10 && array_keys($longTerm['terms']) === range(2, 10) && $longTerm['terms']['2'] === '$24.00', 'Two-year-minimum name terms differ: '.json_encode($longTerm));
$flaky = $check($owner, 'flaky.com')->getContent(); verify((json_decode($flaky, true)['message'] ?? null) === 'Couldn’t check this name.', 'Provider failure message is not generic: '.$flaky);
$throttled = client('throttled@example.com');
$codes = [];
for ($i = 0; $i < 61; $i++) { $codes[] = $check($throttled, 'x')->getStatusCode(); }
verify(count(array_filter($codes, fn ($code) => $code === 422)) === 60 && end($codes) === 429, 'Check is not limited to 60 requests a minute: '.json_encode(array_count_values($codes)));
verify($check($owner, 'x')->getStatusCode() === 422, 'One client\'s throttle blocked another client.');
// The removed order endpoints never create obligations.
$before = clientRows();
foreach ([['POST','/domains/quote'], ['POST','/domains/order'], ['GET','/domains/order/'.str_repeat('a',48)]] as [$method,$uri]) {
    verify(enomHttp($owner,$method,$uri)->getStatusCode() === 404, 'Removed order endpoint remains available.');
}
verify(clientRows()==$before,'Removed endpoints wrote business rows.');
enomResetThrottle($owner);

// ---------------------------------------------------------------- domain management through the page's own forms
$managed = clientDomain($owner, ['domain' => 'managed-site.com'], ['hosts' => [['name' => 'www', 'type' => 'A', 'address' => '192.0.2.10', 'mx_pref' => 10]]]);
$show = fn () => enomHttp($owner, 'GET', '/domains/'.$managed->id)->getContent();
$base = '/domains/'.$managed->id.'/manage/';
$latest = fn (string $action) => Operation::where('domain_id', $managed->id)->where('action', $action)->latest('id')->first();
$settle = fn () => Operation::where('domain_id', $managed->id)->whereIn('state', ['queued'])->update(['state' => 'succeeded']);
// Custom nameservers (the second nameserver form; the first switches to the defaults).
$nsFields = enomFormFields($show(), $base.'nameservers', 1);
$response = enomHttp($owner, 'POST', $base.'nameservers', ['nameservers' => "ns1.new-host.com\nns2.new-host.com"] + $nsFields);
verify($response->headers->get('Location') === 'http://enom-tests.invalid/domains/'.$managed->id.'#nameservers' && $latest('nameservers')?->payload === ['nameservers' => ['ns1.new-host.com', 'ns2.new-host.com']]
    && (int) $latest('nameservers')->authorized_by === $owner->id, 'Nameserver change was not queued for the owner.');
$rows = clientRows();
verify(enomHttp($owner, 'POST', $base.'nameservers', ['nameservers' => "ns1.again.com\nns2.again.com"] + $nsFields)->getStatusCode() === 419 && clientRows() == $rows, 'A used form key was accepted again.');
$settle();
$nsFields = enomFormFields($show(), $base.'nameservers', 1);
$lockKey = enomFormFields($show(), $base.'lock')['_request_key'];
verify(enomHttp($owner, 'POST', $base.'nameservers', ['_request_key' => $lockKey, 'nameservers' => "ns1.x.com\nns2.x.com"] + $nsFields)->getStatusCode() === 419, 'A key issued for another action was accepted.');
$rows = clientRows();
enomHttp($owner, 'POST', $base.'nameservers', ['nameservers' => '192.0.2.1'] + $nsFields);
$retry = $show();
verify(clientRows() == $rows && str_contains(pageText($retry), 'Enter 2 to 12 nameservers') && enomFormFields($retry, $base.'nameservers', 1)['nameservers'] === '192.0.2.1', 'Invalid nameserver change was saved or the typed value lost.');
enomHttp($owner, 'POST', $base.'nameservers', enomFormFields($show(), $base.'nameservers', 0));
verify($latest('nameservers')->payload === ['nameservers' => ['dns1.registrar-servers.com', 'dns2.registrar-servers.com']], '"Use default nameservers" did not queue the defaults.');
$settle();
enomResetThrottle($owner);
// Contacts: matching roles default to "apply to all", sent as one all-roles request.
$contactFields = enomFormFields($show(), $base.'contacts', 0);
verify($contactFields['apply_all'] === '1' && $contactFields['contact_type'] === 'registrant', 'Matching contacts do not default to all roles.');
enomHttp($owner, 'POST', $base.'contacts', ['contact' => ['last_name' => 'Newname'] + $contactFields['contact']] + $contactFields);
verify($latest('contacts')?->payload['contact_type'] === 'all' && $latest('contacts')->payload['contact']['last_name'] === 'Newname', 'Apply-to-all contact change was not one all-roles request.');
$settle();
$single = enomFormFields($show(), $base.'contacts', 0);
enomHttp($owner, 'POST', $base.'contacts', ['apply_all' => '0'] + $single);
verify($latest('contacts')->payload['contact_type'] === 'registrant', 'Owner-only change was sent to every role.');
$settle();
$roleForm = enomFormFields($show(), $base.'contacts', 1);
verify($roleForm['contact_type'] === 'admin', 'Per-role contact form is missing.');
enomResetThrottle($owner);
// Lock, then the transfer-code email with its 10-minute cooldown.
$lockFields = enomFormFields($show(), $base.'lock');
enomHttp($owner, 'POST', $base.'lock', $lockFields);
verify($lockFields['locked'] === '0' && $latest('lock')?->payload === ['locked' => false], 'Lock switch did not queue unlocking.');
$settle();
$eppFields = enomFormFields($show(), $base.'epp_email');
$response = enomHttp($owner, 'POST', $base.'epp_email', $eppFields);
$firstEmail = $latest('epp_email');
verify($firstEmail?->state === 'queued' && str_ends_with((string) $response->headers->get('Location'), '#transfer'), 'Transfer-code email was not queued.');
$settle();
$waiting = $show();
verify(!in_array($base.'epp_email', array_map(fn ($node) => parse_url($node->getAttribute('action'), PHP_URL_PATH), iterator_to_array(enomDocument($waiting)->query('//form'))), true)
    && str_contains(pageText($waiting), 'Transfer code requested. You can ask again after'), 'Cooldown page still offers the transfer-code email.');
$firstEmail->update(['state' => 'rejected']);
$staleKey = enomFormFields($show(), $base.'epp_email')['_request_key'];
$firstEmail->update(['state' => 'succeeded']);
$rows = clientRows();
enomHttp($owner, 'POST', $base.'epp_email', ['_request_key' => $staleKey] + $eppFields);
$eppText = pageText($show());
// Refused safely, and the client sees the real reason (our own refusal text, not provider data).
verify(clientRows() == $rows && str_contains($eppText, 'A transfer code was requested in the last 10 minutes') && str_contains($eppText, 'Transfer code requested. You can ask again after'), 'Second transfer-code email within 10 minutes was accepted.');
enomResetThrottle($owner);
// DNS: refused when the server turns DNS tools off, even from a page loaded while they were on.
serverSetting('dns_tools', '1');
$hostsFields = enomFormFields($show(), $base.'hosts');
serverSetting('dns_tools', '0');
$rows = clientRows();
enomHttp($owner, 'POST', $base.'hosts', $hostsFields);
verify(clientRows() == $rows && str_contains(pageText($show()), 'DNS tools are turned off'), 'DNS change was accepted with DNS tools off.');
verify(!str_contains($show(), $base.'hosts') && !str_contains($show(), $base.'dnssec_add'), 'DNS forms are shown with DNS tools off.');
serverSetting('dns_tools', '1');
enomHttp($owner, 'POST', $base.'hosts', enomFormFields($show(), $base.'hosts'));
verify($latest('hosts')?->payload['records'] === [['name' => 'www', 'type' => 'A', 'address' => '192.0.2.10', 'mx_pref' => 10]], 'DNS change was refused with DNS tools on.');
serverSetting('dns_tools', '0');
$settle();
enomResetThrottle($owner);
// Renewal and ID Protect create invoices; a used key cannot create a second one.
$renewFields = enomFormFields($show(), '/domains/'.$managed->id.'/renew');
$response = enomHttp($owner, 'POST', '/domains/'.$managed->id.'/renew', $renewFields);
$renewInvoice = Invoice::where('user_id', $owner->id)->latest('id')->first();
verify(enomRedirectPath($response) === '/invoices/'.$renewInvoice->id && (string) $renewInvoice->items()->sole()->price === '14.00' && $latest('renew')?->state === 'awaiting_payment', 'Renew now did not create the renewal invoice.');
$rows = clientRows();
verify(enomHttp($owner, 'POST', '/domains/'.$managed->id.'/renew', $renewFields)->getStatusCode() === 419 && clientRows() == $rows, 'A used renewal key created another invoice.');
// 0.4: with a renewal invoice open for this expiry, the page offers that invoice instead of a term picker.
$openPage = $show();
verify(str_contains(pageText($openPage), 'Your renewal invoice for 1 year is ready') && str_contains($openPage, 'href="'.route('invoices.show', $renewInvoice).'"')
    && !str_contains($openPage, 'action="'.route('enom.domains.renew', $managed->id).'"'), 'Open renewal invoice not offered in place of the term picker.');
$privacyFields = enomFormFields($show(), '/domains/'.$managed->id.'/privacy/purchase');
$response = enomHttp($owner, 'POST', '/domains/'.$managed->id.'/privacy/purchase', $privacyFields);
$privacyInvoice = Invoice::where('user_id', $owner->id)->latest('id')->first();
verify(enomRedirectPath($response) === '/invoices/'.$privacyInvoice->id && (string) $privacyInvoice->items()->sole()->price === '5.00' && $latest('privacy_purchase')?->funding_basis === 'paid_invoice', 'ID Protect purchase did not create its invoice.');
enomResetThrottle($owner);
// A complimentary domain has no client renewal; a key from before the change creates nothing. ID Protect is still invoiced.
$freeDomain = clientDomain($owner, ['domain' => 'free-later.com']);
$staleRenew = enomFormFields(enomHttp($owner, 'GET', '/domains/'.$freeDomain->id)->getContent(), '/domains/'.$freeDomain->id.'/renew');
Pricing::setDomainMode($freeDomain->fresh(), 'complimentary');
$freePage = enomHttp($owner, 'GET', '/domains/'.$freeDomain->id)->getContent();
verify(!str_contains($freePage, '/domains/'.$freeDomain->id.'/renew"') && str_contains(pageText($freePage), 'Renewal price Complimentary'), 'Complimentary domain offers a paid renewal.');
$rows = clientRows();
$response = enomHttp($owner, 'POST', '/domains/'.$freeDomain->id.'/renew', $staleRenew);
verify(enomRedirectPath($response) === '/domains/'.$freeDomain->id && clientRows() == $rows, 'Client created a renewal invoice for a complimentary domain.');
verify(str_contains(pageText(enomHttp($owner, 'GET', '/domains/'.$freeDomain->id)->getContent()), 'We couldn’t create a renewal invoice right now'), 'Refused complimentary renewal has no explanation.');
enomHttp($owner, 'POST', '/domains/'.$freeDomain->id.'/privacy/purchase', enomFormFields($freePage, '/domains/'.$freeDomain->id.'/privacy/purchase'));
verify((string) Invoice::where('user_id', $owner->id)->latest('id')->first()->items()->sole()->price === '5.00', 'ID Protect was not invoiced on a complimentary domain.');
// Auto-renew off is recorded natively.
enomHttp($owner, 'POST', '/domains/'.$managed->id.'/renewal-preference', enomFormFields($show(), '/domains/'.$managed->id.'/renewal-preference'));
verify($managed->fresh()->service->cancellation !== null && str_contains(pageText($show()), 'Auto-renew is off.'), 'Turning auto-renew off was not recorded.');
verify(array_unique(array_column($providerCommands, 0)) === ['Check'], 'The client area sent something other than availability checks.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- at most 12 endings per search
for ($i = 1; $i <= 12; $i++) { enomTestTld(['server_id' => $server->id, 'tld' => 'x'.$i, 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '20.00', 'renew_price' => '20.00', 'transfer_price' => '20.00', 'sort_order' => 10 + $i]); }
$many = $results(enomHttp($owner, 'GET', '/domains/register?name=wide.x12')->getContent());
verify(count($many) === 12 && $many[0] === 'wide.x12' && count(array_unique($many)) === 12, 'Search checks more than 12 endings or loses the typed ending: '.count($many));

echo 'Client controller: '.$checks.' passed; real routes and sessions, injected Check only.'.PHP_EOL;
$successful = true;

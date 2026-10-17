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
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
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
foreach (['/domains', '/domains/register?name=hello&tld=com&years=2&go=1', '/domains/transfer', '/domains/help', '/domains/'.$mine->id, '/domains/order/'.str_repeat('a', 48)] as $uri) {
    $response = enomHttp(null, 'GET', $uri);
    verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/login', 'Guest reached GET '.$uri);
}
$posts = ['/domains/quote', '/domains/order', '/domains/'.$mine->id.'/refresh', '/domains/'.$mine->id.'/renew', '/domains/'.$mine->id.'/privacy/purchase',
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
// Help is for signed-in clients; the old staff help address is staff-only.
verify(enomHttp($owner, 'GET', '/domains/help')->getStatusCode() === 200 && enomHttp($owner, 'GET', '/admin/enom/help')->getStatusCode() === 403, 'Help pages ignore their audience.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- list: sort, filter, search and pagination are whitelisted
$mine->update(['domain' => 'bravo.net', 'sld' => 'bravo', 'tld' => 'net', 'provider_expires_at' => '2027-06-01']);
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
verify(array_keys($data) === ['domain', 'status', 'complimentary', 'price', 'transfer_price', 'renew_price', 'min_years', 'max_years', 'terms'] && $data['status'] === 'available'
    && $data['price'] === '$12.00' && $data['transfer_price'] === '$13.00' && $data['renew_price'] === '$14.00', 'Available answer has unexpected fields or prices: '.$ok->getContent());
// 0.4 terms: 1-10 years, first year at the registration price and later years at the renewal price.
verify($data['min_years'] === 1 && $data['max_years'] === 10 && array_keys($data['terms']) === range(1, 10) && $data['terms']['1'] === '$12.00'
    && $data['terms']['2'] === '$26.00' && $data['terms']['10'] === '$138.00', 'Check answer terms differ: '.json_encode($data['terms']));
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
verify($longTerm['min_years'] === 2 && $longTerm['max_years'] === 10 && array_keys($longTerm['terms']) === range(2, 10) && $longTerm['terms']['2'] === '$26.00', 'Two-year-minimum name terms differ: '.json_encode($longTerm));
$flaky = $check($owner, 'flaky.com')->getContent(); verify((json_decode($flaky, true)['message'] ?? null) === 'Couldn’t check this name.', 'Provider failure message is not generic: '.$flaky);
$throttled = client('throttled@example.com');
$codes = [];
for ($i = 0; $i < 61; $i++) { $codes[] = $check($throttled, 'x')->getStatusCode(); }
verify(count(array_filter($codes, fn ($code) => $code === 422)) === 60 && end($codes) === 429, 'Check is not limited to 60 requests a minute: '.json_encode(array_count_values($codes)));
verify($check($owner, 'x')->getStatusCode() === 422, 'One client\'s throttle blocked another client.');
// Availability checks use a separate route bucket from quotes and orders.
$searcher = client('two-searches@example.com');
$searchKey = enomFormFields(enomHttp($searcher, 'GET', '/domains/register?name=fresh-name')->getContent(), '/domains/quote');
for ($i = 0; $i < 24; $i++) { $check($searcher, 'x'); }
$afterSearches = enomHttp($searcher, 'POST', '/domains/quote', $searchKey);
verify($afterSearches->getStatusCode() !== 429, 'Availability checks blocked a later quote.');
OrderIntent::where('user_id', $searcher->id)->delete();

enomResetThrottle($owner);
// ---------------------------------------------------------------- quote
$quoteFields = enomFormFields(enomHttp($owner, 'GET', '/domains/register?name=fresh-name')->getContent(), '/domains/quote');
verify($quoteFields['domain'] === 'fresh-name.com' && $quoteFields['action'] === 'register' && strlen($quoteFields['_request_key']) === 64, 'Register form lacks its name, action or key.');
$before = clientRows();
verify(enomHttp($owner, 'POST', '/domains/quote', ['_request_key' => str_repeat('c', 64)] + $quoteFields)->getStatusCode() === 419, 'Quote accepted a forged form key.');
$refusals = [
    'fresh-name.org' => ['/domains/register', '.org isn’t available here'],
    'taken-name.com' => ['/domains/register', 'taken-name.com isn’t available to register'],
    'bravo.net' => ['/domains/register', 'bravo.net is already managed here'],
    'longterm.com' => ['/domains/register', 'longterm.com isn’t available to register'],
    'shop.hello.com' => ['/domains/register', 'without a subdomain'],
    'fresh_name.com' => ['/domains/register', 'Use only letters a–z, numbers and hyphens'],
];
foreach ($refusals as $name => [$path, $message]) {
    $response = enomHttp($owner, 'POST', '/domains/quote', ['domain' => $name] + $quoteFields);
    verify(enomRedirectPath($response) === $path, 'Refused quote for '.$name.' went to '.enomRedirectPath($response));
    $shown = pageText(enomHttp($owner, 'GET', $response->headers->get('Location'))->getContent());
    verify(str_contains($shown, $message) && !str_contains($shown, '7f3a'), 'Refused quote for '.$name.' did not explain: '.$message);
}
enomHttp($owner, 'POST', '/domains/quote', ['domain' => 'flaky.com'] + $quoteFields);
$flakyText = pageText(enomHttp($owner, 'GET', '/domains/register?name=flaky.com')->getContent());
verify(!str_contains($flakyText, '7f3a'), 'Quote shows provider error text.');
verify(str_contains($flakyText, 'We couldn’t check flaky.com right now'), 'Provider failure did not show a temporary lookup error.');
verify(clientRows() == $before, 'Refused quotes created intents or orders.');
$response = enomHttp($owner, 'POST', '/domains/quote', ['action' => 'renew'] + $quoteFields);
$intentId = substr((string) enomRedirectPath($response), strlen('/domains/order/'));
$intent = OrderIntent::find($intentId);
verify($intent && $intent->user_id === $owner->id && $intent->action === 'register' && $intent->amount === '12.00' && $intent->renewal_amount === '14.00', 'Quote did not create a register intent at retail price.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- order page: prefill, defaults and ownership
$orderPage = enomHttp($owner, 'GET', '/domains/order/'.$intentId)->getContent();
$fields = enomFormFields($orderPage, '/domains/order');
$expectedContact = ['first_name' => 'Client', 'last_name' => 'Owner', 'organization_name' => '', 'email_address' => 'client.owner@example.com', 'phone' => '+1.2065550100',
    'address1' => '1 Main Street', 'address2' => '', 'city' => 'Seattle', 'state_province' => 'WA', 'postal_code' => '98101', 'country' => 'US'];
verify(array_intersect_key($fields['contact'], $expectedContact) == $expectedContact, 'Order form is not prefilled from the profile: '.json_encode($fields['contact']));
verify($fields['same_contact'] === '1' && $fields['ns_mode'] === 'default' && $fields['intent_id'] === $intentId, 'Order form does not default to one contact and default nameservers.');
verify(str_contains(pageText($orderPage), 'dns1.registrar-servers.com, dns2.registrar-servers.com') && str_contains(pageText($orderPage), 'Due today $12.00'), 'Order page hides the default nameservers or the price.');
noCosts($orderPage, 'Order page shows Enom costs');
verify(enomHttp($other, 'GET', '/domains/order/'.$intentId)->getStatusCode() === 404, 'Another client opened this quote.');
$before = clientRows();
verify(enomHttp($other, 'POST', '/domains/order', $fields)->getStatusCode() === 404 && clientRows() == $before, 'Another client placed this quote.');
verify(enomHttp($owner, 'POST', '/domains/order', ['intent_id' => 'not-an-intent'] + $fields)->getStatusCode() === 422, 'Malformed intent id was not refused.');

// Custom nameservers: 2 to 12 hostnames, no IP addresses, no duplicates. The typed list comes back for correction.
$nsCases = [
    "ns1.example-host.com" => 'Enter 2 to 12 nameservers',
    implode("\n", array_map(fn ($n) => 'ns'.$n.'.example-host.com', range(1, 13))) => 'Enter 2 to 12 nameservers',
    "192.0.2.53\nns2.example-host.com" => 'not IP addresses',
    "ns1.example-host.com\nNS1.Example-Host.com." => 'Each nameserver must be different',
    "ns1.example-host.com\nbad_host!" => 'valid nameserver hostnames',
];
$nsHandled = true;
foreach ($nsCases as $typed => $message) {
    $before = clientRows();
    $response = enomHttp($owner, 'POST', '/domains/order', ['ns_mode' => 'custom', 'nameservers' => $typed] + $fields);
    verify(clientRows() == $before, 'Invalid nameservers created an order: '.json_encode($typed));
    $back = enomRedirectPath($response) === '/domains/order/'.$intentId;
    $retry = $back ? enomHttp($owner, 'GET', '/domains/order/'.$intentId)->getContent() : '';
    $retained = $back ? enomFormFields($retry, '/domains/order') : [];
    $nsHandled = $nsHandled && $back && str_contains(pageText($retry), $message) && ($retained['ns_mode'] ?? null) === 'custom' && ($retained['nameservers'] ?? null) === $typed;
}
verify($nsHandled, 'Invalid nameservers lost their field error or typed values.');
// Missing owner fields are reported together and the typed values come back.
$response = enomHttp($owner, 'POST', '/domains/order', ['contact' => ['first_name' => 'Updated', 'last_name' => '', 'phone' => '12'] + $fields['contact']] + $fields);
$retry = enomHttp($owner, 'GET', '/domains/order/'.$intentId)->getContent();
$retained = enomFormFields($retry, '/domains/order');
verify(enomRedirectPath($response) === '/domains/order/'.$intentId && $retained['contact']['first_name'] === 'Updated' && enomDocument($retry)->query('//*[@aria-invalid="true"]')->length >= 2
    && str_contains(pageText($retry), 'Enter the number with + and the country code'), 'Owner field errors are not shown together with the typed values.');
enomResetThrottle($owner);
// Valid order with custom nameservers: one invoice, four identical contacts, the chosen list.
$response = enomHttp($owner, 'POST', '/domains/order', ['ns_mode' => 'custom', 'nameservers' => "NS1.New-Host.com\nns2.new-host.com.\nns3.new-host.com"] + $fields);
$invoice = Invoice::where('user_id', $owner->id)->latest('id')->first();
verify(enomRedirectPath($response) === '/invoices/'.$invoice?->id && $invoice->status === 'pending' && (string) $invoice->items()->sole()->price === '12.00', 'Paid order did not lead to its invoice.');
$intent = $intent->fresh();
$payload = $intent->payload;
verify(array_keys($payload['contacts']) === ['registrant', 'admin', 'tech', 'auxbilling'] && count(array_unique(array_map('json_encode', $payload['contacts']))) === 1
    && $payload['contacts']['registrant']['phone'] === '+1.2065550100', 'Same contact for all did not produce four identical roles.');
verify($payload['nameservers'] === ['ns1.new-host.com', 'ns2.new-host.com', 'ns3.new-host.com'] && $payload['epp'] === null, 'Custom nameservers were not normalized or EPP was stored for a registration.');
$operation = Operation::where('invoice_id', $invoice->id)->sole();
verify($operation->state === 'awaiting_payment' && $operation->funding_basis === 'paid_invoice' && $operation->action === 'register', 'Registration is not waiting for its payment.');
$rows = clientRows();
verify(enomRedirectPath(enomHttp($owner, 'POST', '/domains/order', $fields)) === '/invoices/'.$invoice->id && clientRows() == $rows, 'Resubmitting a placed order created another order.');
verify(enomRedirectPath(enomHttp($owner, 'GET', '/domains/order/'.$intentId)) === '/invoices/'.$invoice->id, 'Placed quote page does not lead to its invoice.');
enomResetThrottle($owner);
// Separate contacts: a filled role is kept, blank roles use the owner; default nameservers when chosen.
$fresh = fn (string $name, string $action = 'register', $user = null) => substr((string) enomRedirectPath(enomHttp($user ?? $owner, 'POST', '/domains/quote', ['domain' => $name, 'action' => $action] + $quoteFields)), strlen('/domains/order/'));
$secondId = $fresh('second-name.com');
verify(strlen($secondId) === 48, 'Second quote was refused.');
$fields2 = enomFormFields(enomHttp($owner, 'GET', '/domains/order/'.$secondId)->getContent(), '/domains/order');
$adminContact = ['first_name' => 'Staff', 'last_name' => 'Admin', 'address1' => '9 Ops Road', 'city' => 'Portland', 'postal_code' => '97201', 'country' => 'US',
    'email_address' => 'staff.admin@example.com', 'phone' => '+1 503 555 0199'];
enomHttp($owner, 'POST', '/domains/order', ['same_contact' => '0', 'roles' => ['admin' => $adminContact, 'tech' => ['country' => 'US'], 'auxbilling' => []]] + $fields2);
$payload = OrderIntent::find($secondId)->payload;
verify($payload['contacts']['admin']['first_name'] === 'Staff' && $payload['contacts']['admin']['phone'] === '+1.5035550199' && $payload['contacts']['tech'] === $payload['contacts']['registrant']
    && $payload['contacts']['auxbilling'] === $payload['contacts']['registrant'], 'Separate contacts were not kept or blank roles did not use the owner.');
verify($payload['nameservers'] === ['dns1.registrar-servers.com', 'dns2.registrar-servers.com'], 'Default nameservers were not used.');
enomResetThrottle($owner);
// An expired quote is refreshed and the typed details come back.
$thirdId = $fresh('third-name.com');
$fields3 = enomFormFields(enomHttp($owner, 'GET', '/domains/order/'.$thirdId)->getContent(), '/domains/order');
Carbon\Carbon::setTestNow(now()->addMinutes(31)); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$response = enomHttp($owner, 'POST', '/domains/order', ['contact' => ['first_name' => 'Changed'] + $fields3['contact']] + $fields3);
$refreshed = substr((string) enomRedirectPath($response), strlen('/domains/order/'));
$retry = enomHttp($owner, 'GET', '/domains/order/'.$refreshed)->getContent();
verify($refreshed !== $thirdId && strlen($refreshed) === 48 && enomFormFields($retry, '/domains/order')['contact']['first_name'] === 'Changed'
    && str_contains(pageText($retry), 'Your price quote expired, so we refreshed it'), 'Expired quote was not refreshed with the typed details.');
verify(!Invoice::where('user_id', $owner->id)->where('id', '>', $invoice->id + 1)->exists(), 'Expired quote created an invoice.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- transfer: EPP required and never echoed
$transferForm = enomFormFields(enomHttp($owner, 'GET', '/domains/transfer?name=moveme.com')->getContent(), '/domains/quote');
verify($transferForm['action'] === 'transfer' && $transferForm['domain'] === 'moveme' && $transferForm['tld'] === 'com', 'Transfer form does not split the typed name.');
$response = enomHttp($owner, 'POST', '/domains/quote', ['domain' => 'brand-new', 'tld' => 'com'] + $transferForm);
verify(enomRedirectPath($response) === '/domains/transfer' && str_contains(pageText(enomHttp($owner, 'GET', '/domains/transfer')->getContent()), 'can’t be transferred here'), 'Unregistered name was quoted for transfer.');
enomResetThrottle($owner);
$transferId = substr((string) enomRedirectPath(enomHttp($owner, 'POST', '/domains/quote', $transferForm)), strlen('/domains/order/'));
$transferPage = enomHttp($owner, 'GET', '/domains/order/'.$transferId)->getContent();
$tFields = enomFormFields($transferPage, '/domains/order');
verify(array_key_exists('epp', $tFields) && !array_key_exists('ns_mode', $tFields) && str_contains(pageText($transferPage), 'Due today $13.00'), 'Transfer order lacks its code field or offers nameservers.');
$before = clientRows();
enomHttp($owner, 'POST', '/domains/order', ['epp' => '   '] + $tFields);
verify(str_contains(pageText(enomHttp($owner, 'GET', '/domains/order/'.$transferId)->getContent()), 'Enter the transfer code from your current registrar') && clientRows() == $before, 'Missing transfer code was accepted.');
$secret = 'EPP-Secret-'.bin2hex(random_bytes(4)).'<b>';
enomHttp($owner, 'POST', '/domains/order', ['epp' => $secret, 'contact' => ['email_address' => 'not-an-email'] + $tFields['contact']] + $tFields);
$retry = enomHttp($owner, 'GET', '/domains/order/'.$transferId)->getContent();
verify(str_contains(pageText($retry), 'Enter an address like name@example.com') && !str_contains($retry, 'EPP-Secret') && enomFormFields($retry, '/domains/order')['epp'] === '', 'Transfer code was echoed back after a validation error.');
verify(clientRows() == $before, 'Invalid transfer order changed data.');
enomHttp($owner, 'POST', '/domains/order', ['epp' => $secret] + $tFields);
$transferIntent = OrderIntent::find($transferId);
verify($transferIntent->claimed_at && $transferIntent->payload['epp'] === $secret && $transferIntent->payload['nameservers'] === []
    && !str_contains((string) Illuminate\Support\Facades\DB::table('enom_order_intents')->where('id', $transferId)->value('payload'), 'EPP-Secret'), 'Transfer code was not stored encrypted with the order.');

enomResetThrottle($owner);
// ---------------------------------------------------------------- complimentary client: no charge, a paid $0 record, then the domain page
$vip = client('vip.client@example.com', ['first_name' => 'Complimentary']);
grantComplimentary($vip);
$vipSearch = enomHttp($vip, 'GET', '/domains/register?name=gift')->getContent();
verify(str_contains(pageText($vipSearch), 'Domains are complimentary on your account') && !str_contains(pageText($vipSearch), '$12.00'), 'Complimentary client sees prices on search.');
$vipCheck = json_decode($check($vip, 'gift.com')->getContent(), true);
verify($vipCheck['complimentary'] === true && $vipCheck['price'] === 'Complimentary' && $vipCheck['renew_price'] === null, 'Complimentary check answer shows a price.');
$vipQuote = enomFormFields($vipSearch, '/domains/quote');
$vipId = substr((string) enomRedirectPath(enomHttp($vip, 'POST', '/domains/quote', $vipQuote)), strlen('/domains/order/'));
$vipPage = enomHttp($vip, 'GET', '/domains/order/'.$vipId)->getContent();
verify(str_contains(pageText($vipPage), 'Due today Nothing') && str_contains(pageText($vipPage), 'Register domain'), 'Complimentary order page asks for payment.');
$response = enomHttp($vip, 'POST', '/domains/order', enomFormFields($vipPage, '/domains/order'));
$gift = Domain::where('user_id', $vip->id)->where('domain', 'gift.com')->first();
verify($gift && enomRedirectPath($response) === '/domains/'.$gift->id && $gift->status === 'registration_pending', 'Complimentary order did not lead to its domain page.');
$record = Invoice::where('user_id', $vip->id)->sole();
verify($record->status === 'paid' && (string) $record->items()->sole()->price === '0.00' && str_contains($record->items()->sole()->description, 'complimentary'), 'Complimentary order lacks a paid $0.00 record.');
$giftOperation = Operation::where('domain_id', $gift->id)->sole();
verify($giftOperation->funding_basis === 'complimentary' && $giftOperation->state === 'queued' && $giftOperation->invoice_id === null && count(queuedJobs(ExecuteOperation::class)) === 1, 'Complimentary registration was not queued at the reseller\'s cost.');
verify(str_contains(pageText(enomHttp($vip, 'GET', '/domains/'.$gift->id)->getContent()), 'Order placed. We’re registering gift.com now.'), 'Complimentary order page lacks its confirmation.');

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
for ($i = 1; $i <= 12; $i++) { Tld::create(['server_id' => $server->id, 'tld' => 'x'.$i, 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '20.00', 'renew_price' => '20.00', 'transfer_price' => '20.00', 'sort_order' => 10 + $i]); }
$many = $results(enomHttp($owner, 'GET', '/domains/register?name=wide.x12')->getContent());
verify(count($many) === 12 && $many[0] === 'wide.x12' && count(array_unique($many)) === 12, 'Search checks more than 12 endings or loses the typed ending: '.count($many));

echo 'Client controller: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; real routes and sessions, injected Check only.'.PHP_EOL;
$successful = true;

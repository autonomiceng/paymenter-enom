<?php
// 0.4 public domain search through real routes: guests can open the search and price list and POST availability
// checks (with CSRF), see pre-tax catalogue prices and never complimentary status, costs or other clients' records,
// share a per-IP limit with short cached answers, and must sign in to quote or order. /domains/search redirects to the
// canonical page. Disposable native app, real sessions, injected Check transport only.
$enomTestLabel = 'public-search';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use Paymenter\Extensions\Servers\Enom\Http\Navigation;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;

$owner = client('public.owner@example.com');
$free = client('public.free@example.com');
Pricing::setClientComplimentary($free, true, null, null);
$held = clientDomain($owner, ['domain' => 'held-name.com']);
/** Visible text, scripts and styles removed. */
function visible(string $html): string { return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('#<(style|script|svg)\b.*?</\1>#si', '', $html))))); }
function costFree(string $body, string $message): void {
    $body = preg_replace('#<(svg|style|script)\b.*?</\1>#si', '', $body);
    foreach (ENOM_COST_MARKERS as $marker) { verify(!str_contains($body, $marker), $message.' (contains '.$marker.')'); }
}
$guestCheck = function (string $name, array $options = []): Symfony\Component\HttpFoundation\Response {
    return enomHttp(null, 'POST', '/domains/check', ['domain' => $name], ['csrf' => true, 'json' => true] + $options);
};

// ---------------------------------------------------------------- pages anyone can open
$search = enomHttp(null, 'GET', '/domains/register?name=fresh-idea');
verify($search->getStatusCode() === 200, 'Guest could not open the search: '.$search->getStatusCode());
$text = visible($search->getContent());
verify(str_contains($text, 'Find a domain') && str_contains($text, '.com $12.00/yr') && str_contains($text, '.net $15.00/yr') && str_contains($text, 'Prices plus tax where applicable'),
    'Guest search lacks the endings, pre-tax prices or the tax note.');
costFree($search->getContent(), 'Guest search shows Enom costs');
$xpath = enomDocument($search->getContent());
// Guests choose a term here, then sign in; the form is a GET back to this page with go=1, never a quote POST.
verify($xpath->query('//form[@action="'.route('enom.domains.quote').'"]')->length === 0 && $xpath->query('//input[@name="_request_key"]')->length === 0, 'Guest search carries quote forms or form keys.');
$buyForm = $xpath->query('//form[@data-when="available"]')->item(0);
$buy = [];
foreach ($xpath->query('.//input[@name]', $buyForm) as $input) { $buy[$input->getAttribute('name')] = $input->getAttribute('value'); }
$buy['years'] = $xpath->query('.//select[@name="years"]/option', $buyForm)->item(0)?->getAttribute('value');
verify(strtoupper($buyForm->getAttribute('method')) === 'GET' && parse_url($buyForm->getAttribute('action'), PHP_URL_PATH) === '/domains/register', 'Guest register form is not a GET back to the search.');
verify(($buy['go'] ?? null) === '1' && ($buy['name'] ?? null) === 'fresh-idea' && ($buy['tld'] ?? null) === 'com' && ($buy['years'] ?? null) === '1', 'Guest register form does not return to the search with its choice: '.json_encode($buy));
$resume = enomHttp(null, 'GET', '/domains/register?name=fresh-idea&tld=com&years=3&go=1');
verify($resume->getStatusCode() === 302 && enomRedirectPath($resume) === '/login', 'Guest Register did not ask to sign in.');
// After signing in the same choice opens with the chosen term preselected.
$back = enomHttp($owner, 'GET', '/domains/register?name=fresh-idea&tld=com&years=3&go=1');
$backXpath = enomDocument($back->getContent());
verify($back->getStatusCode() === 200 && $backXpath->query('//select[@name="years"]/option[@selected and @value="3"]')->length >= 1, 'Signed-in return lost the chosen term.');
$pricing = enomHttp(null, 'GET', '/domains/pricing');
verify($pricing->getStatusCode() === 200, 'Guest could not open the price list.');
$priceText = visible($pricing->getContent());
foreach (['.com', '$12.00', '$14.00', '$13.00', '.net', '$15.00', '$16.00', '$15.50', 'Prices plus tax where applicable'] as $expected) {
    verify(str_contains($priceText, $expected), 'Price list lacks '.$expected);
}
verify(!str_contains($priceText, '.org'), 'Price list shows a disabled ending.');
costFree($pricing->getContent(), 'Price list shows Enom costs');
// The canonical page is /domains/register; /domains/search is a permanent redirect that keeps the query.
$alias = enomHttp(null, 'GET', '/domains/search?name=alias-name&tld=net');
verify($alias->getStatusCode() === 301 && enomRedirectPath($alias) === '/domains/register' && str_contains((string) $alias->headers->get('Location'), 'name=alias-name')
    && str_contains((string) $alias->headers->get('Location'), 'tld=net'), 'Search alias did not redirect permanently with its query.');
verify(Navigation::domains()['route'] === 'enom.domains.search' && Navigation::domains()['spa'] === false, 'Top navigation Domains entry differs.');
verify(in_array('Domains', array_column(array_filter(Illuminate\Support\Facades\Event::dispatch('navigation'), 'is_array'), 'name'), true), 'Navigation event does not add Domains.');

// ---------------------------------------------------------------- the JSON check for guests
enomHttp(null, 'GET', '/domains/register'); // Session and XSRF cookie.
verify($guestCheck('fresh-idea.com', ['token' => false])->getStatusCode() === 419, 'Guest check accepted a request without a CSRF token.');
$answer = $guestCheck('fresh-idea.com');
$data = json_decode($answer->getContent(), true);
verify($answer->getStatusCode() === 200 && $data['status'] === 'available' && $data['complimentary'] === false && $data['price'] === '$12.00'
    && $data['renew_price'] === '$14.00' && $data['terms']['3'] === '$40.00' && !isset($data['url']), 'Guest availability answer differs: '.$answer->getContent());
costFree($answer->getContent(), 'Guest check shows Enom costs');
// A name held in the portal is just taken for a guest (and another client); its owner sees it as theirs.
$heldAnswer = json_decode($guestCheck('held-name.com')->getContent(), true);
verify($heldAnswer['status'] === 'taken' && !str_contains(json_encode($heldAnswer), '/domains/'.$held->id), 'Guest learned that a name is held in this portal: '.json_encode($heldAnswer));
verify(json_decode(enomHttp($owner, 'POST', '/domains/check', ['domain' => 'held-name.com'], ['json' => true])->getContent(), true)['status'] === 'yours', 'Owner did not see their own name.');
$otherHeld = json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'held-name.com'], ['json' => true])->getContent(), true);
verify($otherHeld['status'] === 'taken' && !isset($otherHeld['url']), 'Another client learned the owner URL.');
// Complimentary clients see complimentary prices; guests never do.
verify(json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'gift-idea.com'], ['json' => true])->getContent(), true)['price'] === 'Complimentary', 'Complimentary client saw prices.');
verify(json_decode($guestCheck('gift-idea.com')->getContent(), true)['price'] === '$12.00', 'Guest saw complimentary pricing.');

// ---------------------------------------------------------------- per-IP limit with short cached answers
Illuminate\Support\Facades\Cache::flush();
foreach (['limited' => '198.51.100.20', 'other-ip' => '198.51.100.21'] as $jar => $ip) { enomHttp(null, 'GET', '/domains/register', [], ['ip' => $ip, 'jar' => $jar]); } // Sessions and XSRF cookies.
$providerBefore = count($providerCommands);
$codes = [];
for ($i = 0; $i < 10; $i++) { $codes[] = $guestCheck('limit-'.$i.'.com', ['ip' => '198.51.100.20', 'jar' => 'limited'])->getStatusCode(); }
// A repeated name within a minute is answered from the cache: no Check and no use of the address's budget.
for ($i = 0; $i < 5; $i++) { $codes[] = $guestCheck('limit-0.com', ['ip' => '198.51.100.20', 'jar' => 'limited'])->getStatusCode(); }
verify(count($providerCommands) === $providerBefore + 10, 'A cached guest answer was checked with Enom again.');
for ($i = 10; $i < 31; $i++) { $codes[] = $guestCheck('limit-'.$i.'.com', ['ip' => '198.51.100.20', 'jar' => 'limited'])->getStatusCode(); }
verify(array_count_values($codes) === [200 => 35, 429 => 1] && end($codes) === 429, 'Guest checks are not limited to 30 new names a minute per address: '.json_encode(array_count_values($codes)));
verify(count($providerCommands) === $providerBefore + 30, 'The refused guest check reached Enom.');
$limited = $guestCheck('limit-31.com', ['ip' => '198.51.100.20', 'jar' => 'limited']);
verify($limited->getStatusCode() === 429 && (int) $limited->headers->get('Retry-After') > 0 && json_decode($limited->getContent(), true)['status'] === 'error', 'Limited answer lacks Retry-After or a generic error.');
// Another address, and signed-in clients, are unaffected.
verify($guestCheck('other-address.com', ['ip' => '198.51.100.21', 'jar' => 'other-ip'])->getStatusCode() === 200, 'One address\'s limit blocked another.');
verify(enomHttp($owner, 'POST', '/domains/check', ['domain' => 'signed-in.com'], ['json' => true])->getStatusCode() === 200, 'The guest limit blocked a signed-in client.');
// The guest cache never answers a signed-in client (who may be complimentary or own the name).
verify(json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'limit-1.com'], ['json' => true])->getContent(), true)['price'] === 'Complimentary', 'A cached guest answer was served to a client.');
// After a minute the address may search again.
Carbon\Carbon::setTestNow(now()->addSeconds(61)); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
verify($guestCheck('after-minute.com', ['ip' => '198.51.100.20', 'jar' => 'limited'])->getStatusCode() === 200, 'Guest limit did not reset after a minute.');

// ---------------------------------------------------------------- ordering still needs an account
$before = clientRows();
foreach ([['POST', '/domains/quote', ['domain' => 'fresh-idea.com', 'action' => 'register', 'years' => 2]], ['POST', '/domains/order', []], ['GET', '/domains/transfer', []], ['GET', '/domains', []],
    ['GET', '/domains/order/'.str_repeat('a', 48), []]] as [$method, $uri, $payload]) {
    $response = enomHttp(null, $method, $uri, $payload);
    verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/login', 'Guest reached '.$method.' '.$uri.': '.$response->getStatusCode());
}
verify(clientRows() == $before, 'Guest requests wrote business rows.');
verify(!array_filter($providerCommands, fn ($command) => $command[0] !== 'Check'), 'Public pages sent a provider command other than Check.');

echo 'Public search: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; real routes and sessions, injected Check only.'.PHP_EOL;
$successful = true;

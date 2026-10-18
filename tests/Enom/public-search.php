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
// Search links to native checkout with a priced plan and full domain prefill.
$link = $xpath->query('//a[@data-slot="register"]')->item(0);
verify($link !== null && str_contains($link->getAttribute('href'), 'config%5Bdomain%5D=fresh-idea.com') && str_contains($link->getAttribute('href'), 'plan='), 'Native checkout link lacks name or plan.');
verify($xpath->query('//input[@name="_request_key"]')->length===0,'Search retains an order form key.');
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
verify($answer->getStatusCode() === 200 && $data['status'] === 'available' && $data['price'] === '$12.00'
    && $data['renew_price'] === '$14.00' && $data['terms']['3'] === '$36.00' && !isset($data['url']), 'Guest availability answer differs: '.$answer->getContent());
costFree($answer->getContent(), 'Guest check shows Enom costs');
// A name held in the portal is just taken for a guest (and another client); its owner sees it as theirs.
$heldAnswer = json_decode($guestCheck('held-name.com')->getContent(), true);
verify($heldAnswer['status'] === 'taken' && !str_contains(json_encode($heldAnswer), '/domains/'.$held->id), 'Guest learned that a name is held in this portal: '.json_encode($heldAnswer));
verify(json_decode(enomHttp($owner, 'POST', '/domains/check', ['domain' => 'held-name.com'], ['json' => true])->getContent(), true)['status'] === 'yours', 'Owner did not see their own name.');
$otherHeld = json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'held-name.com'], ['json' => true])->getContent(), true);
verify($otherHeld['status'] === 'taken' && !isset($otherHeld['url']), 'Another client learned the owner URL.');
// Complimentary clients see complimentary prices; guests never do.
verify(json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'gift-idea.com'], ['json' => true])->getContent(), true)['price'] === '$12.00', 'Complimentary client saw prices.');
verify(json_decode($guestCheck('gift-idea.com')->getContent(), true)['price'] === '$12.00', 'Guest saw complimentary pricing.');

// Enom TEST can call a registry-unregistered name transferable. An authoritative RDAP 404 blocks it.
$availability['asdfsadfsafd.com'] = 'transferable';
$rdapRequests = [];
$app->instance(\Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class,
    new \Paymenter\Extensions\Servers\Enom\Services\TransferOrders(new \Paymenter\Extensions\Servers\Enom\Services\Rdap(function ($url) use (&$rdapRequests) {
        $rdapRequests[] = $url;
        return $url === 'https://data.iana.org/rdap/dns.json'
            ? ['status' => 200, 'body' => json_encode(['services' => [[['com'], ['https://rdap.example.com/']]]])]
            : ['status' => 404, 'body' => ''];
    })));
$reason = \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::reason('transfer_not_registered');
$checked = app(\Paymenter\Extensions\Servers\Enom\Services\DomainOrders::class)->check(null, 'asdfsadfsafd.com');
verify(!$checked['available'] && !$checked['transferable'] && $checked['transfer_reason'] === $reason
    && $checked['warning'] === null && $checked['transfer_price'] === null && $checked['transfer_url'] === null, 'Registry 404 left a transfer offer in DomainOrders.');
$answer = json_decode($guestCheck('asdfsadfsafd.com')->getContent(), true);
verify($answer['status'] === 'invalid' && $answer['transfer_reason'] === $reason && $answer['warning'] === null
    && $answer['transfer_price'] === null && $answer['transfer_url'] === null, 'Registry 404 search answer offers a transfer or says taken.');
verify(count(array_filter($rdapRequests, fn ($url) => $url === 'https://rdap.example.com/domain/asdfsadfsafd.com')) === 2, 'Registry 404 did not use the domain endpoint.');
$transferPage = enomHttp($owner, 'GET', '/domains/transfer?name=asdfsadfsafd.com');
verify($transferPage->getStatusCode() === 200 && str_contains(visible($transferPage->getContent()), $reason)
    && !str_contains($transferPage->getContent(), 'config%5Bdomain%5D=asdfsadfsafd.com'), 'Server-rendered transfer page lost the reason or offers checkout.');
// Native checkout rechecks eligibility before creating any order or invoice.
require_once dirname(__DIR__).'/enom-cart-order.php';
$beforeBlocked = clientRows();
$blockedCart = enomCartStart($owner);
$transferProduct = app(\Paymenter\Extensions\Servers\Enom\Services\Catalogue::class)->productFor($com, 'transfer');
$transferPlan = $transferProduct->plans()->where('billing_period', 1)->firstOrFail();
try { $blockedCart->items()->create(['product_id' => $transferProduct->id, 'plan_id' => $transferPlan->id, 'quantity' => 1,
    'config_options' => [], 'checkout_config' => ['domain' => 'asdfsadfsafd.com']]); throw new RuntimeException('Registry 404 passed checkout.'); }
catch (\App\Exceptions\DisplayException $error) { verify($error->getMessage() === $reason, 'Blocked checkout lost the registry reason.'); }
verify(clientRows() === $beforeBlocked, 'Blocked transfer checkout wrote business rows.');

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
verify(json_decode(enomHttp($free, 'POST', '/domains/check', ['domain' => 'limit-1.com'], ['json' => true])->getContent(), true)['price'] === '$12.00', 'A cached guest answer was served to a client.');
// After a minute the address may search again.
Carbon\Carbon::setTestNow(now()->addSeconds(61)); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
verify($guestCheck('after-minute.com', ['ip' => '198.51.100.20', 'jar' => 'limited'])->getStatusCode() === 200, 'Guest limit did not reset after a minute.');

// ---------------------------------------------------------------- ordering still needs an account
$before = clientRows();
foreach ([['GET','/domains/transfer'],['GET','/domains']] as [$method,$uri]) {
    verify(enomRedirectPath(enomHttp(null,$method,$uri))==='/login','Private domain page did not require login.');
}
foreach ([['POST','/domains/quote'],['POST','/domains/order'],['GET','/domains/order/'.str_repeat('a',48)]] as [$method,$uri]) {
    verify(enomHttp(null,$method,$uri)->getStatusCode()===404,'Removed guest order endpoint remains available.');
}
verify(clientRows() == $before, 'Guest requests wrote business rows.');
verify(!array_filter($providerCommands, fn ($command) => $command[0] !== 'Check'), 'Public pages sent a provider command other than Check.');

echo 'Public search: '.$checks.' passed; real routes and sessions, injected Check only.'.PHP_EOL;
$successful = true;

<?php
// Every client view compiles, and each page renders through its real route for the states clients meet:
// pending registration, active, complimentary, registrant-only contacts, DNS tools on and off, test and live.
// Registrar- and client-supplied text is rendered escaped. With --emit, prints the rendered pages as JSON for
// tests/Enom/client-script.cjs instead of the summary line.
$enomTestLabel = 'client-views';
$emit = in_array('--emit', $argv ?? [], true);
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Models\Invoice;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
require_once dirname(__DIR__).'/enom-cart-order.php';
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;

function text(string $html): string { return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('#<(style|script)\b.*?</\1>#si', '', $html))))); }
function ok(string $uri, $user): string {
    $response = enomHttp($user, 'GET', $uri);
    verify($response->getStatusCode() === 200, 'GET '.$uri.' was '.$response->getStatusCode().' '.($response->exception?->getMessage() ?? ''));
    return $response->getContent();
}
/** Unique ids, resolvable ARIA references and labelled form controls. */
function accessible(string $html, string $name): void {
    $xpath = enomDocument($html);
    $ids = array_map(fn ($node) => $node->getAttribute('id'), iterator_to_array($xpath->query('//*[contains(@class, "enom")]//*[@id] | //*[@id="enom-confirm"]')));
    verify(count($ids) === count(array_unique($ids)), $name.' repeats an id: '.json_encode(array_diff_assoc($ids, array_unique($ids))));
    foreach ($xpath->query('//*[contains(concat(" ", @class, " "), " enom ")]//*[@aria-describedby or @aria-labelledby]') as $node) {
        foreach (['aria-describedby', 'aria-labelledby'] as $attribute) {
            foreach (preg_split('/\s+/', trim($node->getAttribute($attribute))) ?: [] as $id) {
                verify($id === '' || $xpath->query('//*[@id="'.$id.'"]')->length === 1, $name.' references a missing id '.$id);
            }
        }
    }
    foreach ($xpath->query('//*[contains(concat(" ", @class, " "), " enom ")]//input[not(@type="hidden")][not(@type="checkbox")][not(@type="radio")] | //*[contains(concat(" ", @class, " "), " enom ")]//select | //*[contains(concat(" ", @class, " "), " enom ")]//textarea') as $field) {
        $id = $field->getAttribute('id');
        $labelled = ($id !== '' && $xpath->query('//label[@for="'.$id.'"]')->length > 0) || $field->hasAttribute('aria-label') || $field->hasAttribute('aria-labelledby');
        verify($labelled, $name.' has an unlabelled '.$field->nodeName.' '.$field->getAttribute('name'));
    }
}

// ---------------------------------------------------------------- every Blade file compiles
$compiler = $app['blade.compiler'];
$views = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extensionRoot.'/resources/views', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!str_ends_with($file->getFilename(), '.blade.php')) { continue; }
    $compiled = $compiler->compileString(file_get_contents($file->getPathname()));
    $lint = tempnam($testRoot, 'blade'); file_put_contents($lint, $compiled);
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($lint).' 2>&1', $output, $status);
    verify($status === 0, 'Compiled view does not parse: '.$file->getFilename().' '.implode(' ', $output));
    unlink($lint); $views++;
}
verify($views >= 18, 'Fewer client views than expected were compiled: '.$views);

$owner = client('client.owner@example.com');
$pages = [];

// ---------------------------------------------------------------- list, search, transfer and help
$empty = ok('/domains', $owner);
verify(str_contains(text($empty), 'Find your domain name') && enomDocument($empty)->query('//form[@action="http://enom-tests.invalid/domains/register"]')->length === 1, 'Empty list does not offer a search.');
accessible($empty, 'Empty list');
$search = ok('/domains/register?name=hello', $owner);
accessible($search, 'Search');
verify(str_contains(text($search), 'Test mode: Orders and changes use the registrar\'s test system. Nothing is registered for real.'), 'Test environment banner missing on search.');
$pages['search'] = $search;
$transfer = ok('/domains/transfer', $owner);
verify(str_contains(text($transfer), 'Transfer prices') && str_contains(text($transfer), '.com $13.00') && !str_contains(text($transfer), '.org'), 'Transfer page does not list enabled endings with transfer prices.');
accessible($transfer, 'Transfer');
verify(str_contains(text(ok('/domains/help', $owner)), 'domain'), 'Help page is empty.');

// ---------------------------------------------------------------- pending registration awaiting payment
$initial = enomCartOrder($owner, 'hello.com');
$pending = $initial['domain'];
$invoice = $initial['invoice'];
$pendingPage = ok('/domains/'.$pending->id, $owner);
verify(str_contains(text($pendingPage), 'Pay the invoice to register hello.com') && enomDocument($pendingPage)->query('//a[@href="http://enom-tests.invalid/invoices/'.$invoice->id.'"][contains(@class, "enom-btn--primary")]')->length === 1, 'Pending registration does not lead to its invoice.');
verify(enomDocument($pendingPage)->query('//*[@data-enom-tabs]')->length === 0 && !str_contains($pendingPage, '/manage/'), 'Pending registration shows management tabs or forms.');
accessible($pendingPage, 'Pending registration');
$list = ok('/domains', $owner);
verify(str_contains(text($list), 'Awaiting payment') && enomDocument($list)->query('//a[@href="http://enom-tests.invalid/invoices/'.$invoice->id.'"]')->length === 1, 'List does not show the unpaid registration.');
accessible($list, 'List');

// ---------------------------------------------------------------- active domain: DNS tools off, then on
$active = clientDomain($owner, ['domain' => 'active-site.com'], ['hosts' => [['name' => '@', 'type' => 'A', 'address' => '192.0.2.10', 'mx_pref' => 10]], 'srv' => []]);
Operation::create(['domain_id' => $active->id, 'service_id' => $active->service_id, 'operation_key' => 'view-nameservers', 'action' => 'nameservers', 'state' => 'succeeded', 'currency' => 'USD', 'term' => 1, 'amount' => '0.00', 'payload' => [], 'created_at' => '2026-10-13 15:45:00']);
$activePage = ok('/domains/'.$active->id, $owner);
$tabs = array_map(fn ($node) => $node->getAttribute('data-enom-tab'), iterator_to_array(enomDocument($activePage)->query('//*[@data-enom-tab]')));
verify($tabs === ['overview', 'nameservers', 'contacts', 'transfer', 'privacy', 'activity'], 'Active domain tabs with DNS tools off: '.json_encode($tabs));
verify(!str_contains($activePage, '/manage/hosts') && !str_contains($activePage, '/manage/dnssec_add') && !str_contains($activePage, 'id="dns"'), 'DNS tools appear while turned off.');
verify(str_contains(text($activePage), 'Renewal price $14.00/yr') && str_contains(text($activePage), 'Expires Dec 1, 2026') && enomDocument($activePage)->query('//time[@datetime="2026-12-01"][not(@data-enom-time)]')->length >= 1,
    'Active overview lacks its price or a plain calendar expiry date.');
verify(enomDocument($activePage)->query('//time[@data-enom-time][@datetime="2026-10-14T08:30:00+00:00"]')->length === 1, 'Last check is not a local-time timestamp.');
verify(enomDocument($activePage)->query('//form[@data-confirm-title="Turn off the transfer lock?"]')->length === 1 && enomDocument($activePage)->query('//form[@data-confirm-title="Turn off auto-renew?"]')->length === 1, 'Risky actions lack confirmation.');
verify(str_contains(text($activePage), 'We email it to the domain owner at c••••••@example.com'), 'Transfer code text lacks the masked registrant email.');
accessible($activePage, 'Active domain');
$pages['show'] = $activePage;
serverSetting('dns_tools', '1');
$dnsPage = ok('/domains/'.$active->id, $owner);
verify(str_contains($dnsPage, 'id="dns"') && str_contains($dnsPage, '/domains/'.$active->id.'/manage/hosts') && str_contains($dnsPage, '/domains/'.$active->id.'/manage/dnssec_add'), 'DNS tools missing while turned on.');
accessible($dnsPage, 'Active domain with DNS tools');
serverSetting('dns_tools', '0');

// ---------------------------------------------------------------- transfer-code cooldown
Operation::create(['domain_id' => $active->id, 'service_id' => $active->service_id, 'operation_key' => 'view-epp', 'action' => 'epp_email', 'state' => 'succeeded', 'currency' => 'USD', 'term' => 1, 'amount' => '0.00', 'payload' => [], 'created_at' => now()->subMinutes(3)]);
$cooldown = ok('/domains/'.$active->id, $owner);
verify(enomDocument($cooldown)->query('//button[@disabled][contains(., "Email transfer code")]')->length === 1 && str_contains(text($cooldown), 'You can ask again after'), 'Cooldown does not disable the transfer-code button.');
$pages['cooldown'] = $cooldown;

// ---------------------------------------------------------------- complimentary domain and complimentary client
$gift = clientDomain($owner, ['domain' => 'gift-site.com']);
Pricing::setDomainMode($gift, 'complimentary');
$giftPage = ok('/domains/'.$gift->id, $owner);
verify(str_contains(text($giftPage), 'Renewal price Complimentary') && str_contains(text($giftPage), 'Renews at no cost.') && !str_contains($giftPage, '/domains/'.$gift->id.'/renew"'), 'Complimentary domain page shows a paid renewal.');
verify(str_contains($giftPage, '/domains/'.$gift->id.'/privacy/purchase'), 'Complimentary domain hides the paid ID Protect purchase.');
// ---------------------------------------------------------------- registrant-only contacts, differing contacts
$solo = clientDomain($owner, ['domain' => 'solo-contact.com'], ['contact_roles' => ['registrant']]);
$soloPage = ok('/domains/'.$solo->id, $owner);
$contactForms = enomDocument($soloPage)->query('//form[@action="http://enom-tests.invalid/domains/'.$solo->id.'/manage/contacts"]');
verify($contactForms->length === 1 && str_contains(text($soloPage), 'Registrant contact') && !str_contains(text($soloPage), 'Other contacts')
    && enomFormFields($soloPage, '/domains/'.$solo->id.'/manage/contacts')['apply_all'] === '1', 'Registrant-only domain shows other roles or does not update all roles.');
$differ = clientDomain($owner, ['domain' => 'differ-contact.com']);
$snapshot = $differ->snapshot; $snapshot['contacts']['tech']['first_name'] = 'Theo'; $differ->update(['snapshot' => $snapshot]);
$differPage = ok('/domains/'.$differ->id, $owner);
verify(str_contains(text($differPage), 'Some contacts differ from the owner.') && enomFormFields($differPage, '/domains/'.$differ->id.'/manage/contacts')['apply_all'] === '0'
    && str_contains(text($differPage), 'Technical · Theo Owner'), 'Differing contacts default to overwriting every role.');

// ---------------------------------------------------------------- native service page block
// The native service page offers a "Manage domain" button; the extension's service block renders through getView().
$servicePage = ok('/services/'.$active->service_id, $owner);
verify(str_contains($servicePage, 'http://enom-tests.invalid/domains/'.$active->id), 'Native service page does not link to the domain page.');
Illuminate\Support\Facades\Auth::setUser($owner);
$block = $extension->getView($active->service)->render();
verify(str_contains(text($block), 'active-site.com Active Expires Dec 1, 2026 Auto-renew on') && str_contains($block, 'href="http://enom-tests.invalid/domains/'.$active->id.'"'), 'Service block lacks status, expiry or the domain link.');
Illuminate\Support\Facades\Auth::setUser(client('other-view@example.com'));
try { $extension->getView($active->service); verify(false, 'Another client rendered this service block.'); }
catch (Illuminate\Database\Eloquent\ModelNotFoundException|Symfony\Component\HttpKernel\Exception\HttpException) { verify(true, ''); }
$app['auth']->forgetGuards();

// ---------------------------------------------------------------- test and live banners
$liveCheck = fn (string $html) => str_contains(text($html), 'Test mode:');
verify($liveCheck(ok('/domains', $owner)) && $liveCheck(ok('/domains/'.$active->id, $owner)) && $liveCheck($search), 'Test banner missing in the test environment.');
serverSetting('environment', 'live');
RuntimeGuard::confirmLive($server->id);
verify(!$liveCheck(ok('/domains', $owner)) && !$liveCheck(ok('/domains/'.$active->id, $owner)) && !$liveCheck(ok('/domains/register?name=hello', $owner)) && !$liveCheck(ok('/domains/transfer', $owner)), 'Test banner shown for a live server.');
serverSetting('environment', 'test');

// ---------------------------------------------------------------- registrar- and client-supplied text is escaped
$attack = '<script>alert(1)</script>';
$hostile = clientDomain($owner, ['domain' => 'hostile.com'], [
    'contacts' => ['registrant' => ['first_name' => $attack, 'last_name' => '"><img src=x onerror=alert(2)>', 'organization_name' => "</textarea><script>alert(3)</script>", 'email_address' => 'x"onmouseover="alert(4)@example.com',
        'address1' => '1 Main', 'city' => 'Seattle', 'postal_code' => '98101', 'country' => 'US', 'phone' => '+1.2065550100'],
        'tech' => ['first_name' => $attack, 'last_name' => 'T']],
    'nameservers' => ['ns1.example.com', '<img src=x onerror=alert(5)>'],
    'hosts' => [['name' => 'www', 'type' => 'TXT', 'address' => '"><script>alert(6)</script>', 'mx_pref' => 10]],
]);
Illuminate\Support\Facades\DB::table('enom_domains')->where('id', $hostile->id)->update(['domain' => '<script>alert(7)</script>.com']);
$owner->update(['first_name' => '<script>alert(8)</script>']);
serverSetting('dns_tools', '1');
$hostilePage = ok('/domains/'.$hostile->id, $owner);
$hostileList = ok('/domains?q='.rawurlencode('<script>alert(9)</script>'), $owner);
$hostileAll = ok('/domains', $owner);
serverSetting('dns_tools', '0');
foreach (['domain page' => $hostilePage, 'list search' => $hostileList, 'list' => $hostileAll] as $name => $html) {
    // Raw markup only; escaped text such as &lt;img src=x onerror=…&gt; is expected. Attributes are checked on the DOM below.
    verify(!preg_match('/<script>alert\(|<img src=x|"onmouseover="/i', $html), 'Unescaped hostile text on the '.$name.'.');
    $dom = enomDocument($html);
    verify($dom->query('//script[contains(., "alert(")]')->length === 0 && $dom->query('//img[@src="x"]')->length === 0 && $dom->query('//*[@onerror or @onmouseover]')->length === 0, 'Hostile markup became elements on the '.$name.'.');
}
verify(str_contains($hostilePage, '&lt;script&gt;alert(7)&lt;/script&gt;.com') && str_contains($hostileList, '&lt;script&gt;alert(9)&lt;/script&gt;'), 'Hostile text was dropped instead of shown escaped.');
$document = enomDocument($hostilePage);
verify($document->query('//script[contains(., "alert(")]')->length === 0 && $document->query('//img[@src="x"]')->length === 0 && $document->query('//*[@onerror or @onmouseover]')->length === 0, 'Hostile markup became elements.');
verify(enomFormFields($hostilePage, '/domains/'.$hostile->id.'/manage/contacts')['contact']['first_name'] === $attack, 'Hostile contact value was not kept as plain text in the form.');

if ($emit) {
    // Pages for the jsdom checks. A search with 13 enabled endings renders the 12-ending cap.
    for ($i = 1; $i <= 11; $i++) { enomTestTld(['server_id' => $server->id, 'tld' => 'e'.$i, 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '20.00', 'renew_price' => '21.00', 'transfer_price' => '20.00', 'sort_order' => 10 + $i]); }
    $pages['search'] = ok('/domains/register?name=hello', $owner);
    fwrite(STDOUT, json_encode($pages, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
} else {
    echo 'Client views: '.$checks.' passed; '.$views.' views compiled; real routes, SQLite in memory.'.PHP_EOL;
}
$successful = true;

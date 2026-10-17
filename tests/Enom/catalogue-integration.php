<?php
// Catalogue import, cost sync, enabling rules and the hidden native product, against a disposable native app.
// Provider responses are the sanitized TEST API captures in tests/fixtures/enom-test-api, served by an injected transport.
$enomTestLabel = 'catalogue';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

$fixtures = dirname(__DIR__).'/fixtures/enom-test-api/';
$pricingBody = file_get_contents($fixtures.'enom-pe-getdomainpricing.xml');
$failCommand = null;
$commands = [];
$wrongDetails = false;
$attributesOverride = null;
$detailsOverride = null;
$transport = function (string $command, array $params) use (&$pricingBody, &$failCommand, &$commands, &$wrongDetails, &$attributesOverride, &$detailsOverride, $fixtures): string {
    $commands[] = $command.(isset($params['TLD']) ? ':'.$params['TLD'] : '');
    if ($failCommand === $command) { return enomXml($command, '').'<broken'; }
    if ($command === 'GetExtAttributes' && $attributesOverride !== null) { return $attributesOverride; }
    if ($command === 'GetTLDDetails' && $detailsOverride !== null) { return $detailsOverride; }
    return match ($command) {
        'GetTLDList' => file_get_contents($fixtures.'enom-gettldlist.xml'),
        'PE_GetDomainPricing' => $pricingBody,
        // Captures exist for .com and .ai; other endings reuse the .com one-year shape under their own name (synthetic).
        // $wrongDetails serves the .com capture unchanged, to test that an answer about another ending is refused.
        'GetTLDDetails' => $params['TLD'] === 'ai' ? file_get_contents($fixtures.'enom-gettlddetails-ai.xml')
            : str_replace('<TLD>com</TLD>', '<TLD>'.($wrongDetails ? 'com' : $params['TLD']).'</TLD>', file_get_contents($fixtures.'enom-gettlddetails-com.xml')),
        'GetExtAttributes' => file_get_contents($fixtures.($params['TLD'] === 'us' ? 'enom-getextattributes-us.xml' : 'enom-getextattributes-com.xml')),
        default => throw new RuntimeException('Unexpected catalogue command '.$command),
    };
};
$guardCalls = [];
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport, null, true)),
    function (int $serverId, bool $readOnly) use (&$guardCalls) { $guardCalls[] = [$serverId, $readOnly]; return ['uid' => 'offline-account', 'token' => 'offline-token']; });
$app->instance(DomainOperations::class, $operations);
$catalogue = new Catalogue($operations);
$server = Server::create(['name' => 'Catalogue Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$second = Server::create(['name' => 'Second Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);

// Ordering is unavailable until an ending is enabled with all three prices.
refuses(fn () => $catalogue->resolve('example.com'), 'not available yet', 'Empty catalogue accepted an order.');

// Import adds every ending, disabled, with the default 30% markup, then reads costs (read-only requests only).
$imported = $catalogue->import($server->id);
verify($imported === ['added' => 9, 'total' => 9, 'costs' => 8], 'Import counts differ: '.json_encode($imported));
verify($commands === ['GetTLDList', 'PE_GetDomainPricing'] && array_unique(array_column($guardCalls, 1)) === [true], 'Import sent unexpected or non-read-only requests.');
verify(Tld::where('server_id', $server->id)->where('enabled', true)->count() === 0, 'Import enabled an ending.');
$com = Tld::where('server_id', $server->id)->where('tld', 'com')->sole();
verify($com->pricing_mode === 'markup_percent' && $com->markup === '30.00' && $com->sort_order === 1 && Tld::where('tld', 'co.uk')->value('sort_order') === 100, 'Import defaults or sort order differ.');
verify($com->cost('register') === '8.95' && $com->cost('transfer') === '9.50' && $com->price('register') === '11.64' && $com->price('transfer') === '12.35', 'Imported .com costs or markup prices differ.');
$io = Tld::where('server_id', $server->id)->where('tld', 'io')->sole();
verify($io->cost('transfer') === null && $io->price('transfer') === null && $io->price('register') === '51.35', 'Disabled .io transfer received a cost or price.');
$ai = Tld::where('server_id', $server->id)->where('tld', 'ai')->sole();
verify($ai->costs_synced_at === null && $ai->cost('register') === null, '.ai (absent from Enom pricing) received a cost.');
$commands = [];
verify($catalogue->import($server->id)['added'] === 0 && Tld::where('server_id', $server->id)->count() === 9, 'Re-import duplicated endings.');

// Sync: endings missing from the response keep their previous cost and sync time; markup prices follow cost; fixed prices never move.
Tld::where('server_id', $server->id)->where('tld', 'org')->update(['pricing_mode' => 'fixed', 'register_price' => '20.00', 'renew_price' => '21.00', 'transfer_price' => '22.00']);
Tld::where('server_id', $server->id)->where('tld', 'net')->update(['pricing_mode' => 'markup_amount', 'markup' => '5.00']);
$comSyncedAt = $com->fresh()->costs_synced_at;
Carbon\Carbon::setTestNow(now()->addHour());
$pricingBody = preg_replace('#<product>\s*<tld>com</tld>.*?</product>#s', '', $pricingBody, 1, $removed);
$pricingBody = str_replace('<resellerpricereg>8.95</resellerpricereg>', '<resellerpricereg>9.95</resellerpricereg>', $pricingBody); // .org registration cost rises
verify($removed === 1, 'Fixture edit did not remove .com.');
verify($catalogue->syncCosts($server->id) === 7, 'Sync did not report the seven endings present in the response.');
$com = $com->fresh();
verify($com->cost('register') === '8.95' && $com->cost('renew') === '8.95' && $com->cost('transfer') === '9.50' && $com->price('register') === '11.64'
    && $com->costs_synced_at?->equalTo($comSyncedAt), 'Ending missing from the cost response lost or changed its previous cost.');
$org = Tld::where('server_id', $server->id)->where('tld', 'org')->sole();
verify($org->cost('register') === '9.95' && $org->price('register') === '20.00' && $org->price('renew') === '21.00' && $org->price('transfer') === '22.00', 'Sync changed fixed prices or missed a new cost.');
$net = Tld::where('server_id', $server->id)->where('tld', 'net')->sole();
verify($net->price('register') === '20.55' && $net->price('renew') === '13.95' && $net->price('transfer') === '13.95', 'Amount markup not recomputed from cost.');
verify(Tld::where('tld', 'ai')->value('cost_register') === null, 'Missing ending gained a cost.');
Carbon\Carbon::setTestNow();
// A failed or empty response changes nothing.
$before = tableRows(['enom_tlds']);
$failCommand = 'PE_GetDomainPricing';
refuses(fn () => $catalogue->syncCosts($server->id), 'did not return domain costs', 'Unknown pricing response was accepted.');
$failCommand = null;
$saved = $pricingBody;
$pricingBody = enomXml('PE_GetDomainPricing', '<pricestructure><count>0</count></pricestructure>');
refuses(fn () => $catalogue->syncCosts($server->id), 'no domain costs', 'Empty pricing response was accepted.');
$pricingBody = $saved;
verify(tableRows(['enom_tlds']) == $before, 'Refused sync changed the catalogue.');

// Enabling rules. Every refusal leaves the ending disabled and creates no product.
$io = $io->fresh();
refuses(fn () => $catalogue->enable($io), 'Set registration, renewal and transfer prices', '.io without a transfer price was enabled.', [InvalidArgumentException::class]);
$us = Tld::where('server_id', $server->id)->where('tld', 'us')->sole();
// 0.4: an ending whose registry fields the order form can collect (.us nexus, purpose, country) is refused only for an unreadable schema.
$attributesBody = null;
$commands = [];
$us->fill(['pricing_mode' => 'fixed', 'register_price' => '9.00', 'renew_price' => '10.00', 'transfer_price' => '9.50'])->save();
$failCommand = 'GetExtAttributes';
refuses(fn () => $catalogue->enable($us->fresh()), 'Could not check .us registry requirements', 'Unknown attribute read enabled .us.', [RuntimeException::class]);
$failCommand = null;
verify($commands === ['GetTLDDetails:us', 'GetExtAttributes:us'], 'Enable did not check terms then registry attributes.');
$ai = $ai->fresh(); $ai->fill(['pricing_mode' => 'fixed', 'register_price' => '90.00', 'renew_price' => '90.00', 'transfer_price' => '90.00'])->save();
$failCommand = 'GetExtAttributes';
refuses(fn () => $catalogue->enable($org->fresh()), 'Could not check .org registry requirements', 'Unknown attribute read enabled an ending.', [RuntimeException::class]);
$failCommand = 'GetTLDDetails';
refuses(fn () => $catalogue->enable($org->fresh()), 'Could not read .org registration terms', 'Failed term read enabled an ending.', [RuntimeException::class]);
$failCommand = null;
$wrongDetails = true;
refuses(fn () => $catalogue->enable($org->fresh()), 'Could not read .org registration terms', 'Term details for another ending enabled .org.', [RuntimeException::class]);
$wrongDetails = false;
verify(Tld::where('enabled', true)->count() === 0 && Product::count() === 0, 'A refused enable changed state or created a product.');
$enableTransaction = null;
Tld::saving(function (Tld $ending) use ($com, &$enableTransaction): void {
    if ($ending->id === $com->id && $ending->enabled) { $enableTransaction = Illuminate\Support\Facades\DB::transactionLevel(); }
});
$catalogue->enable($com);
verify($enableTransaction > 0, 'Ending enable write ran outside its transaction.');
verify($com->fresh()->enabled && Product::where('server_id', $server->id)->count() === 1, 'Valid .com was not enabled with its product.');
verify($com->fresh()->min_years === 1 && $com->fresh()->max_years === 10 && $com->fresh()->registry_fields === [] && $catalogue->attributesFor($com->fresh()) === [], '.com terms or empty registry schema not stored.');
// .ai needs at least two years: it is enabled with a 2-10 term range (previously refused).
$catalogue->enable($ai->fresh());
verify($ai->fresh()->enabled && $ai->fresh()->min_years === 2 && $ai->fresh()->max_years === 10 && $ai->fresh()->terms() === range(2, 10), '.ai was not enabled with its 2-10 year range.');
verify($ai->fresh()->registrationTotal(1) === null && $ai->fresh()->registrationTotal(2) === '180.00' && $ai->fresh()->registrationTotal(10) === '900.00', '.ai totals ignore its minimum.');
// .us is enabled with its normalized schema stored for the order form.
$catalogue->enable($us->fresh());
$usSchema = $catalogue->attributesFor($us->fresh());
verify($us->fresh()->enabled && array_column($usSchema, 'name') === ['us_nexus', 'global_cc_us', 'us_purpose'] && $usSchema[1]['child'] === true, '.us was not enabled with its registry schema.');
// Public prices: pre-tax catalogue prices and term ranges of orderable endings only.
$public = collect($catalogue->publicPrices())->keyBy('tld');
verify($public['ai'] === ['tld' => 'ai', 'register' => '90.00', 'renew' => '90.00', 'transfer' => '90.00', 'min_years' => 2, 'max_years' => 10]
    && $public['us']['register'] === '9.00' && !$public->has('io') && !$public->has('net'), 'Public price list differs: '.json_encode($public));
// A malformed schema or a field the form can't collect refuses the ending.
foreach (['<Attributes><Attribute><Name>Bad Name</Name><Required>1</Required></Attribute></Attributes>', '<Attributes><Attribute><Name>opaque_id</Name><Required>1</Required><UserDefined>False</UserDefined></Attribute></Attributes>'] as $body) {
    $odd = Tld::create(['server_id' => $server->id, 'tld' => 'xyz'.strlen($body), 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '5.00', 'renew_price' => '5.00', 'transfer_price' => '5.00']);
    $attributesOverride = enomXml('GetExtAttributes', $body);
    refuses(fn () => $catalogue->enable($odd), 'can\'t collect yet', 'Unsupported registry schema was enabled.', [InvalidArgumentException::class]);
    verify(!$odd->fresh()->enabled && $odd->fresh()->registry_fields === null, 'Refused schema was stored.');
    $attributesOverride = null;
    $odd->delete();
}
// Over-long minimum terms are refused.
$detailsOverride = str_replace(['<Minimum>1</Minimum>', '<TLD>com</TLD>'], ['<Minimum>11</Minimum>', '<TLD>longterm</TLD>'], file_get_contents($fixtures.'enom-gettlddetails-com.xml'));
$longTerm = Tld::create(['server_id' => $server->id, 'tld' => 'longterm', 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '5.00', 'renew_price' => '5.00', 'transfer_price' => '5.00']);
refuses(fn () => $catalogue->enable($longTerm), 'longer than 10 years', 'A ten-plus year minimum was enabled.', [InvalidArgumentException::class]);
verify(!$longTerm->fresh()->enabled, 'Refused long-term ending was enabled.');
$longTerm->delete();
$detailsOverride = null;
Tld::whereKey($ai->id)->update(['enabled' => false]); Tld::whereKey($us->id)->update(['enabled' => false]);
// The same ending cannot be sold through a second server.
$duplicate = Tld::create(['server_id' => $second->id, 'tld' => 'com', 'enabled' => false, 'pricing_mode' => 'fixed', 'register_price' => '10.00', 'renew_price' => '10.00', 'transfer_price' => '10.00']);
$commands = [];
refuses(fn () => $catalogue->enable($duplicate), 'already sold through another Enom server', 'Second server enabled an ending already for sale.', [InvalidArgumentException::class]);
verify(!$duplicate->fresh()->enabled && $commands === [] && Product::where('server_id', $second->id)->doesntExist(), 'Duplicate refusal contacted Enom or changed state.');
$catalogue->enable($org->fresh());

// Hidden native product: one per server in a Domains category, idempotent, and reused by orders.
$product = $catalogue->ensureProduct($server->id);
verify($catalogue->ensureProduct($server->id)->id === $product->id && Product::where('server_id', $server->id)->count() === 1, 'ensureProduct was not idempotent.');
$plan = $product->plans()->sole();
$category = Category::where('slug', 'domains')->sole();
verify($product->hidden && $product->category_id === $category->id && $product->slug === 'domain-name' && $product->settings->firstWhere('key', Catalogue::PRODUCT_SETTING)?->value === '1'
    && $plan->type === 'recurring' && $plan->billing_unit === 'year' && (int) $plan->billing_period === 1 && $plan->prices()->where('currency_code', 'USD')->exists(), 'Hidden product shape differs.');
$secondProduct = $catalogue->ensureProduct($second->id);
verify($secondProduct->id !== $product->id && $secondProduct->slug === 'domain-name-'.$second->id && $secondProduct->category_id === $category->id
    && Category::where('slug', 'domains')->count() === 1 && $catalogue->ensureProduct($second->id)->id === $secondProduct->id, 'Second server product or shared category differs.');
// A pre-existing product without the catalogue marker is never adopted.
$legacy = Product::create(['name' => 'Legacy .com', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true, 'slug' => 'legacy-com']);
verify($catalogue->ensureProduct($server->id)->id === $product->id, 'Unmarked product was adopted as the catalogue product.');

// Orderable endings: enabled with all three prices, from Enom servers only.
verify($catalogue->orderable()->pluck('tld')->all() === ['com', 'org'], 'Orderable endings differ: '.json_encode($catalogue->orderable()->pluck('tld')->all()));
$resolved = $catalogue->resolve('Example.COM');
verify($resolved['domain'] === 'example.com' && $resolved['sld'] === 'example' && $resolved['tld']->id === $com->id, 'Resolve did not canonicalize to the enabled ending.');
foreach (['example.net', 'example.io', 'example.co.uk'] as $outside) { refuses(fn () => $catalogue->resolve($outside), '', 'Disabled ending '.$outside.' resolved.', [InvalidArgumentException::class, RuntimeException::class]); }
refuses(fn () => $catalogue->resolve('xn--bcher-kva.com'), 'International', 'IDN resolved.', [InvalidArgumentException::class]);
Server::whereKey($server->id)->update(['extension' => 'Other']);
verify($catalogue->orderable()->isEmpty(), 'Endings of a non-Enom server stayed orderable.');
Server::whereKey($server->id)->update(['extension' => 'Enom']);
// Disabling stops new orders, but existing registrations keep their ending addressable for renewal and management.
Tld::whereKey($com->id)->update(['enabled' => false]);
Domain::create(['service_id' => 999, 'user_id' => 1, 'server_id' => $server->id, 'domain' => 'kept.biz', 'sld' => 'kept', 'tld' => 'biz', 'status' => 'active', 'capabilities' => []]);
verify(!in_array('com', $catalogue->orderable()->pluck('tld')->all(), true) && in_array('com', $catalogue->suffixes($server->id), true)
    && in_array('biz', $catalogue->suffixes($server->id), true), 'Disabled ending or existing registration ending lost its addressability.');

echo 'Catalogue integration: '.$checks.' passed; sanitized TEST API captures via injected transport; SQLite in memory.'.PHP_EOL;
$successful = true;

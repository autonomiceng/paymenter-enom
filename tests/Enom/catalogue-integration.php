<?php
// Native products, exact-term costs and publication against a disposable database.
$enomTestLabel = 'catalogue';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\CatalogueRuntime;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\PricingRule;
use Paymenter\Extensions\Servers\Enom\Services\TransferOrders;

$commands = [];
$missingTerms = [];
$costOverride = [];
$failAll = false;
$detailsOverride = null;
$attributesOverride = null;
$transport = function (string $command, array $params) use (&$commands, &$missingTerms, &$costOverride, &$failAll, &$detailsOverride, &$attributesOverride): string {
    verify(DB::transactionLevel() === 0, 'Provider read ran while holding catalogue locks.');
    $commands[] = [$command, $params];
    if ($command === 'GetTLDList') { return file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/enom-gettldlist.xml'); }
    if ($command === 'GetTLDDetails') {
        if ($detailsOverride !== null) { return $detailsOverride; }
        return str_replace(['<TLD>com</TLD>', '<Minimum>1</Minimum>'], ['<TLD>'.$params['TLD'].'</TLD>', '<Minimum>'.($params['TLD'] === 'ai' ? 2 : 1).'</Minimum>'], file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/enom-gettlddetails-com.xml'));
    }
    if ($command === 'GetExtAttributes' && $attributesOverride !== null) { return $attributesOverride; }
    if ($command === 'GetExtAttributes') { return file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/enom-getextattributes-'.($params['TLD'] === 'us' ? 'us' : 'com').'.xml'); }
    if ($command !== 'PE_GetProductPrice') { throw new RuntimeException('Unexpected command.'); }
    $key = $params['TLD'].':'.$params['ProductType'].':'.$params['Years'];
    if ($failAll || in_array($key, $missingTerms, true)) { return enomXml($command, '<productprice><productenabled>False</productenabled></productprice>'); }
    $cost = $costOverride[$key] ?? ($params['TLD'] === 'net' && $params['ProductType'] === 10 && $params['Years'] === 1 ? '15.55' : '8.95');
    return enomXml($command, '<productprice><productenabled>True</productenabled><price>'.$cost.'</price></productprice>');
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport, null, true)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token']);
$app->instance(DomainOperations::class, $operations);
$catalogue = new Catalogue($operations);
$app->instance(Catalogue::class, $catalogue);
$server = Server::create(['name' => 'Catalogue', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$second = Server::create(['name' => 'Second catalogue', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
CatalogueRuntime::saveDefaultRule($server->id, array_replace(PricingRule::DEFAULT, ['markup' => '0.00']));
$storedRule = $server->settings()->where('key', 'pricing_default_rule')->sole();
verify($storedRule->type === 'array' && !$storedRule->encrypted && is_array($storedRule->value), 'Default rule was not a native array Setting.');
$net = Tld::create(['server_id' => $server->id, 'tld' => 'net', 'min_years' => 1, 'max_years' => 10,
    'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => true]);
verify($catalogue->syncCosts($server->id) === 1, 'Sync count differs.');
verify(array_column($commands, 0) === array_fill(0, 12, 'PE_GetProductPrice'), 'Sync did not request all exact terms and two actions.');
$summary = $catalogue->sync($server->id);
verify($summary['tlds'] === 1 && $summary['changed'] === [], 'Unchanged sync reported cost changes.');
verify(Catalogue::syncMessage(['tlds' => 2, 'changed' => [], 'missing' => [], 'held' => []]) === ['Costs synced for 2 TLDs.', 'No costs changed. Nothing on sale is below cost.', false], 'Clean sync summary differs.');
verify(Catalogue::syncMessage(['tlds' => 5, 'changed' => ['.com', '.io'], 'missing' => ['.xyz'], 'held' => ['.io', '.net', '.org', '.dev']]) === ['Costs synced for 5 TLDs.',
    '2 TLDs had cost changes. Below cost, partly or fully off sale: .io, .net, .org and 1 more. No Enom price for some terms of .xyz. Those terms aren’t offered until a sync gets a price.', true], 'Guarded sync summary differs.');
verify(Catalogue::belowCostAlert('xyz', 'register', [7, 8, 9, 10], [1, 2, 3, 4, 5, 6])[0] === '.xyz registration for 7–10 years removed: price below cost'
    && Catalogue::belowCostAlert('xyz', 'register', [1], [])[0] === 'Registration for .xyz turned off: price below cost'
    && Catalogue::belowCostAlert('xyz', 'renew', [1], [1])[0] === '.xyz renews below cost', 'Margin alert titles differ.');
verify(array_column(array_column(array_slice($commands, 0, 10), 1), 'Years') === range(1, 10), 'Sync substituted a registration term.');
verify($net->fresh()->cost('register', 1) === '15.5500' && $net->fresh()->cost('register', 2) === '8.9500', 'Term costs were rounded or substituted.');
verify(Product::count() === 0, 'A disabled ending created products.');
$catalogue->enable($net->fresh());
$net->refresh();
$product = $catalogue->productFor($net, 'register');
$transfer = $catalogue->productFor($net, 'transfer');
verify($product && $transfer && $product->id !== $transfer->id && Product::count() === 2, 'Actions did not get separate products.');
verify($product->slug === 'net-registration' && $transfer->slug === 'net-transfer' && $product->hidden
    && $product->allow_quantity === 'separated' && $product->stock === null && (int) $product->per_user_limit === 0, 'Native product shape differs.');
verify(Category::where('slug', 'domains')->sole()->name === 'Domains' && $product->name === '.net registration', 'Names or category differ.');
verify($net->registrationTotal(2) === '17.90' && $net->registrationTotal(3) === '26.85' && $net->price('register') === '15.55', 'Retail borrowed another term or used renewal costs.');
verify($net->terms() === range(1, 10) && $net->orderable('register') && $net->orderable('transfer'), 'Available actions or terms differ.');
$plan = $product->plans()->where('billing_period', 2)->sole();
verify($catalogue->mapping($product, $plan)['years'] === 2 && $catalogue->mapping($product)['years'] === null
    && $plan->type === 'recurring' && $plan->billing_unit === 'year' && $plan->prices()->sole()->setup_fee == 0, 'Plan mapping or setup fee differs.');
$identities = [$product->id, $transfer->id, $product->plans()->pluck('id')->all()];
$catalogue->publish($net);
verify([$catalogue->productFor($net, 'register')->id, $catalogue->productFor($net, 'transfer')->id, $product->plans()->pluck('id')->all()] === $identities, 'Publication replaced IDs.');
verify($catalogue->billingPlan($product)->billing_period === 1, 'Service billing identity is not annual.');

// Failed term reads remove only that term and keep a purchasable default.
$missingTerms = ['net:10:1', 'net:10:3'];
$catalogue->syncCosts($server->id);
$net->refresh();
verify($net->registrationTotal(1) === null && $net->registrationTotal(3) === null && $net->registrationTotal(2) === '17.90', 'Failed sync retained stale sale prices.');
verify($net->terms() === [2, 4, 5, 6, 7, 8, 9, 10] && $product->plans()->first()->id === $plan->id, 'Unavailable plan remained the native default.');
verify($product->plans()->where('billing_period', 3)->sole()->prices()->count() === 0, 'Failed term still has a price row.');

// Below-cost registration terms are removed independently at four-place precision.
$missingTerms = [];
$net->fill(['use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '16.00', 'fixed_renew' => '17.00', 'fixed_transfer' => '18.00']);
$catalogue->publish($net);
$costOverride['net:10:7'] = '16.0001';
$catalogue->syncCosts($server->id);
$net->refresh();
verify($net->register_enabled && ($net->sale_holds['register'] ?? '') === 'Price is below cost. Not offered: 7 years.' && !in_array(7, $net->terms(), true) && $net->registrationTotal(2) === '32.00', 'Below-cost term affected available registration terms.');
verify($product->plans()->where('billing_period', 7)->sole()->prices()->count() === 0 && $net->price('transfer') === '18.00', 'Registration hold affected transfer or retained the below-cost term.');
$net->register_enabled = false;
$catalogue->publish($net);
$costOverride = [];
$catalogue->syncCosts($server->id);
verify(!$net->fresh()->register_enabled && $net->fresh()->registrationTotal(2) === null, 'Cost recovery re-enabled a staff-disabled action.');
$net->refresh()->fill(['register_enabled' => true, 'transfer_enabled' => false]);
$catalogue->publish($net);
verify($net->registrationTotal(2) === '32.00' && $net->price('transfer') === null && $transfer->plans()->sole()->prices()->count() === 0, 'Independent action disable did not remove transfer prices.');

// Renewal cost failures retain billing; an explicit disable still stops new sales.
$missingTerms = ['net:16:1'];
$catalogue->syncCosts($server->id);
$net->refresh();
verify($net->price('renew') === '17.00' && $net->orderable('register') && isset($net->sale_holds['renew']), 'Missing renewal cost did not retain billing and alert staff.');
$missingTerms = [];
$costOverride['net:16:1'] = '17.0001';
$catalogue->syncCosts($server->id);
verify($net->fresh()->renew_enabled && $net->fresh()->price('renew') === '17.00' && isset($net->fresh()->sale_holds['renew']), 'Renewal margin warning stopped billing.');
foreach ([1, 2] as $repeat) {
    $renewalSummary = $catalogue->sync($server->id);
    [$title, $body, $warn] = Catalogue::syncMessage($renewalSummary);
    verify($renewalSummary['held'] === [] && $renewalSummary['renewals_below_cost'] === ['.net'] && $warn
        && str_contains($body, 'Renewals below cost (still billed): .net.') && !str_contains($body, 'Nothing on sale is below cost.'), 'Repeat sync concealed below-cost renewals.');
    verify($net->fresh()->price('renew') === '17.00', 'Renewal warning stopped billing.');
}
$net->refresh()->fill(['renew_enabled' => false]);
$catalogue->publish($net);
verify(!$net->orderable() && $net->price('renew') === null, 'Explicit renewal disable left new sales available.');
verify($catalogue->sync($server->id)['renewals_below_cost'] === [], 'Disabled renewal reported as still billed.');
$costOverride = [];
$catalogue->syncCosts($server->id);
verify(!$net->fresh()->renew_enabled, 'Renewal recovery silently re-enabled renewal.');
$net->refresh()->fill(['renew_enabled' => true, 'use_default_rule' => true]);
$catalogue->publish($net);

// Rule changes publish immediately; overrides remain independent of the default.
CatalogueRuntime::saveDefaultRule($server->id, array_replace(PricingRule::DEFAULT, ['round_up' => 'ending_99']));
$net->refresh();
verify($net->registrationTotal(2) === '23.98' && $net->price('renew') === '11.99', 'Global rule or round-before-multiply differs.');
$net->fill(['use_default_rule' => false, 'pricing_mode' => 'markup_amount', 'markup' => '1.00', 'round_up' => 'half']);
$catalogue->publish($net);
verify($net->registrationTotal(2) === '20.00', 'Override markup/rounding was not published.');
CatalogueRuntime::saveDefaultRule($server->id, array_replace(PricingRule::DEFAULT, ['markup' => '90.00']));
verify($net->fresh()->registrationTotal(2) === '20.00', 'Default change overwrote a TLD override.');

// Registration-only endings need no transfer cost; two-year minimum keeps annual identity unpriced.
$ai = Tld::create(['server_id' => $server->id, 'tld' => 'ai', 'min_years' => 2, 'max_years' => 10,
    'register_enabled' => true, 'renew_enabled' => true]);
$catalogue->syncCosts($server->id);
$catalogue->enable($ai->fresh());
$ai->refresh();
$aiProduct = $catalogue->productFor($ai, 'register');
verify($ai->orderable('register') && !$ai->orderable('transfer') && $catalogue->productFor($ai, 'transfer') === null && $ai->cost('transfer') === null, 'Registration-only ending requires transfer pricing.');
$annual = $catalogue->billingPlan($aiProduct);
verify($annual->name === 'Managed annual renewal' && $annual->billing_period === 1 && $annual->prices()->count() === 0
    && $aiProduct->plans()->first()->billing_period === 2 && $ai->price('register') === null, 'Annual service identity became a one-year sale.');
verify($ai->registrationTotal(2) !== null && !array_key_exists('cost_register_terms', $ai->toArray()) && !array_key_exists('cost_renew', $ai->toArray()), 'Missing retail or serialized wholesale costs.');
$de = Tld::create(['server_id' => $server->id, 'tld' => 'de']);
refuses(fn () => $catalogue->enable($de), 'nameservers at purchase', '.de registration was enabled.');

// Ownership markers, native server and plan ownership all fail closed.
$native = Product::create(['category_id' => $product->category_id, 'name' => 'Hosting', 'slug' => 'hosting']);
verify($catalogue->mapping($native) === null, 'Unrelated hosting product was treated as registrar inventory.');
refuses(fn () => $catalogue->mapping($product, $annual), 'plan mapping', 'A different product plan was accepted.');
foreach (['enom_catalogue' => '0', 'enom_tld_id' => '999999', 'enom_action' => 'renew'] as $key => $bad) {
    $setting = $product->settings()->where('key', $key)->sole();
    $old = $setting->value;
    $setting->update(['value' => $bad]);
    refuses(fn () => $catalogue->mapping($product), 'product mapping', 'Corrupt product marker accepted.');
    $before = tableRows(['enom_tlds', 'plans', 'prices']);
    refuses(fn () => $catalogue->publish($net), '', 'Publication adopted corrupt product markers.');
    verify(tableRows(['enom_tlds', 'plans', 'prices']) === $before, 'Failed publication was not atomic.');
    $setting->update(['value' => $old]);
}
Server::whereKey($server->id)->update(['extension' => 'Other']);
refuses(fn () => $catalogue->mapping($product), 'product mapping', 'Changed server extension accepted.');
Server::whereKey($server->id)->update(['extension' => 'Enom']);
$duplicate = Tld::create(['server_id' => $second->id, 'tld' => 'net', 'enabled' => false]);
refuses(fn () => $catalogue->enable($duplicate), 'already sold', 'Second enabled seller accepted.');
verify(!$duplicate->fresh()->enabled, 'Second seller refusal changed state.');
$collision = Tld::create(['server_id' => $server->id, 'tld' => 'collision', 'enabled' => true]);
$unowned = Product::create(['category_id' => $product->category_id, 'name' => 'Existing', 'slug' => 'collision-registration']);
refuses(fn () => $catalogue->publish($collision), 'already in use', 'Unowned slug was adopted.');
verify($unowned->settings()->count() === 0, 'Unowned product was marked as extension-owned.');
$collision->delete();

// Generated product settings are repaired without replacing historical identities.
$product->update(['slug' => 'edited-registration', 'hidden' => false, 'allow_quantity' => 'combined', 'stock' => 1, 'per_user_limit' => 5]);
$catalogue->publish($net);
$product->refresh();
verify($product->slug === 'net-registration' && $product->hidden && $product->allow_quantity === 'separated'
    && $product->stock === null && (int) $product->per_user_limit === 0, 'Publication did not restore the owned product.');
$plan->update(['type' => 'free']);
refuses(fn () => $catalogue->mapping($product, $plan->fresh()), 'plan mapping', 'Free plan mapping accepted.');
$catalogue->publish($net);
verify($plan->fresh()->type === 'recurring', 'Publication left a free native sale plan.');

// Admission metadata cannot come from an unreadable or different ending response.
$odd = Tld::create(['server_id' => $server->id, 'tld' => 'odd']);
$detailsOverride = file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/enom-gettlddetails-com.xml');
refuses(fn () => $catalogue->enable($odd), 'registration terms', 'Details for another ending were accepted.');
$detailsOverride = null;
$attributesOverride = enomXml('GetExtAttributes', '<Attributes><Attribute><Name>Bad Name</Name><Required>1</Required></Attribute></Attributes>');
refuses(fn () => $catalogue->enable($odd), '', 'An unreadable registry schema was accepted.');
verify(!$odd->fresh()->enabled && $catalogue->productFor($odd, 'register') === null, 'Refused metadata created sale inventory.');
$attributesOverride = null;
$us = Tld::create(['server_id' => $server->id, 'tld' => 'us', 'register_enabled' => true, 'renew_enabled' => true]);
$catalogue->syncCosts($server->id);
$catalogue->enable($us->fresh());
verify(array_column($us->fresh()->registry_fields, 'name') === ['us_nexus', 'global_cc_us', 'us_purpose'], 'Supported registry metadata was not stored.');

// Import is idempotent and never grants a sale flag or adopts an existing product.
$importServer = Server::create(['name' => 'Import catalogue', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$imported = $catalogue->import($importServer->id);
verify($imported === ['added' => 9, 'total' => 9, 'costs' => 9], 'Catalogue import did not read every ending.');
verify(Tld::where('server_id', $importServer->id)->where('enabled', true)->count() === 0
    && Tld::where('server_id', $importServer->id)->where('register_enabled', true)->count() === 0
    && Product::where('server_id', $importServer->id)->count() === 0, 'Import enabled sales or created placeholder products.');
verify($catalogue->import($importServer->id)['added'] === 0 && Tld::where('server_id', $importServer->id)->count() === 9, 'Import duplicated ending identities.');

// A completely failed price read removes stale sales and can recover without enabling disabled actions.
$failAll = true;
$catalogue->syncCosts($server->id);
verify(!$net->fresh()->orderable() && $net->fresh()->cost('register', 2) === null && $net->fresh()->cost('renew') === null
    && $product->plans()->get()->sum(fn ($p) => $p->prices()->count()) === 0, 'Complete price failure retained stale sale data.');
$failAll = false;
$catalogue->syncCosts($server->id);
verify($net->fresh()->orderable('register') && !$net->fresh()->transfer_enabled, 'Cost recovery changed an explicitly disabled action.');

// SQLite records product-first acquisition and a single transaction for native and extension writes.
$queries = [];
DB::listen(function ($event) use (&$queries) { $queries[] = $event->sql; });
$atomic = [];
Tld::saving(function () use (&$atomic) { $atomic[] = DB::transactionLevel(); });
Plan::saving(function () use (&$atomic) { $atomic[] = DB::transactionLevel(); });
$catalogue->publish($net);
verify(str_starts_with($queries[0], 'select * from "products"') && str_starts_with($queries[1], 'select * from "enom_tlds"'), 'Publication does not acquire products before the TLD.');
verify($atomic !== [] && min($atomic) > 0, 'Publication wrote outside the transaction.');
$net->enabled = false;
$catalogue->publish($net);
verify(!$net->orderable() && $product->plans()->get()->sum(fn ($p) => $p->prices()->count()) === 0, 'Disabling the ending left purchasable plans.');
verify($catalogue->productFor($net, 'register')->id === $identities[0], 'Disabling removed historical product identity.');

echo 'Catalogue integration: '.$checks.' checks passed; native Paymenter, SQLite, injected registrar.'.PHP_EOL;
$successful = true;

<?php
require __DIR__.'/catalogue-integration.php';
$successful = false;
require dirname(__DIR__).'/enom-admin-panel.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Enom\Admin\Pages\EnomSetup;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\CatalogueRuntime;
use Paymenter\Extensions\Servers\Enom\Services\PricingRule;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;

$staffUser = function (string $name, array $permissions): User {
    $role = Role::create(['name' => $name, 'permissions' => $permissions]);
    return User::create(['email' => strtolower($name).'@example.com', 'password' => 'synthetic', 'first_name' => $name, 'last_name' => 'Staff',
        'role_id' => $role->id, 'email_verified_at' => now()]);
};
$manager = $staffUser('Manager', ['admin.enom.manage']);
$coster = $staffUser('Coster', ['admin.enom.manage', 'admin.enom.costs']);
$client = $staffUser('Client', []);
enomAdminPanel();
Auth::setUser($coster);
// The converter also publishes new mapped products before moving existing services to an annual plan.
$unmappedServer = App\Models\Server::create(['name' => 'Converted billing identity', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$unmapped = Tld::create(['server_id' => $unmappedServer->id, 'tld' => 'com', 'enabled' => true,
    'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => true, 'use_default_rule' => false,
    'pricing_mode' => 'markup_amount', 'markup' => '8.00', 'round_up' => 'none',
    'fixed_register' => null, 'fixed_renew' => null, 'fixed_transfer' => null, 'cost_register_terms' => null,
    'cost_renew' => null, 'cost_transfer' => null, 'renew_price' => '18.00', 'min_years' => 2, 'max_years' => 2]);
$catalogue->publish($unmapped);
$unmappedProduct = $catalogue->productFor($unmapped, 'register');
try { $unmappedAnnual = $catalogue->billingPlan($unmappedProduct); }
catch (Throwable) { verify(false, 'Converted publication did not create its annual billing identity.'); }
verify($unmappedAnnual->billing_period === 1 && $unmappedAnnual->type === 'recurring' && $unmappedAnnual->billing_unit === 'year'
    && $unmappedAnnual->prices()->count() === 0 && $unmappedProduct->plans()->count() === 2 && !$unmapped->fresh()->orderable(),
    'Converted billing identity is missing or offers an unknown-cost term.');
verify($catalogue->productFor($unmapped, 'transfer')->plans()->sole()->prices()->count() === 0,
    'Converted transfer did not get an unpriced annual identity.');
$catalogue->publish($unmapped->fresh());
verify($catalogue->billingPlan($unmappedProduct)->id === $unmappedAnnual->id, 'Converted republication replaced its billing identity.');
$unmapped->refresh()->update(['enabled' => false]);

// Converter-shaped markup rows have no exact-term registration costs or fixed rules.
// Existing native prices must survive every publication until a successful cost read.
foreach (['markup_amount', 'markup_percent'] as $convertedMode) {
    foreach ([[null, null], ['10.0000', null], [null, '10.0000'], ['10.0000', '10.0000']] as [$convertedRenewCost, $convertedTransferCost]) {
        $convertedServer = App\Models\Server::create(['name' => 'Converted catalogue', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
        $converted = Tld::create(['server_id' => $convertedServer->id, 'tld' => 'com', 'enabled' => true,
            'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => true, 'use_default_rule' => false,
            'pricing_mode' => $convertedMode, 'markup' => $convertedMode === 'markup_amount' ? '8.00' : '80.00', 'round_up' => 'none',
            'fixed_register' => null, 'fixed_renew' => null, 'fixed_transfer' => null, 'cost_register_terms' => null,
            'cost_renew' => $convertedRenewCost, 'cost_transfer' => $convertedTransferCost, 'renew_price' => '18.00',
            'costs_synced_at' => now()->subDay(), 'min_years' => 1, 'max_years' => 2, 'registry_fields' => []]);
        $convertedProducts = [];
        foreach (['register' => [1 => '20.00', 2 => '40.00'], 'transfer' => [1 => '22.00']] as $action => $termPrices) {
            $convertedProduct = App\Models\Product::create(['category_id' => $product->category_id, 'server_id' => $convertedServer->id,
                'name' => 'Converted '.$action, 'slug' => 'converted-'.$converted->id.'-'.$action, 'hidden' => true, 'allow_quantity' => 'separated']);
            foreach (['enom_catalogue' => '1', 'enom_tld_id' => (string) $converted->id, 'enom_action' => $action] as $key => $value) {
                $convertedProduct->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => false]);
            }
            foreach ($termPrices as $years => $amount) {
                $convertedPlan = $convertedProduct->plans()->create(['name' => $years.' years', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => $years]);
                $convertedPlan->prices()->create(['currency_code' => 'USD', 'price' => $amount, 'setup_fee' => '0.00']);
            }
            $convertedProducts[$action] = $convertedProduct;
        }
        $convertedService = App\Models\Service::withoutEvents(fn () => App\Models\Service::create([
            'user_id' => $client->id, 'product_id' => $convertedProducts['register']->id,
            'plan_id' => $convertedProducts['register']->plans()->where('billing_period', 1)->sole()->id,
            'price' => '18.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active']));
        $convertedDomain = Paymenter\Extensions\Servers\Enom\Models\Domain::create([
            'service_id' => $convertedService->id, 'user_id' => $client->id, 'server_id' => $convertedServer->id,
            'domain' => 'converted-'.$converted->id.'.com', 'sld' => 'converted-'.$converted->id, 'tld' => 'com',
            'status' => 'active', 'provider_expires_at' => '2027-12-01', 'billing_managed' => true, 'price_mode' => 'standard']);
        $nativePrices = fn ($action) => App\Models\Price::whereIn('plan_id', $convertedProducts[$action]->plans()->pluck('id'))->orderBy('id')->get()->toArray();
        $convertedRegisterPrices = $nativePrices('register');
        $convertedTransferPrices = $nativePrices('transfer');
        $assertConvertedPrices = function (string $step) use ($converted, $convertedRenewCost, $convertedTransferCost, $nativePrices, $convertedRegisterPrices, $convertedTransferPrices) {
            $current = $converted->fresh();
            verify($nativePrices('register') === $convertedRegisterPrices, 'Converted registration prices were lost during '.$step.'.');
            verify($convertedTransferCost === null ? $nativePrices('transfer') === $convertedTransferPrices : count($nativePrices('transfer')) === 1,
                'Converted transfer prices were lost during '.$step.'.');
            verify($current->renew_price !== null && ($convertedRenewCost !== null || $current->renew_price === '18.00'),
                'Converted renewal price was lost during '.$step.'.');
            verify(!$current->orderable('register') && $current->terms() === [] && $current->registrationTotal(1) === null,
                'Converted registration with unknown costs remained on sale during '.$step.'.');
            if ($convertedTransferCost === null) {
                verify(!$current->orderable('transfer') && $current->price('transfer') === null, 'Converted transfer with unknown cost remained on sale during '.$step.'.');
            }
        };
        $catalogue->publish($converted);
        $assertConvertedPrices('publication');
        foreach (Tld::ACTIONS as $action) {
            TldResource::setAction($converted->fresh(), $action, false);
            if ($action === 'register' || $action === 'transfer' && $convertedTransferCost === null) {
                $assertConvertedPrices($action.' off');
                refuses(fn () => TldResource::setAction($converted->fresh(), $action, true), 'No current price is available.', 'Unknown converted action was offered.');
            } else {
                if ($action === 'renew') {
                    verify($converted->fresh()->renew_price !== null && Pricing::renewalPrice($convertedDomain->fresh()) === null,
                        'Converted renewal disable erased its retained price or kept billing enabled.');
                    verify(!str_contains($convertedProducts['register']->fresh()->description, 'It renews at'),
                        'Disabled converted renewal remained quoted in product text.');
                }
                TldResource::setAction($converted->fresh(), $action, true);
            }
            $assertConvertedPrices($action.' on');
            if ($action === 'renew' && $convertedRenewCost === null) {
                verify(Pricing::renewalPrice($convertedDomain->fresh()) === '18.00', 'Converted domain renewal price was lost after off/on.');
                Pricing::syncServicePrice($convertedDomain->fresh());
                verify(number_format((float) $convertedService->fresh()->price, 2, '.', '') === '18.00'
                    && str_contains($convertedProducts['register']->fresh()->description, 'It renews at $18.00/yr'),
                    'Converted renewal service price or product text was not restored.');
            }
        }
        TldResource::applyPricing($converted->fresh(), ['use_default_rule' => false, 'pricing_mode' => $convertedMode,
            'markup' => $convertedMode === 'markup_amount' ? '9.00' : '90.00', 'round_up' => 'none']);
        $assertConvertedPrices('rule change');
        CatalogueRuntime::saveDefaultRule($convertedServer->id, array_replace(PricingRule::DEFAULT, ['mode' => 'markup_amount', 'markup' => '8.00']));
        TldResource::applyPricing($converted->fresh(), ['use_default_rule' => true]);
        CatalogueRuntime::saveDefaultRule($convertedServer->id, array_replace(PricingRule::DEFAULT, ['mode' => 'markup_amount', 'markup' => '9.00']));
        $assertConvertedPrices('default rule change');
        $converted->refresh()->fill(['enabled' => false]);
        $catalogue->publish($converted);
        $catalogue->enable($converted->fresh());
        $assertConvertedPrices('enable');
        foreach (['com:10:1', 'com:16:1', 'com:19:1'] as $failedKey) {
            $failedReads = [$failedKey => 'transport'];
            verify($catalogue->syncTld($converted->fresh()) === null, 'Converted failed price refresh was accepted.');
            $assertConvertedPrices('failed price refresh');
            $catalogue->publish($converted->fresh());
            $assertConvertedPrices('publication after failed price refresh');
        }
        $failedReads = [];
        verify($catalogue->syncTld($converted->fresh()) !== null && $converted->fresh()->orderable('register')
            && $converted->fresh()->registrationTotal(2) === '35.90' && $converted->fresh()->price('transfer') === '17.95',
            'Successful cost sync did not release converted sales at current prices.');
        // Reading the newly required cost must keep corrupt sale data isolated to its ending.
        Illuminate\Support\Facades\DB::table('enom_tlds')->where('id', $converted->id)->update(['cost_transfer' => 'malformed']);
        try { $corruptCostPrice = $converted->fresh()->price('transfer'); }
        catch (Throwable) { verify(false, 'Malformed transfer cost escaped the sale guard.'); }
        verify($corruptCostPrice === null && $catalogue->resolve('example.ai')['tld']->id === $ai->id,
            'Malformed transfer cost remained on sale or hid a valid ending.');
        Illuminate\Support\Facades\DB::table('enom_tlds')->where('id', $converted->id)->update(['cost_transfer' => '8.9500']);
        $missingTerms = ['com:10:1', 'com:19:1'];
        $catalogue->syncTld($converted->fresh());
        verify($convertedProducts['register']->plans()->where('billing_period', 1)->sole()->prices()->count() === 0
            && $convertedProducts['transfer']->plans()->sole()->prices()->count() === 0 && $converted->fresh()->registrationTotal(2) === '35.90',
            'Explicitly unavailable converted costs retained stale sale prices.');
        $missingTerms = [];
        $converted->refresh()->update(['enabled' => false]);
    }
}

$net->refresh()->fill(['enabled' => true, 'register_enabled' => true, 'renew_enabled' => true]);
$catalogue->publish($net);
$net->cost_register_terms = array_replace($net->cost_register_terms, [2 => '71.4321']);
$net->save();
TldResource::applyPricing($net, ['use_default_rule' => false, 'pricing_mode' => 'markup_amount', 'markup' => '1.00', 'round_up' => 'half']);
verify($net->registrationTotal(2) === '145.00', 'Pricing form did not publish exact-term rounding.');
$preview = (string) TldResource::preview($net, $catalogue->rule($net));
verify(str_contains($preview, 'Price preview for .net') && str_contains($preview, '143.8642') === false
    && str_contains($preview, '142.8642') && str_contains($preview, '145.00') && str_contains($preview, '<th scope="col"'), 'Term preview lacks cost, retail or accessible headings.');
$net->cost_register_terms = array_replace($net->cost_register_terms, [3 => null]);
$net->save();
verify(str_contains((string) TldResource::preview($net, $catalogue->rule($net)), 'No Enom price — not offered'), 'Missing-price preview is misleading.');

// Costs and markup cannot enter an unauthorized model snapshot or modal response.
Auth::setUser($manager);
verify(!array_key_exists('cost_register_terms', $net->toArray()) && !array_key_exists('markup', $net->toArray()), 'Wholesale data entered model state.');
refuses(fn () => TldResource::preview($net, PricingRule::DEFAULT), '', 'Manager could read a cost preview.', [Symfony\Component\HttpKernel\Exception\HttpException::class]);
refuses(fn () => TldResource::applyPricing($net, ['use_default_rule' => true]), '', 'Manager changed a pricing rule.', [Symfony\Component\HttpKernel\Exception\HttpException::class]);
verify(!TldResource::defaultRuleAction($server)->isVisible(), 'Default rule action is visible without costs permission.');
$page = Livewire::actingAs($manager)->test(ListTlds::class);
$html = $page->html();
verify(str_contains($html, 'TLDs &amp; pricing') && !str_contains($html, '71.4321') && !str_contains($html, '142.8642'), 'Manager page leaked cost or did not render.');
verify(!str_contains(json_encode($page->snapshot), '71.4321'), 'Manager Livewire snapshot leaked exact-term costs.');

Auth::setUser($coster);
$page = Livewire::actingAs($coster)->test(ListTlds::class);
verify(str_contains($page->html(), 'Default pricing rule') && str_contains($page->html(), 'Sync costs now'), 'Pricing header actions are missing.');
$page->mountTableAction('edit', $net->id);
$html = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
verify((float) $page->get('mountedActions')[0]['data']['markup'] === 1.0, 'Pricing modal lost the protected markup value.');
verify(str_contains($html, 'Use the default rule') && str_contains($html, 'How to price') && str_contains($html, 'Round up to')
    && str_contains($html, '142.8642'), 'Per-ending modal lacks rule inputs or term preview.');
$page->setTableActionData(['use_default_rule' => '0', 'pricing_mode' => 'markup_percent', 'markup' => '50.00', 'round_up' => 'whole']);
$page->callMountedTableAction();
verify($page->instance()->getErrorBag()->isEmpty(), 'Pricing form failed validation: '.json_encode($page->instance()->getErrorBag()->toArray()));
verify($net->fresh()->registrationTotal(2) === '216.00', 'Native admin save did not publish plan prices.');
$page = Livewire::actingAs($coster)->test(ListTlds::class);
$page->mountAction('defaultRule'.$server->id);
verify(str_contains($page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml(), 'Preview with'), 'Default rule modal lacks ending preview.');
$page->setActionData(['pricing_mode' => 'markup_percent', 'markup' => '25.00', 'round_up' => 'ending_99']);
$page->callMountedAction();
verify($page->instance()->getErrorBag()->isEmpty(), 'Default rule form failed validation: '.json_encode($page->instance()->getErrorBag()->toArray()));
verify(CatalogueRuntime::defaultRule($server->id)['markup'] === '25.00', 'Default rule modal did not persist the native array setting.');
$successNotice = collect(session('filament.claimed_notifications', session('filament.notifications', [])))->last();
verify($successNotice['status'] === 'success' && str_starts_with($successNotice['title'], 'Default rule applied to '), 'Complete default-rule publication did not report success.');
$failDefaultPublish = true;
App\Models\Product::updating(function ($product) use ($blockedProduct, &$failDefaultPublish) {
    if ($failDefaultPublish && (int) $product->id === (int) $blockedProduct->id) { throw new RuntimeException('Injected default-rule publication failure'); }
});
session()->forget('filament.notifications');
$page = Livewire::actingAs($coster)->test(ListTlds::class);
$page->mountAction('defaultRule'.$ruleServer->id);
$page->setActionData(['pricing_mode' => 'markup_percent', 'markup' => '25.00', 'round_up' => 'none']);
$page->callMountedAction();
verify($page->instance()->getErrorBag()->isEmpty(), 'Partial default-rule publication failed the native action.');
$partialNotice = collect(session('filament.claimed_notifications', session('filament.notifications', [])))->last();
verify($partialNotice['status'] === 'warning' && $partialNotice['title'] === "Default rule applied to 2 TLDs. Couldn't update: .ruleblocked — open each to retry.", 'Partial default-rule publication did not show the requested warning and retry guidance.');
$failDefaultPublish = false;
$setup = Livewire::actingAs($coster)->test(EnomSetup::class);
verify(str_contains($setup->html(), 'Default pricing rule') && str_contains($setup->html(), 'Sync costs now'), 'Setup lacks pricing and sync actions.');

// A new margin hold alerts only authorized staff, once, and leaves the invoice ledger alone.
$net->refresh()->fill(['register_enabled' => true]);
$invoice = App\Models\Invoice::create(['user_id' => $coster->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => now()->addDays(7)]);
$invoice->items()->create(['description' => 'Register frozen.example (2 years)', 'price' => '12.34', 'quantity' => 1]);
$beforeInvoices = tableRows(['invoices', 'invoice_items']);
$before = App\Models\Notification::where('user_id', $coster->id)->count();
TldResource::applyPricing($net, ['use_default_rule' => false, 'pricing_mode' => 'fixed', 'markup' => '0.00', 'round_up' => 'none',
    'fixed_register' => '10.00', 'fixed_renew' => '20.00', 'fixed_transfer' => '20.00']);
verify($net->fresh()->register_enabled && $net->fresh()->registrationTotal(2) === null && $net->fresh()->registrationTotal(4) === '40.00' && App\Models\Notification::where('user_id', $coster->id)->count() === $before + 1, 'Term margin hold did not preserve other terms and alert staff.');
$alert = App\Models\Notification::where('user_id', $coster->id)->latest('id')->first();
verify(str_starts_with($alert->title, '.net registration for ') && str_ends_with($alert->title, ' removed: price below cost') && str_contains($alert->body, 'still on sale'), 'Term margin alert wording differs.');
verify(App\Models\Notification::whereIn('user_id', [$manager->id, $client->id])->count() === 0, 'Margin hold alerted unauthorized users.');
$catalogue->publish($net);
verify(App\Models\Notification::where('user_id', $coster->id)->count() === $before + 1, 'Unchanged hold sent a duplicate alert.');
verify(tableRows(['invoices', 'invoice_items']) === $beforeInvoices, 'Pricing modified issued debt.');
TldResource::setAction($net, 'register', true);
verify($net->fresh()->registrationTotal(2) === null && $net->fresh()->registrationTotal(4) === '40.00', 'Toggle restored a below-cost term or removed an available one.');
// Expanding the transfer allowlist needs explicit confirmation and respects C's exclusions.
$io = Tld::create(['server_id' => $server->id, 'tld' => 'io', 'register_enabled' => true, 'renew_enabled' => true]);
refuses(fn () => TldResource::setAction($io, 'transfer', true), 'Confirm a tested transfer', 'Transfer allowlist changed without confirmation.');
$page = Livewire::actingAs($coster)->test(ListTlds::class);
$page->mountTableAction('toggleTransfer', $io->id);
verify(str_contains($page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml(), 'I tested a transfer'), 'Transfer toggle lacks the required confirmation.');
$page->setTableActionData(['tested_transfer' => true]);
$page->callMountedTableAction();
verify($page->instance()->getErrorBag()->isEmpty() && $io->fresh()->orderable('transfer'), 'Confirmed transfer ending was not priced and enabled.');
$allowlist = Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard::setting($server->id, 'transfer_allowlist');
refuses(fn () => TldResource::setAction($ai, 'transfer', true, true), 'not supported', 'Unsupported transfer ending was enabled.');
verify(Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard::setting($server->id, 'transfer_allowlist') === $allowlist, 'Unsupported transfer changed the allowlist.');
// A single toggle preserves a prior committed append despite a stale server/settings relation.
$toggleServer = App\Models\Server::create(['name' => 'Transfer toggle', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$toggleServer->settings()->create(['key' => 'transfer_allowlist', 'value' => 'com,net,org', 'type' => 'string', 'encrypted' => false]);
$toggleEnding = Tld::create(['server_id' => $toggleServer->id, 'tld' => 'xyz', 'enabled' => true, 'min_years' => 2, 'max_years' => 3,
    'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => false, 'use_default_rule' => false, 'pricing_mode' => 'fixed',
    'fixed_register' => '20.00', 'fixed_renew' => '20.00', 'fixed_transfer' => '20.00']);
$untouchedEnding = Tld::create(['server_id' => $toggleServer->id, 'tld' => 'dev', 'min_years' => 1, 'max_years' => 1]);
$toggleEnding->load('server.settings');
$toggleServer->settings()->where('key', 'transfer_allowlist')->update(['value' => 'com,net,org,dev']);
$untouchedBefore = (array) Illuminate\Support\Facades\DB::table('enom_tlds')->where('id', $untouchedEnding->id)->sole();
$commands = [];
$connection = Illuminate\Support\Facades\DB::connection();
$originalGrammar = $connection->getQueryGrammar();
// SQLite cannot execute FOR UPDATE. Observe the actual builder lock and transaction at compilation instead.
$lockGrammar = new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $reads = [];
    public function compileSelect(Illuminate\Database\Query\Builder $query)
    {
        if (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            $this->reads[] = [$query->from, $query->lock, Illuminate\Support\Facades\DB::transactionLevel()];
        }
        return parent::compileSelect($query);
    }
};
$connection->setQueryGrammar($lockGrammar);
try { TldResource::setAction($toggleEnding, 'transfer', true, true); }
finally { $connection->setQueryGrammar($originalGrammar); }
verify(($lockGrammar->reads[0] ?? null) === ['extensions', true, 1],
    'Transfer allowlist did not lock the server inside the transaction before reading fresh settings.');
verify(Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard::setting($toggleServer->id, 'transfer_allowlist') === 'com,net,org,dev,xyz',
    'Transfer toggle lost a committed prior allowlist append through a stale relation.');
$priceReads = array_values(array_filter($commands, fn ($command) => $command[0] === 'PE_GetProductPrice'));
verify(array_map(fn ($command) => $command[1]['TLD'].':'.$command[1]['ProductType'].':'.$command[1]['Years'], $priceReads)
    === ['xyz:10:2', 'xyz:10:3', 'xyz:16:1', 'xyz:19:1'], 'Transfer toggle read prices for another ending or skipped an exact term/action.');
verify((array) Illuminate\Support\Facades\DB::table('enom_tlds')->where('id', $untouchedEnding->id)->sole() === $untouchedBefore,
    'Transfer toggle published costs or timestamps for another ending.');
verify($toggleEnding->fresh()->orderable('transfer') && $toggleEnding->fresh()->price('transfer') === '20.00'
    && $toggleEnding->fresh()->cost('transfer') === '8.9500', 'Per-ending sync did not publish the enabled transfer at the existing margin.');

// Full sync still visits every ending, publishes costs and emits the same exact summary and notice.
$commands = [];
$costOverride['dev:10:1'] = '9.50';
$toggleSummary = $catalogue->sync($toggleServer->id);
verify($toggleSummary === ['tlds' => 2, 'changed' => ['.dev'], 'missing' => [], 'held' => [], 'renewals_below_cost' => [], 'failed' => []]
    && \Paymenter\Extensions\Servers\Enom\Services\Catalogue::syncMessage($toggleSummary)
        === ['Costs synced for 2 TLDs.', '1 TLD had cost changes. Nothing on sale is below cost.', false], 'Full sync changed its summary or staff notification.');
verify(array_map(fn ($command) => $command[1]['TLD'].':'.$command[1]['ProductType'].':'.$command[1]['Years'], $commands)
    === ['xyz:10:2', 'xyz:10:3', 'xyz:16:1', 'xyz:19:1', 'dev:10:1', 'dev:16:1', 'dev:19:1']
    && $untouchedEnding->fresh()->cost('register') === '9.5000' && $untouchedEnding->fresh()->costs_synced_at !== null,
    'Full sync skipped an ending, exact price read or publication.');
unset($costOverride['dev:10:1']);

// The public per-ending path reports provider failure and retains every published byte.
$beforeFailedSync = tableRows(['enom_tlds', 'products', 'plans', 'prices']);
$failedReads = ['xyz:19:1' => 'transport'];
$reportedBefore = count($reportedRules);
verify($catalogue->syncTld($toggleEnding) === null && count($reportedRules) === $reportedBefore + 1
    && end($reportedRules) === 'Could not read prices from Enom.', 'Per-ending price failure was not reported and returned as a failure.');
verify(tableRows(['enom_tlds', 'products', 'plans', 'prices']) === $beforeFailedSync,
    'Per-ending price failure changed known costs, timestamps, holds or native prices.');
$failedReads = [];
refuses(fn () => TldResource::setAction(new Tld(['server_id' => 999999, 'tld' => 'missingserver']), 'transfer', true, true),
    'The Enom server no longer exists. Reload the page.', 'Missing transfer server did not fail clearly.', [InvalidArgumentException::class]);

$code = CatalogueRuntime::config();
verify(count($code) === 1 && $code[0]['type'] === 'placeholder' && !str_contains($code[0]['name'], 'pricing_default_rule'), 'Native server note would overwrite the stored nested rule.');
CatalogueRuntime::boot();
$scheduled = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($event) => $event->description === 'enom-catalogue-costs');
verify($scheduled && $scheduled->expression === '0 0 * * *' && $scheduled->withoutOverlapping, 'Daily cost schedule is missing or can overlap.');

// Sync warnings retain a review action for renewal-only losses, including repeat syncs.
Auth::setUser($coster);
$summaryServer = App\Models\Server::create(['name' => 'Renewal summary', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$renewalEnding = Tld::create(['server_id' => $summaryServer->id, 'tld' => 'com', 'enabled' => true, 'min_years' => 1, 'max_years' => 1,
    'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => true, 'use_default_rule' => false, 'pricing_mode' => 'fixed',
    'fixed_register' => '20.00', 'fixed_transfer' => '20.00', 'fixed_renew' => '1.00']);
foreach ([1, 2] as $repeat) {
    session()->forget('filament.notifications');
    TldResource::syncCosts($summaryServer->id);
    $notice = collect(session('filament.notifications'))->last();
    $review = collect($notice['actions'])->first();
    verify($notice['status'] === 'warning' && str_contains($notice['body'], 'Renewals below cost (still billed): .com.')
        && !str_contains($notice['body'], 'Nothing on sale is below cost.') && $review['label'] === 'Review pricing', 'Renewal-only sync lost its warning or direct review action.');
    parse_str(parse_url($review['url'], PHP_URL_QUERY) ?? '', $destination);
    verify(($destination['tableAction'] ?? null) === 'edit'
        && ($destination['tableActionRecord'] ?? null) === (string) $renewalEnding->id, 'Direct pricing review targets the wrong modal or ending.');
}
$secondRenewal = $renewalEnding->replicate();
$secondRenewal->tld = 'org'; $secondRenewal->save();
session()->forget('filament.notifications');
TldResource::syncCosts($summaryServer->id);
$notice = collect(session('filament.notifications'))->last();
$review = collect($notice['actions'])->first();
parse_str(parse_url($review['url'], PHP_URL_QUERY) ?? '', $destination);
verify(($destination['tableFilters']['below_cost']['isActive'] ?? null) === '1', 'Several renewal losses do not activate the below-cost filter.');
$unaffected = $renewalEnding->fresh()->replicate();
$unaffected->tld = 'net'; $unaffected->sale_holds = []; $unaffected->save();
$table = Livewire::test(ListTlds::class)->instance()->getTable();
$affected = $table->getFilter('below_cost')->apply(Tld::where('server_id', $summaryServer->id), $destination['tableFilters']['below_cost'])
    ->orderBy('tld')->pluck('tld')->all();
verify($affected === ['com', 'org'], 'Below-cost filter lost an affected renewal ending.');

// Failed delivery for one message/user must not stop publication or the remaining staff notices.
$nextCoster = $staffUser('Nextcoster', ['admin.enom.manage', 'admin.enom.costs']);
$alertServer = App\Models\Server::create(['name' => 'Alert failures', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$alertEnding = Tld::create(['server_id' => $alertServer->id, 'tld' => 'alerttest', 'enabled' => true, 'min_years' => 1, 'max_years' => 1,
    'register_enabled' => true, 'renew_enabled' => true, 'transfer_enabled' => false, 'use_default_rule' => false, 'pricing_mode' => 'fixed',
    'fixed_register' => '1.00', 'fixed_renew' => '1.00', 'fixed_transfer' => '1.00', 'cost_register_terms' => [1 => '10.0000'], 'cost_renew' => '10.0000']);
$failedNotice = $failedEmail = false;
$reportedAlerts = &$reportedRules;
App\Models\Notification::creating(function ($notice) use ($coster, &$failedNotice) {
    if (!$failedNotice && (int) $notice->user_id === (int) $coster->id && str_contains($notice->title, '.alerttest')) {
        $failedNotice = true;
        throw new RuntimeException('Injected pricing notice failure');
    }
});
App\Models\EmailLog::creating(function ($email) use ($coster, &$failedEmail) {
    if (!$failedEmail && $email->to === $coster->email && str_contains($email->subject, '.alerttest')) {
        $failedEmail = true;
        throw new RuntimeException('Injected pricing email failure');
    }
});
$catalogue->publish($alertEnding);
verify($failedNotice && $failedEmail && in_array('Injected pricing notice failure', $reportedAlerts, true)
    && in_array('Injected pricing email failure', $reportedAlerts, true), 'Pricing delivery failures escaped or were not reported.');
verify(App\Models\Notification::where('user_id', $coster->id)->where('title', 'like', '%.alerttest%')->count() === 1
    && App\Models\Notification::where('user_id', $nextCoster->id)->where('title', 'like', '%.alerttest%')->count() === 2
    && App\Models\EmailLog::where('to', $nextCoster->email)->where('subject', 'like', '%.alerttest%')->count() === 2, 'Failed pricing delivery stopped another message or user.');
verify($alertEnding->fresh()->registrationTotal(1) === null && $alertEnding->fresh()->price('renew') === '1.00'
    && $catalogue->productFor($alertEnding, 'register')->plans()->sole()->prices()->count() === 0, 'Pricing alert failure interrupted publication.');

echo 'Catalogue admin: '.$checks.' checks passed; native Filament forms and permissions.'.PHP_EOL;
$successful = true;

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
        && !str_contains($notice['body'], 'Nothing on sale is below cost.') && $review['label'] === 'Review pricing'
        && $review['url'] === \Paymenter\Extensions\Servers\Enom\Services\Catalogue::pricingUrl($renewalEnding), 'Renewal-only sync lost its warning or direct review action.');
}
$secondRenewal = $renewalEnding->replicate();
$secondRenewal->tld = 'org'; $secondRenewal->save();
session()->forget('filament.notifications');
TldResource::syncCosts($summaryServer->id);
$notice = collect(session('filament.notifications'))->last();
$review = collect($notice['actions'])->first();
verify($review['url'] === TldResource::getUrl('index', ['tableFilters' => ['below_cost' => ['isActive' => true]]]), 'Several renewal losses do not open the below-cost filter.');

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

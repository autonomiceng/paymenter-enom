<?php
$enomBootExtension = false;
$enomCreateCurrency = false;
require __DIR__.'/enom-native-app.php';
require_once __DIR__.'/enom-cart-order.php';
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

$checks = 0;
function checkBilling(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function refusedBilling(callable $action, string $message): void {
    try { $action(); }
    catch (RuntimeException) { checkBilling(true, $message); return; }
    throw new RuntimeException($message);
}
function atBilling(string $day): void { Carbon\Carbon::setTestNow($day.' 12:00:00'); }
function billingJobCount(string $class): int {
    return DB::table('jobs')->get()->filter(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === $class)->count();
}
config(['app.version' => 'development']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$nativeSchema = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name");
$user = App\Models\User::create(['email' => 'billing-owner@example.invalid', 'password' => bcrypt('synthetic')]);
$other = App\Models\User::create(['email' => 'billing-other@example.invalid', 'password' => bcrypt('synthetic')]);
$role = App\Models\Role::create(['name' => 'Synthetic Enom staff', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$staff = App\Models\User::create(['email' => 'billing-staff@example.invalid', 'password' => bcrypt('synthetic'), 'role_id' => $role->id]);
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$server = App\Models\Server::create(['name' => 'Synthetic billing', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
enomTestTld(['server_id'=>$server->id,'tld'=>'com','renew_price'=>'15.00']);
$server->settings()->create(['key' => 'billing_enabled', 'value' => true, 'type' => 'boolean']);
$server->settings()->create(['key' => 'operations_enabled', 'value' => true, 'type' => 'boolean']);
$server->settings()->create(['key' => 'uid', 'value' => 'test-account', 'type' => 'text']);
$category = App\Models\Category::create(['name' => 'Domain billing test', 'slug' => 'domain-billing-test']);
$product = App\Models\Product::create(['name' => 'Managed domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
$operations = new DomainOperations;
$billing = new DomainBilling($operations);
$make = function (string $name, int $price = 15) use ($user, $server, $product, $plan): Domain {
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active', 'expires_at' => null]);
    $source = fn ($value) => ['status' => 'fresh', 'succeeded_at' => now()->toIso8601String(), 'value' => $value];
    return Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => $name.'.com', 'sld' => $name, 'tld' => 'com', 'status' => 'active', 'provider_expires_at' => '2026-12-01',
        'billing_managed' => true, 'provider_domain_id' => '456',
        'renewal_policy' => ['enabled' => true, 'issue_days' => 21, 'due_days' => 7, 'account_fingerprint' => hash('sha256', 'test:test-account')], 'authority_generation' => 1, 'confirmed_revision' => 1, 'price_mode' => 'standard',
        'observation' => ['status' => 'fresh', 'account_fingerprint' => hash('sha256', 'test:test-account'), 'expiry_drift' => 'none',
            'sources' => ['info' => $source(['domain_name_id' => '456', 'registration_status' => 'registered', 'purchase_status' => 'paid', 'expires_at' => '2026-12-01T00:00:00Z']), 'renewal' => $source(['auto_renew' => false]),
                'expiry' => $source(['expires_at' => '2026-12-01T00:00:00Z'])],
            'renewal_quote' => ['amount' => '11.99', 'currency' => 'USD', 'private' => 'internal-cost-marker']]]);
};

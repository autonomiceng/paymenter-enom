<?php
$enomBootExtension = true;
$enomCreateCurrency = false;
require __DIR__.'/enom-native-app.php';

require_once __DIR__.'/enom-cart-order.php';
$checks = 0;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$user = App\Models\User::create(['email' => 'rejection@example.invalid', 'password' => 'synthetic']);
$other = App\Models\User::create(['email' => 'other@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Offline Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$category = App\Models\Category::create(['name' => 'Offline', 'slug' => 'offline']);
$product = App\Models\Product::create(['name' => 'Offline .com', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
foreach (['uid' => 'offline', 'token' => 'offline', 'operations_enabled' => true, 'billing_enabled' => true] as $key => $value) { $server->settings()->create(compact('key', 'value')); }
enomTestTld(['server_id' => $server->id, 'tld' => 'com', 'register_price' => '15.00', 'renew_price' => '15.00', 'transfer_price' => '15.00']);
enomCartProfile($user);
$sequence = 0;
function fixture(string $action = 'transfer'): array {
    global $sequence, $user, $server, $product, $plan;
    $sequence++;
    return Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($action) {
        global $sequence, $user, $server, $product, $plan;
        $order = App\Models\Order::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $service = $order->services()->create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'pending']);
        $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'reject'.$sequence.'.com', 'sld' => 'reject'.$sequence, 'tld' => 'com', 'status' => $action === 'register' ? 'registration_pending' : 'transfer_pending', 'capabilities' => [], 'billing_managed' => true, 'renewal_policy' => ['enabled' => true, 'account_fingerprint' => hash('sha256', 'test:offline'), 'invoice_lead_days' => 21, 'invoice_due_days' => 7]]);
        $invoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'paid']);
        $item = $invoice->items()->create(['reference_type' => App\Models\Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => '15.00', 'description' => 'Offline rejection']);
        $invoice->transactions()->create(['amount' => '15.00', 'status' => App\Enums\InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true, 'transaction_id' => 'offline-'.$sequence]);
        $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => 'offline-'.$sequence, 'action' => $action, 'funding_basis' => 'paid_invoice', 'state' => 'pending', 'amount' => '15.00', 'currency' => 'USD', 'provider_order_id' => (string) (1000 + $sequence), 'policy' => ['billing_managed' => true, 'domain' => $domain->domain, 'user_id' => (int) $user->id, 'service_id' => (int) $service->id, 'server_id' => (int) $server->id, 'authority_generation' => 0, 'confirmed_revision' => 0, 'currency' => 'USD', 'term' => 1, 'checkout_product_id' => (int) $product->id, 'checkout_plan_id' => (int) $plan->id, 'checkout_years' => 1, 'retail_minor' => 1500, 'account_fingerprint' => hash('sha256', 'test:offline'), 'paid_at' => now()->utc()->toIso8601String(), 'dispatch_attempted_at' => now()->toIso8601String()], 'payload' => Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup::payload($user, (int) $server->id, $action) + ['registry' => [], 'epp' => 'synthetic-secret'], 'started_at' => now()->subMinute()]);
        return [$domain, $operation->fresh()];
    });
}
function finances(): array {
    $data = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'failed_jobs'] as $table) { $data[$table] = DB::table($table)->orderBy('id')->get()->toArray(); }
    // Registrar work queued by a rejection would be a replay; the refund notification email is the only allowed job.
    $data['jobs'] = DB::table('jobs')->orderBy('id')->get()
        ->reject(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === 'App\\Mail\\Mail')->values()->all();
    return $data;
}
/** a definite rejection of a paid registration/transfer credits the exact paid amount once and cancels its service. */
function refundedOnce(Operation $operation, string $before): bool {
    $operation = $operation->fresh();
    $credit = App\Models\Credit::where('user_id', $operation->domain->user_id)->where('currency_code', 'USD')->value('amount');
    return ($operation->cost['refund']['amount'] ?? null) === '15.00' && ($operation->cost['refund']['to'] ?? null) === 'account_credit'
        && (int) round(((float) $credit - (float) $before) * 100) === 1500 && App\Models\Service::find($operation->service_id)?->status === 'cancelled';
}
function creditNow(): string { global $user; return (string) (App\Models\Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? '0'); }
App\Models\Credit::create(['user_id' => $other->id, 'currency_code' => 'USD', 'amount' => '900.00']);
function reconciler(Operation $operation, array $overrides = [], ?Closure $afterRead = null): array {
    $commands = new ArrayObject;
    $data = $overrides + ['id' => $operation->provider_order_id, 'sld' => $operation->domain->sld, 'tld' => 'com', 'header' => '2', 'detail' => '32', 'errcount' => '0'];
    $client = new RegistrarClient('offline', 'offline', function ($command, $params) use ($data, $commands, $afterRead): string {
        $commands[] = $command;
        if ($command === 'GetDomainExp') {
            return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><ExpirationDate>2027-10-13</ExpirationDate></interface-response>';
        }
        if ($command === 'GetDomainInfo') { return '<interface-response><Command>GetDomainInfo</Command><ErrCount>0</ErrCount><Done>true</Done><GetDomainInfo><domainname domainnameid="456">'.$data['sld'].'.com</domainname><status><expiration>2027-10-13</expiration><belongs-to>offline</belongs-to></status></GetDomainInfo></interface-response>'; }
        if ($command !== 'TP_GetOrder') { throw new RuntimeException('Unexpected provider command '.$command); }
        if ($afterRead) { $afterRead(); }
        $detail = '<transferorderdetail><sld>'.$data['sld'].'</sld><tld>'.$data['tld'].'</tld><statusid>'.$data['detail'].'</statusid></transferorderdetail>';
        if ($data['multiple'] ?? false) { $detail .= $detail; }
        if ($data['missing_detail'] ?? false) { $detail = ''; }
        $error = $data['errcount'] === '0' ? '' : '<RRPCode>530</RRPCode><Err1>Offline query failure</Err1>';
        return '<interface-response><Command>'.$command.'</Command><ErrCount>'.$data['errcount'].'</ErrCount><Done>true</Done>'.$error.'<transferorder><transferorderid>'.$data['id'].'</transferorderid><statusid>'.$data['header'].'</statusid>'.$detail.'</transferorder></interface-response>';
    });
    return [new DomainOperations(new EnomApi($client), fn () => ['uid' => 'offline', 'token' => 'offline']), $commands];
}

$app->instance(Paymenter\Extensions\Servers\Enom\Services\Rdap::class, new Paymenter\Extensions\Servers\Enom\Services\Rdap(fn () => ['status' => 503, 'body' => '']));

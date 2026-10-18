<?php
$enomTestLabel = 'native-order-regressions';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\Rdap;

$owner = client('native-order-regressions@example.com');
$reported = [];
app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (Throwable $error) use (&$reported) {
    $reported[] = $error->getMessage(); return false;
});

// Native cancellation commits even when synchronous cleanup fails; the ordinary sweep retries it.
$cancelled = [];
$failRetirement = true;
Operation::updating(function (Operation $operation) use (&$failRetirement, &$cancelled) {
    if ($failRetirement && in_array($operation->id, array_column($cancelled, 'id'), true)) { throw new Error('Injected cancellation cleanup failure'); }
});
foreach (['service', 'invoice'] as $native) {
    $order = enomCartOrder($owner, 'cancel-failure-'.$native.'.com');
    $cancelled[] = $order['operation'];
    $beforeReports = count($reported);
    $record = $order[$native];
    DB::transaction(fn () => $record->update(['status' => $native === 'service' ? Service::STATUS_CANCELLED : Invoice::STATUS_CANCELLED]));
    verify($record->fresh()->status === 'cancelled' && count($reported) === $beforeReports + 1
        && end($reported) === 'Injected cancellation cleanup failure' && $order['operation']->fresh()->state === 'awaiting_payment',
        'Cleanup failure aborted native cancellation, escaped reporting or retired the request: '.$native);
}
$failRetirement = false;
$operations->sweep();
verify(collect($cancelled)->every(fn ($operation) => $operation->fresh()->state === 'rejected' && $operation->fresh()->payload === null
    && $operation->fresh()->domain->status === 'cancelled'), 'Sweep did not recover failed cancellation cleanup.');

// Only invalid transfer configuration is downgraded to the ordinary taken response.
$rdap = new class extends Rdap {
    public int $calls = 0;
    public bool $fail = false;
    public function precheck(string $domain, int $creationDays = 60, int $transferDays = 60, ?DateTimeImmutable $today = null): array
    {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('Injected RDAP method failure'); }
        return ['allowed' => true, 'code' => null, 'warning' => Rdap::UNCHECKED, 'expires_at' => null];
    }
};
$app->instance(Rdap::class, $rdap);
$orders = app(DomainOrders::class);
foreach (['transfer_creation_lock_days' => '366', 'transfer_previous_lock_days' => 'invalid'] as $setting => $value) {
    $name = 'invalid-'.str_replace('_', '-', $setting).'.com';
    $availability[$name] = 'transferable';
    serverSetting($setting, $value);
    $beforeReports = count($reported);
    $result = $orders->check($owner, $name);
    verify(!$result['available'] && !$result['transferable'] && $result['transfer_reason'] === null && $result['warning'] === null
        && $result['transfer_url'] === null && count($reported) === $beforeReports + 1 && $rdap->calls === 0,
        'Invalid transfer settings did not produce a reported ordinary taken response: '.$setting);
    serverSetting($setting, null);
}
$availability['rdap-warning.com'] = 'transferable';
$result = $orders->check($owner, 'rdap-warning.com');
verify($result['transferable'] && $result['warning'] === Rdap::UNCHECKED && $rdap->calls === 1, 'RDAP unknown-result behavior changed.');
$rdap->fail = true;
$availability['rdap-failure.com'] = 'transferable';
refuses(fn () => $orders->check($owner, 'rdap-failure.com'), 'Injected RDAP method failure', 'Non-configuration precheck failure was swallowed.', [RuntimeException::class]);

// Progress reads one minimal newest transfer row per refused domain, regardless of older history or newer other actions.
$refused = [];
foreach (['provider_rejected', 'transfer_code_timeout'] as $latestCode) {
    $domain = clientDomain($owner, ['status' => 'transfer_rejected']);
    foreach ([['transfer', $latestCode === 'provider_rejected' ? 'transfer_code_timeout' : 'provider_rejected'], ['transfer', $latestCode], ['register', 'transfer_code_timeout']] as $index => [$action, $code]) {
        Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id, 'operation_key' => 'refused-'.$domain->id.'-'.$index,
            'action' => $action, 'state' => 'rejected', 'safe_code' => $code, 'term' => 1, 'amount' => '0.00', 'currency' => 'USD']);
    }
    $refused[] = $domain;
}
DB::enableQueryLog(); DB::flushQueryLog();
$progress = DomainAdministration::progress($refused);
$queries = DB::getQueryLog();
DB::disableQueryLog();
$rows = DB::select($queries[0]['query'], $queries[0]['bindings']);
verify(count($queries) === 1 && count($rows) === 2 && array_keys((array) $rows[0]) === ['domain_id', 'safe_code']
    && $progress[$refused[0]->id]['safe_code'] === null && $progress[$refused[1]->id]['safe_code'] === 'transfer_code_timeout',
    'Refused-transfer progress fetched history, exposed extra columns or ignored the newest transfer outcome.');

echo 'Native order regressions: '.$checks.' passed; cancellation recovery, transfer precheck and bounded progress.'.PHP_EOL;
$successful = true;

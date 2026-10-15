<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Isolated SQLite memory only. Does not bootstrap Paymenter or load an .env.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php')) { fwrite(STDERR, "Pristine release vendor is required.\n"); exit(2); }
require $release.'/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'Paymenter\\Extensions\\Servers\\Enom\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root.'/Enom/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) { require $path; }
    }
});

use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

$app = new Application($release);
$app->instance('config', new Repository(['app' => ['key' => 'unused', 'cipher' => 'AES-256-CBC'], 'audit' => ['enabled' => false]]));
$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$db->setAsGlobal();
$db->bootEloquent();
Model::encryptUsing(new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
$schema = $db->schema();
$schema->create('extensions', function ($table) { $table->id(); $table->string('type'); $table->string('extension'); $table->softDeletes(); });
$schema->create('products', function ($table) { $table->id(); $table->unsignedBigInteger('server_id'); });
$schema->create('services', function ($table) { $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('product_id'); $table->date('expires_at')->nullable(); });
$schema->create('service_cancellations', function ($table) { $table->id(); $table->unsignedBigInteger('service_id'); $table->string('type'); });
$schema->create('invoices', function ($table) { $table->id(); $table->unsignedBigInteger('user_id'); $table->string('status'); $table->date('due_at')->nullable(); });
$schema->create('enom_domains', function ($table) { $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('service_id'); $table->unsignedBigInteger('server_id'); $table->string('domain'); $table->string('status'); $table->text('snapshot')->nullable(); $table->text('capabilities')->nullable(); $table->date('provider_expires_at')->nullable(); $table->dateTime('last_synced_at')->nullable(); });
$schema->create('enom_operations', function ($table) { $table->id(); $table->unsignedBigInteger('domain_id'); $table->unsignedBigInteger('service_id')->nullable(); $table->unsignedBigInteger('invoice_id')->nullable(); $table->string('action'); $table->string('state'); $table->timestamps(); });
$db->table('extensions')->insert(['id' => 1, 'type' => 'server', 'extension' => 'Enom']);
$db->table('products')->insert(['id' => 1, 'server_id' => 1]);
$db->table('services')->insert(['id' => 1, 'user_id' => 101, 'product_id' => 1, 'expires_at' => '2027-01-01']);
$db->table('enom_domains')->insert(['id' => 1, 'user_id' => 101, 'service_id' => 1, 'server_id' => 1, 'domain' => 'demo.example.test', 'status' => 'active', 'capabilities' => json_encode(['nameservers' => true])]);
$user = (new User)->forceFill(['id' => 101]);
$other = (new User)->forceFill(['id' => 202]);
$operations = new class extends DomainOperations {
    public array $requests = [];
    public function enqueueManagement(User $actor, int $domainId, string $action, array $payload, string $nonce): Operation {
        $this->requests[] = [$actor->id, $domainId, $action, $payload, $nonce];
        return (new Operation)->forceFill(['id' => 99, 'state' => 'queued']);
    }
};
$manager = new DomainManager($operations);
$count = 0;
function check(bool $condition, string $name): void { global $count; if (!$condition) { throw new RuntimeException('FAIL: '.$name); } $count++; }
function denied(callable $call, string $name, ?int $status = null): void {
    try { $call(); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) { check($status === null || $status === 404, $name); return; }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { check($status === null || $exception->getStatusCode() === $status, $name); return; }
    throw new RuntimeException('FAIL: '.$name);
}
check($manager->owned($user, 1)->id === 1, 'matching owner service server accepted');
denied(fn () => $manager->snapshot($other, 1), 'cross-owner snapshot hidden', 404);
$payload = ['confirmed' => '1', '_request_key' => str_repeat('a', 64), 'nameservers' => ['ns1.example.test', 'ns2.example.test']];
denied(fn () => $manager->request($other, 1, 'nameservers', $payload), 'cross-owner mutation hidden', 404);
check(count($operations->requests) === 0, 'denied owner never enqueues');
$manager->request($user, 1, 'nameservers', $payload);
check(count($operations->requests) === 1 && $operations->requests[0][4] === str_repeat('a', 64), 'validated request delegates nonce and owner');
try { $manager->request($user, 1, 'contacts', ['confirmed' => '1']); throw new RuntimeException('FAIL: capability'); }
catch (InvalidArgumentException) { check(count($operations->requests) === 1, 'unsupported capability cannot enqueue'); }
$db->table('services')->where('id', 1)->update(['user_id' => 202]);
denied(fn () => $manager->owned($user, 1), 'stale domain owner mapping hidden', 404);
$db->table('services')->where('id', 1)->update(['user_id' => 101]);
$db->table('enom_domains')->where('id', 1)->update(['server_id' => 2]);
denied(fn () => $manager->owned($user, 1), 'wrong server mapping hidden', 404);
$db->table('enom_domains')->where('id', 1)->update(['server_id' => 1]);
$db->table('extensions')->where('id', 1)->update(['extension' => 'Plesk']);
denied(fn () => $manager->owned($user, 1), 'other provider mapping hidden', 404);
$db->table('extensions')->where('id', 1)->update(['extension' => 'Enom']);
$db->table('enom_domains')->where('id', 1)->update(['snapshot' => (new Encrypter(str_repeat('x', 32), 'AES-256-CBC'))->encryptString(json_encode(['epp' => 'secret', 'contacts' => ['registrant' => ['email_address' => 'private@example.test']], 'contact_status' => ['pending_verification' => true, 'transfer_locked' => false, 'raw_error' => 'status-secret']]))]);
$snapshot = $manager->snapshot($user, 1);
check($snapshot['cached']['contacts']['registrant']['email_address'] === 'private@example.test' && !isset($snapshot['cached']['epp']), 'encrypted authorized cache exposes contact and excludes EPP');
check($snapshot['cached']['contact_status'] === ['pending_verification' => true, 'transfer_locked' => false], 'encrypted contact status retains only verified flags');
$request = Request::create('/domains/1', 'POST');
$request->setLaravelSession(new Store('ui-test', new ArraySessionHandler(60)));
$key = FormKeys::issue($request, 'domain:1:lock');
check(strlen($key) === 64 && $key === FormKeys::issue($request, 'domain:1:lock'), 'issued key stable for unsent form');
$request->request->set('_request_key', $key);
check(FormKeys::verify($request, 'domain:1:lock') === $key, 'issued key accepted');
denied(fn () => FormKeys::verify($request, 'domain:2:lock'), 'key bound to domain', 419);
FormKeys::consume($request, 'domain:1:lock');
denied(fn () => FormKeys::verify($request, 'domain:1:lock'), 'consumed key cannot replay form', 419);
check(FormKeys::issue($request, 'domain:1:lock') !== $key, 'fresh key for subsequent edit');
$request->session()->put('enom.form_keys', ['expired' => ['key' => $key, 'expires' => time() - 1]]);
denied(fn () => FormKeys::verify($request, 'expired'), 'expired key rejected', 419);
$nonstaff = new class extends User { public function hasPermission($permission): bool { return false; } };
$request->setUserResolver(fn () => $nonstaff);
$admin = new AdminController($operations);
denied(fn () => $admin->index($request), 'customer cannot view staff route', 403);
denied(fn () => $admin->reconcile($request, 1), 'customer cannot reconcile', 403);
denied(fn () => $admin->fund($request, 1), 'customer cannot approve funding', 403);
fwrite(STDOUT, "UI ownership and forms: {$count} passed\n");

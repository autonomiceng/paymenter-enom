<?php
// RuntimeGuard environment/credential/binding/live-pin rules and the credential-encrypting Setting listener.
// Disposable native app; no provider transport is ever reached.
$enomTestLabel = 'runtime-guard';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;

function enomServer(string $name, array $settings = []): Server {
    $server = Server::create(['name' => $name, 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
    foreach ($settings as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
    return $server->fresh();
}
function rawSetting(Server $server, string $key): ?object {
    return DB::table('settings')->where('settingable_type', $server->getMorphClass())->where('settingable_id', $server->id)->where('key', $key)->first();
}
function setSetting(Server $server, string $key, mixed $value): void {
    $server->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
}

// Setting::saving listener: native server Create saves credentials without the encrypted flag; Enom encrypts them before the first write.
$server = enomServer('Guarded Enom', ['uid' => '  Reseller-UID  ', 'token' => 'api-token', 'operations_enabled' => '1']);
foreach (['uid' => 'Reseller-UID', 'token' => 'api-token'] as $key => $plain) {
    $row = rawSetting($server, $key);
    verify((bool) $row->encrypted && $row->value !== $plain && !str_contains($row->value, $plain) && Crypt::decryptString($row->value) === $plain, $key.' was stored without encryption or untrimmed.');
    verify($server->settings->firstWhere('key', $key)->value === $plain, $key.' does not read back decrypted through the native model.');
}
verify(!(bool) rawSetting($server, 'operations_enabled')->encrypted, 'Non-credential setting was encrypted.');
// Re-saving an already encrypted credential through the native model keeps one layer of encryption.
$uidSetting = $server->settings()->where('key', 'uid')->sole();
$uidSetting->value = 'Reseller-UID'; $uidSetting->save();
verify(Crypt::decryptString(rawSetting($server, 'uid')->value) === 'Reseller-UID', 'Re-saved credential was double encrypted.');
// Explicitly flagged credentials (Edit server) are encrypted once by native code.
$flagged = Server::create(['name' => 'Flagged Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$flagged->settings()->create(['key' => 'uid', 'value' => 'flagged-uid', 'encrypted' => true]);
verify(Crypt::decryptString(rawSetting($flagged, 'uid')->value) === 'flagged-uid', 'Flagged credential was encrypted twice.');
// Other extensions, other keys, empty values and non-server settings are left alone.
$other = Server::create(['name' => 'Other panel', 'type' => 'server', 'extension' => 'Plesk', 'enabled' => true]);
$other->settings()->create(['key' => 'uid', 'value' => 'plesk-user']);
$other->settings()->create(['key' => 'token', 'value' => 'plesk-token']);
verify(!(bool) rawSetting($other, 'uid')->encrypted && rawSetting($other, 'uid')->value === 'plesk-user' && rawSetting($other, 'token')->value === 'plesk-token', 'Another extension\'s credentials were encrypted.');
$blank = enomServer('Blank Enom', ['uid' => '']);
verify(!(bool) rawSetting($blank, 'uid')->encrypted, 'Empty credential was encrypted.');
$global = Setting::create(['key' => 'uid', 'value' => 'global-value']);
verify(!(bool) DB::table('settings')->where('id', $global->id)->value('encrypted'), 'A non-server setting named uid was encrypted.');

// Credentials: present and encrypted, or every request refuses (reads included).
$configuration = RuntimeGuard::configuration($server->id);
verify($configuration['uid'] === 'Reseller-UID' && $configuration['token'] === 'api-token' && $configuration['environment'] === 'test' && $configuration['enabled'] === true, 'Valid configuration was not returned.');
refuses(fn () => RuntimeGuard::configuration($blank->id), 'account ID and API token', 'Blank credentials were accepted.');
refuses(fn () => RuntimeGuard::configuration($other->id), 'unavailable', 'A non-Enom server passed the Enom guard.');
$plain = enomServer('Plain Enom');
DB::table('settings')->insert([
    ['key' => 'uid', 'value' => 'plain-uid', 'type' => 'string', 'encrypted' => false, 'settingable_type' => $plain->getMorphClass(), 'settingable_id' => $plain->id, 'created_at' => now(), 'updated_at' => now()],
    ['key' => 'token', 'value' => 'plain-token', 'type' => 'string', 'encrypted' => false, 'settingable_type' => $plain->getMorphClass(), 'settingable_id' => $plain->id, 'created_at' => now(), 'updated_at' => now()],
]);
refuses(fn () => RuntimeGuard::assertProvider($plain->fresh()->id, true), 'account ID and API token', 'Unencrypted stored credentials were accepted for reads.');
$tokenless = enomServer('Tokenless Enom', ['uid' => 'only-uid']);
refuses(fn () => RuntimeGuard::assertProvider($tokenless->id, true), 'account ID and API token', 'Missing token was accepted.');

// Environment: missing means test; anything other than test/live refuses instead of falling back.
setSetting($server, 'environment', 'staging');
refuses(fn () => RuntimeGuard::configuration($server->id), 'Choose Test or Live', 'Unknown environment did not refuse.');
setSetting($server, 'environment', 'LIVE');
refuses(fn () => RuntimeGuard::configuration($server->id), 'Choose Test or Live', 'Environment value was case-folded instead of refused.');
setSetting($server, 'environment', 'test');
verify(RuntimeGuard::configuration($server->id)['environment'] === 'test' && RuntimeGuard::isTest($server->id), 'Explicit test environment not honoured.');
verify(RuntimeGuard::environment([]) === 'test' && RuntimeGuard::environment(['environment' => 'live']) === 'live' && RuntimeGuard::environment(['environment' => 'x']) === 'test', 'environment() mapping differs.');
verify(RuntimeGuard::endpoint('test') === 'https://resellertest.enom.com/interface.asp' && RuntimeGuard::endpoint('live') === 'https://reseller.enom.com/interface.asp', 'Endpoints differ.');
refuses(fn () => RuntimeGuard::endpoint('staging'), 'Unknown Enom environment', 'Unknown endpoint was returned.');

// Reads need credentials only; writes also need "Process orders and changes".
setSetting($server, 'operations_enabled', '0');
verify(RuntimeGuard::assertProvider($server->id, true)['uid'] === 'Reseller-UID', 'Reads were refused with processing off.');
refuses(fn () => RuntimeGuard::assertProvider($server->id), 'turned off', 'Writes were allowed with processing off.');
setSetting($server, 'operations_enabled', '1');
verify(RuntimeGuard::assertProvider($server->id)['operations_enabled'] == '1', 'Writes were refused with processing on.');

// Legacy 0.2 settings: sandbox=false without an environment meant "send nothing"; writes stay off until an environment is chosen.
$legacy = enomServer('Legacy Enom', ['uid' => 'legacy-uid', 'token' => 'legacy-token', 'operations_enabled' => '1', 'sandbox' => '0']);
verify(RuntimeGuard::assertProvider($legacy->id, true)['environment'] === 'test', 'Legacy reads were refused.');
// configuration() adds a normalized 'environment' key before assertProvider() calls legacyDisabled(), so the legacy check never fires.
try { RuntimeGuard::assertProvider($legacy->id); $legacyWrites = true; } catch (RuntimeException) { $legacyWrites = false; }
knownBug(!$legacyWrites, 'legacy-sandbox-false-writes', 'RuntimeGuard::assertProvider() checks legacyDisabled() on the normalized configuration, which always has environment; sandbox=false servers can still write.');
verify(RuntimeGuard::status($legacy->id)['processing'] === false, 'Setup status reports legacy-disabled processing as on.');
setSetting($legacy, 'sandbox', '1');
verify(RuntimeGuard::assertProvider($legacy->id)['environment'] === 'test', 'Legacy sandbox=true did not keep test writes.');
setSetting($legacy, 'sandbox', '0');
setSetting($legacy, 'environment', 'test');
verify(RuntimeGuard::assertProvider($legacy->id)['environment'] === 'test', 'Choosing an environment did not supersede the legacy flag.');

// Fingerprints: test keeps the historical sandbox: prefix; live never matches test; uid is case-insensitive.
verify(RuntimeGuard::accountFingerprint(['uid' => 'Reseller-UID']) === hash('sha256', 'sandbox:reseller-uid'), 'Test fingerprint changed from the 0.2 sandbox: form.');
verify(RuntimeGuard::accountFingerprint(['uid' => 'Reseller-UID', 'environment' => 'test']) === hash('sha256', 'sandbox:reseller-uid'), 'Explicit test fingerprint differs.');
verify(RuntimeGuard::accountFingerprint(['uid' => 'Reseller-UID', 'environment' => 'live']) === hash('sha256', 'live:reseller-uid'), 'Live fingerprint differs.');
verify(RuntimeGuard::serverFingerprint($server->fresh()) === hash('sha256', 'sandbox:reseller-uid') && RuntimeGuard::serverFingerprint(null) === 'missing-account'
    && RuntimeGuard::serverFingerprint($blank->fresh()) === 'missing-account', 'Server fingerprint differs.');
verify(Paymenter\Extensions\Servers\Enom\Services\RenewalAdmission::accountFingerprint(['uid' => 'Reseller-UID']) === RuntimeGuard::accountFingerprint(['uid' => 'Reseller-UID']), 'Renewal admission uses a different fingerprint.');

// Account binding: the first order records the account; a different account or environment refuses; token rotation is allowed.
RuntimeGuard::bindAccount($server->id);
$binding = rawSetting($server, RuntimeGuard::BINDING)->value;
verify($binding === hash('sha256', 'sandbox:reseller-uid') && RuntimeGuard::status($server->id)['bound'] === true, 'Binding was not recorded.');
setSetting($server, 'token', 'rotated-token');
verify(RuntimeGuard::configuration($server->id)['token'] === 'rotated-token', 'Token rotation was refused.');
setSetting($server, 'uid', 'RESELLER-uid');
verify(RuntimeGuard::configuration($server->id)['uid'] === 'RESELLER-uid', 'Case-only uid change broke the binding.');
setSetting($server, 'uid', 'another-account');
refuses(fn () => RuntimeGuard::configuration($server->id), 'account or environment changed', 'Different account passed the binding.');
refuses(fn () => RuntimeGuard::assertProvider($server->id, true), 'account or environment changed', 'Different account was allowed to read.');
verify(str_contains((string) RuntimeGuard::status($server->id)['problem'], 'account or environment changed'), 'Setup status hides the binding mismatch.');
RuntimeGuard::bindAccount($server->id);
verify(rawSetting($server, RuntimeGuard::BINDING)->value === $binding, 'bindAccount overwrote an existing binding.');
setSetting($server, 'uid', 'Reseller-UID');
config(['app.url' => 'http://enom-tests.invalid']);
RuntimeGuard::confirmLive($server->id);
setSetting($server, 'environment', 'live');
refuses(fn () => RuntimeGuard::configuration($server->id), 'account or environment changed', 'Switching a bound test server to live passed the binding.');
setSetting($server, 'environment', 'test');
verify(RuntimeGuard::configuration($server->id)['environment'] === 'test', 'Restoring the original settings did not clear the refusal.');

// Live site pin: live requests need enom_live_url to equal the running site's URL.
$live = enomServer('Live Enom', ['uid' => 'live-uid', 'token' => 'live-token', 'environment' => 'live', 'operations_enabled' => '1']);
refuses(fn () => RuntimeGuard::configuration($live->id), 'Live mode is not confirmed', 'Unconfirmed live server was allowed.');
verify(RuntimeGuard::status($live->id)['live_confirmed'] === false && RuntimeGuard::isTest($live->id) === false, 'Unconfirmed live status differs.');
config(['app.url' => 'https://billing.example.com/']);
RuntimeGuard::confirmLive($live->id);
verify(rawSetting($live, RuntimeGuard::LIVE_URL)->value === 'https://billing.example.com', 'Live URL pin not recorded without trailing slash.');
verify(RuntimeGuard::assertProvider($live->id)['environment'] === 'live' && RuntimeGuard::status($live->id)['live_confirmed'] === true, 'Confirmed live server was refused.');
config(['app.url' => 'https://staging.example.com']);
refuses(fn () => RuntimeGuard::assertProvider($live->id, true), 'Live mode is not confirmed', 'A copied database on another site sent live requests.');
config(['app.url' => 'https://billing.example.com']);
RuntimeGuard::bindAccount($live->id);
verify(rawSetting($live, RuntimeGuard::BINDING)->value === hash('sha256', 'live:live-uid'), 'Live binding differs.');
$status = RuntimeGuard::status($live->id);
verify(!str_contains(json_encode($status), 'live-uid') && !str_contains(json_encode($status), 'live-token') && $status['credentials'] === true && $status['environment'] === 'live', 'Setup status leaked credentials or misreports.');
// Test servers ignore the pin.
config(['app.url' => 'https://anything.example.com']);
verify(RuntimeGuard::configuration($server->id)['environment'] === 'test', 'Test server depended on the live pin.');
config(['app.url' => 'https://billing.example.com']);

// apiForServer builds a client for the configured environment, with the catalogue and existing-registration endings. Constructing never sends.
$operations = new DomainOperations;
Tld::create(['server_id' => $live->id, 'tld' => 'io', 'enabled' => false, 'pricing_mode' => 'fixed']);
Domain::create(['service_id' => 1, 'user_id' => 1, 'server_id' => $live->id, 'domain' => 'kept.co.uk', 'sld' => 'kept', 'tld' => 'co.uk', 'status' => 'active', 'capabilities' => []]);
$liveApi = $operations->apiForServer($live->id);
verify($liveApi->environment() === 'live' && !$liveApi->isReadOnly() && in_array('io', $liveApi->suffixes(), true) && in_array('co.uk', $liveApi->suffixes(), true), 'Live API environment or suffixes differ.');
verify($operations->apiForServer($server->id, true)->environment() === 'test' && $operations->apiForServer($server->id, true)->isReadOnly(), 'Test read-only API differs.');
// The per-request guard re-reads configuration: a changed account or environment stops the request before any transport.
setSetting($live, 'token', 'changed-token');
verify($liveApi->balance()->code === 'guard_refused', 'Changed token after construction was not refused by the request guard.');
setSetting($live, 'token', 'live-token');
setSetting($live, 'operations_enabled', '0');
verify($liveApi->balance()->code === 'guard_refused', 'Processing switched off after construction did not stop a write-capable client.');
refuses(fn () => $operations->apiForServer($live->id), 'turned off', 'Write client built with processing off.');

echo 'Runtime guard: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; credential encryption, environment, binding and live pin; no provider transport.'.PHP_EOL;
$successful = true;

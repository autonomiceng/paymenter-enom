<?php
/**
 * Shared disposable native app for 0.3 tests: stock Paymenter 1.5.9 code, a copy of Enom/, an in-memory
 * SQLite database, the extension installed and booted. Never loads an installed app, .env or credentials.
 * Include at top level; it defines $repo, $release, $testRoot, $app, $extension, $checks, $successful and verify().
 */
require_once __DIR__.'/paymenter-test-app.php';

$repo = dirname(__DIR__);
$successful = false;
$testRoot = null;
paymenterTestShutdown($testRoot, $successful);
$release = paymenterTestApp();
$testRoot = $repo.'/.scratch/enom-native-'.($enomTestLabel ?? 'test').'-'.bin2hex(random_bytes(5));
mkdir($testRoot, 0700, true);
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) { mkdir($testRoot.'/'.$path, 0700, true); }
foreach (['config', 'database', 'routes', 'vendor', 'themes', 'resources', 'public'] as $path) { symlink($release.'/'.$path, $testRoot.'/'.$path); }
mkdir($testRoot.'/app', 0700);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($release.'/app', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $target = $testRoot.'/app/'.substr($file->getPathname(), strlen($release.'/app/'));
    if ($file->isDir()) { mkdir($target, 0700, true); } else { copy($file->getPathname(), $target); }
}
foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $file) { copy($release.'/'.$file, $testRoot.'/'.$file); }
$extensionRoot = $testRoot.'/extensions/Servers/Enom';
mkdir($extensionRoot, 0700, true);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/Enom', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $target = $extensionRoot.'/'.substr($file->getPathname(), strlen($repo.'/Enom/'));
    if ($file->isDir()) { mkdir($target, 0700, true); } else { copy($file->getPathname(), $target); }
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'APP_URL' => 'http://enom-tests.invalid',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'APP_TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$loader = require $release.'/vendor/autoload.php';
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $extensionRoot.'/', true);
$app = require $testRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['audit.enabled' => false, 'settings.mail_disabled' => true, 'settings.default_currency' => 'USD', 'settings.tax_enabled' => false,
    'settings.theme' => 'default', 'app.version' => 'development']);
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') { throw new RuntimeException('Test database isolation failed.'); }
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$extension = new Paymenter\Extensions\Servers\Enom\Enom;
$extension->installed();
$extension->boot();
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
/** Run $action, require a refusal of one of $types whose message contains $needle, and return the message. */
function refuses(callable $action, string $needle, string $message, array $types = [RuntimeException::class, InvalidArgumentException::class]): string {
    try { $action(); }
    catch (Throwable $error) {
        $matched = false;
        foreach ($types as $type) { $matched = $matched || $error instanceof $type; }
        verify($matched && str_contains($error->getMessage(), $needle), $message.' (got '.get_class($error).': '.$error->getMessage().')');
        return $error->getMessage();
    }
    throw new RuntimeException($message.' (no refusal)');
}
/**
 * Count passing checks. Record known failures in the summary and print them to stderr
 * without stopping the suite. Passing checks produce no warning.
 */
$knownBugs = [];
function knownBug(bool $intended, string $id, string $description): void {
    global $knownBugs, $checks;
    if ($intended) { $checks++; return; }
    $knownBugs[] = $id;
    fwrite(STDERR, 'KNOWN RUNTIME BUG '.$id.': '.$description.PHP_EOL);
}
/** Enom XML envelope for an injected transport. */
function enomXml(string $command, string $body): string {
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
}
/** Rows of the given tables, for unchanged-history assertions. */
function tableRows(array $tables): array {
    $rows = [];
    foreach ($tables as $table) { $rows[$table] = Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(); }
    return $rows;
}
/** Queued jobs of one class. */
function queuedJobs(string $class): array {
    return Illuminate\Support\Facades\DB::table('jobs')->get()->filter(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === $class)->values()->all();
}

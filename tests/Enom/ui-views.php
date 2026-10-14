<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Compile against the pristine native theme, without application/DB/provider bootstrap.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php')) { fwrite(STDERR, "Pristine release vendor is required.\n"); exit(2); }
require $release.'/vendor/autoload.php';
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;

$cache = sys_get_temp_dir().'/enom-ui-views-'.bin2hex(random_bytes(8));
mkdir($cache, 0700);
$app = new Application($release);
$app->instance('config', new Repository(['view' => ['paths' => [$release.'/themes/default/views'], 'compiled' => $cache], 'app' => ['namespace' => 'App\\']]));
$app->instance('events', new Dispatcher($app));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Facade::setFacadeApplication($app);
$app->register(ViewServiceProvider::class);
$app['view']->addNamespace('enom', $root.'/Enom/resources/views');
$app['view']->addNamespace('filament-panels', $release.'/vendor/filament/filament/resources/views');
$app['view']->addNamespace('filament', $release.'/vendor/filament/support/resources/views');
$count = 0;
try {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/Enom/resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!str_ends_with($file->getFilename(), '.blade.php')) { continue; }
        $compiled = $app['blade.compiler']->compileString(file_get_contents($file->getPathname()));
        $target = $cache.'/check-'.$count.'.php';
        file_put_contents($target, $compiled);
        $output = []; $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($target).' 2>&1', $output, $status);
        if ($status !== 0) { throw new RuntimeException('Invalid compiled view '.$file->getFilename().': '.implode(' ', $output)); }
        $count++;
    }
    fwrite(STDOUT, "UI native Blade compilation: {$count} passed\n");
} finally {
    foreach (glob($cache.'/*') as $file) { unlink($file); }
    rmdir($cache);
}

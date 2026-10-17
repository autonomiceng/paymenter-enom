<?php
/** Resolve a caller-provided pristine development dependency without loading an installed app. */
declare(strict_types=1);

function paymenterTestApp(): string
{
    $requested = rtrim((string) getenv('PAYMENTER_TEST_APP'), '/');
    $path = realpath($requested);
    if ($requested === '' || $path === false || $path !== $requested || str_starts_with($path, '/var/www/')
        || file_exists($path.'/.env') || is_link($path.'/.env') || file_exists($path.'/bootstrap/cache/config.php')
        || !is_file($path.'/vendor/autoload.php') || !is_file($path.'/bootstrap/app.php')
        || !is_dir($path.'/themes/default/views')) {
        throw new RuntimeException('PAYMENTER_TEST_APP must be a canonical pristine development release with vendor, no .env and no cached configuration.');
    }
    $composerSource = file_get_contents($path.'/composer.json');
    if ($composerSource === false) { throw new RuntimeException('The dependency composer.json could not be read.'); }
    $composer = json_decode($composerSource, true, 32, JSON_THROW_ON_ERROR);
    if (($composer['name'] ?? null) !== 'paymenter/paymenter') {
        throw new RuntimeException('A pristine Paymenter release is required.');
    }
    $config = $path.'/config/app.php';
    $source = '';
    if (is_file($config)) {
        $configSource = file_get_contents($config);
        if ($configSource === false) { throw new RuntimeException('The dependency config/app.php could not be read.'); }
        foreach (token_get_all($configSource) as $token) {
            $source .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
        }
    }
    if (!is_file($config) || preg_match_all('/[\'"]version[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $versions) !== 1
        || $versions[1][0] !== '1.5.9') {
        throw new RuntimeException('These native harnesses require the tested Paymenter 1.5.9 release.');
    }
    return $path;
}

/** Remove a disposable app without following links into its dependency. */
function paymenterRemoveTestRoot(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        paymenterRemoveTestRoot($entry->getPathname());
    }
    rmdir($path);
}

/** Clean up before setting the failure exit code, which stops later shutdown handlers. */
function paymenterTestShutdown(?string &$testRoot, bool &$successful): void
{
    register_shutdown_function(function () use (&$testRoot, &$successful): void {
        if ($testRoot !== null) { paymenterRemoveTestRoot($testRoot); }
        if (!$successful) { exit(1); }
    });
}

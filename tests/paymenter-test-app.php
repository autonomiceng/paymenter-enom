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
    $composer = json_decode(file_get_contents($path.'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($composer['name'] ?? null) !== 'paymenter/paymenter') {
        throw new RuntimeException('A pristine Paymenter release is required.');
    }
    $config = $path.'/config/app.php';
    if (!is_file($config) || preg_match_all('/[\'"]version[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($config), $versions) !== 1
        || $versions[1][0] !== '1.5.9') {
        throw new RuntimeException('These native harnesses require the tested Paymenter 1.5.9 release.');
    }
    return $path;
}

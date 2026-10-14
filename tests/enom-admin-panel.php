<?php
/**
 * Register the extension's Filament resources and pages on the native admin panel of an already booted
 * disposable app, the way Paymenter's AdminPanelProvider discovers them for an installed extension.
 * Panel discovery runs at boot, before the test migrates and installs the extension, so tests add them here.
 *
 * The local test PHP may lack the intl extension that Filament's pagination numbers need; a one-line
 * pagination view stands in for it. Nothing else in Filament or the extension is replaced.
 */
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

function enomAdminPanel(): \Filament\Panel
{
    static $panel = null;
    if ($panel) { return $panel; }
    $namespace = 'Paymenter\\Extensions\\Servers\\Enom\\Admin\\';
    $resources = [$namespace.'Resources\\DomainResource', $namespace.'Resources\\OperationResource', $namespace.'Resources\\TldResource'];
    $pages = [$namespace.'Pages\\EnomSetup'];
    $panel = Filament::getPanel('admin');
    $panel->resources($resources)->pages($pages);
    Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(function () use ($panel, $resources, $pages) {
        Route::middleware($panel->getAuthMiddleware())->group(function () use ($panel, $resources, $pages) {
            foreach ($resources as $resource) { $resource::registerRoutes($panel); }
            foreach ($pages as $page) { $page::registerRoutes($panel); }
        });
    });
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
    Filament::setCurrentPanel($panel);
    if (!extension_loaded('intl')) {
        $stub = $GLOBALS['testRoot'].'/filament-stub';
        @mkdir($stub.'/components/pagination', 0700, true);
        file_put_contents($stub.'/components/pagination/index.blade.php', '<nav data-test-pagination></nav>');
        app('view')->prependNamespace('filament', $stub);
    }
    return $panel;
}

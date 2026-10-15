<?php
/**
 * Render the client domain page through the real DomainController::show() (the same data the route passes)
 * and list its POST form targets. Include after a disposable app is booted and the extension routes exist.
 * Native tests assert which actions the page offers by route, not by wording; wording belongs to the UI suite.
 */
function enomDomainPage(App\Models\User $user, int $domainId): string {
    global $app, $release, $testRoot;
    static $prepared = false;
    if (!$prepared) {
        require_once $testRoot.'/app/Classes/helpers.php';
        $app['view']->addLocation($release.'/themes/default/views');
        app('router')->getRoutes()->refreshNameLookups();
        $prepared = true;
    }
    $app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
    Illuminate\Support\Facades\Auth::login($user);
    $request = Illuminate\Http\Request::create('http://enom-tests.invalid/domains/'.$domainId, 'GET');
    $session = new Illuminate\Session\Store('enom-domain-page', new Illuminate\Session\ArraySessionHandler(60));
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    $app['url']->setRequest($request);
    $controller = $app->make(Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class);
    return $controller->show($request, $domainId)->render();
}

/** @return list<string> POST form action paths, e.g. /domains/5/manage/lock */
function enomFormActions(string $html): array {
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $actions = [];
    foreach ((new DOMXPath($document))->query('//form[translate(@method, "post", "POST")="POST"]') as $form) {
        $actions[] = (string) parse_url($form->getAttribute('action'), PHP_URL_PATH);
    }
    return $actions;
}

<?php
/**
 * Real HTTP requests through the native kernel and middleware of a disposable app (tests/enom-native-app.php):
 * sessions, Paymenter's UserSession login, auth, email verification, throttles, session blocks and CSRF.
 * Each user keeps a cookie jar, so session-bound form keys and flashed errors carry over like in a browser.
 * Include after enom-native-app.php.
 */
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

require_once $testRoot.'/app/Classes/helpers.php';
$app['view']->addLocation($release.'/themes/default/views');
// Native code reloads config('settings') from the settings table after a global setting is saved (for example the
// invoice number); store the test settings there too so the theme and mail switches survive the reload.
foreach (['theme' => 'default', 'mail_must_verify' => '0', 'default_currency' => 'USD', 'tax_enabled' => '0'] as $key => $value) {
    App\Models\Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
}
Illuminate\Support\Facades\Cache::forget('settings');
// A login landing route: ResolveUserSession turns the remember cookie into a session login here.
$app['router']->get('/__enom-test-login', fn () => response('', 204))->middleware('web');
app('router')->getRoutes()->refreshNameLookups();
app('router')->getRoutes()->refreshActionLookups();
$enomHttpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$enomCookieJars = [];

/**
 * Send one request. $user null means a guest. $csrf true runs the CSRF middleware as in production
 * (the testing environment otherwise skips it) and sends the session's XSRF token unless $token is false.
 * $options['ip'] sets the client address (per-IP limits); $options['jar'] names a separate guest cookie jar.
 */
function enomHttp(?User $user, string $method, string $uri, array $data = [], array $options = []): Response
{
    global $app, $enomHttpKernel, $enomCookieJars;
    $jar = $user ? enomLogin($user) : ($options['jar'] ?? 'guest');
    $server = ['REMOTE_ADDR' => $options['ip'] ?? '203.0.113.10', 'HTTP_USER_AGENT' => 'enom-tests'];
    foreach ($options['headers'] ?? [] as $name => $value) { $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value; }
    $cookies = $enomCookieJars[$jar] ?? [];
    if (($options['csrf'] ?? false) && ($options['token'] ?? true) && isset($cookies['XSRF-TOKEN'])) { $server['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN']; }
    $content = null;
    if (($options['json'] ?? false) === true) {
        $server['CONTENT_TYPE'] = 'application/json'; $server['HTTP_ACCEPT'] = 'application/json';
        $content = json_encode($data); $data = [];
    }
    $request = Request::create(str_starts_with($uri, 'http') ? $uri : 'http://enom-tests.invalid'.$uri, $method, $data, $cookies, [], $server, $content);
    enomFreshProcess();
    $environment = $app['env'];
    if ($options['csrf'] ?? false) { $app['env'] = 'production'; }
    try { $response = $enomHttpKernel->handle($request); }
    finally { $app['env'] = $environment; }
    foreach ($response->headers->getCookies() as $cookie) {
        // Expiry is compared with the real clock, which differs from the frozen test clock; only an emptied cookie is removed.
        if ($cookie->getValue() === null || $cookie->getValue() === '') { unset($enomCookieJars[$jar][$cookie->getName()]); } else { $enomCookieJars[$jar][$cookie->getName()] = $cookie->getValue(); }
    }
    $enomHttpKernel->terminate($request, $response);
    $app['auth']->forgetGuards();
    return $response;
}

/**
 * Forget per-request state a PHP-FPM worker would not carry over: the resolved guard user and the in-memory
 * attributes of the shared session store (its handler keeps each session's saved data by id).
 */
function enomFreshProcess(): void
{
    global $app;
    $app['auth']->forgetGuards();
    $store = $app['session']->driver();
    $store->flush();
    $store->setId(null);
}

/** Log a user in once through Paymenter's own session middleware; returns the cookie jar key. */
function enomLogin(User $user): string
{
    global $app, $enomHttpKernel, $enomCookieJars;
    $jar = 'user-'.$user->id;
    if (isset($enomCookieJars[$jar])) { return $jar; }
    $token = UserSession::create(['user_id' => $user->id, 'expires_at' => now()->addDays(30), 'last_activity' => now()]);
    $remember = $app['encrypter']->encrypt(CookieValuePrefix::create('paymenter_remember', $app['encrypter']->getKey()).$token->ulid, false);
    $request = Request::create('http://enom-tests.invalid/__enom-test-login', 'GET', [], ['paymenter_remember' => $remember], [], ['REMOTE_ADDR' => '203.0.113.10']);
    enomFreshProcess();
    $response = $enomHttpKernel->handle($request);
    $enomCookieJars[$jar] = [];
    foreach ($response->headers->getCookies() as $cookie) { $enomCookieJars[$jar][$cookie->getName()] = $cookie->getValue(); }
    $enomHttpKernel->terminate($request, $response);
    if ($response->getStatusCode() !== 204 || !isset($enomCookieJars[$jar]['paymenter_session'])) { throw new RuntimeException('Test login failed.'); }
    return $jar;
}

/** Forget a user's cookies (a new browser). */
function enomLogout(User $user): void { global $enomCookieJars; unset($enomCookieJars['user-'.$user->id]); }

/** Path of a redirect target, or null. */
function enomRedirectPath(Response $response): ?string
{
    return $response->isRedirection() ? (string) parse_url($response->headers->get('Location'), PHP_URL_PATH) : null;
}

function enomDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    return new DOMXPath($document);
}

/**
 * Hidden and filled fields of the first POST form whose action path matches, as a browser would submit them
 * (unchecked boxes and disabled fields excluded; textareas and selected options included).
 */
function enomFormFields(string $html, string $actionPath, int $index = 0): array
{
    $xpath = enomDocument($html);
    $forms = [];
    foreach ($xpath->query('//form') as $form) {
        if (strtoupper($form->getAttribute('method')) === 'POST' && parse_url($form->getAttribute('action'), PHP_URL_PATH) === $actionPath) { $forms[] = $form; }
    }
    if (!isset($forms[$index])) { throw new RuntimeException('No form posts to '.$actionPath.' (called from line '.(debug_backtrace()[0]['line'] ?? '?').')'); }
    $fields = [];
    foreach ($xpath->query('.//input|.//textarea|.//select', $forms[$index]) as $field) {
        $name = $field->getAttribute('name');
        if ($name === '' || $field->hasAttribute('disabled')) { continue; }
        $type = strtolower($field->getAttribute('type'));
        if (in_array($type, ['checkbox', 'radio'], true) && !$field->hasAttribute('checked')) { continue; }
        if ($field->nodeName === 'textarea') { $value = $field->textContent; }
        elseif ($field->nodeName === 'select') {
            $value = null;
            foreach ($xpath->query('.//option', $field) as $option) {
                if ($option->hasAttribute('selected')) { $value = $option->getAttribute('value'); break; }
                $value ??= $option->getAttribute('value'); // a browser submits the first option when none is selected
            }
            $value ??= '';
        } else { $value = $field->getAttribute('value'); }
        parse_str(rawurlencode($name).'='.rawurlencode($value), $pair);
        $fields = array_replace_recursive($fields, $pair);
    }
    return $fields;
}

/** Start a new rate-limit minute for every route bucket used by this user. */
function enomResetThrottle(User $user): void
{
    $signature = sha1((string) $user->getAuthIdentifier());
    $limiter = app(Illuminate\Cache\RateLimiter::class);
    $limiter->clear($signature);
    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (!is_string($middleware) || !str_starts_with($middleware, 'throttle:')) { continue; }
            $parts = explode(',', substr($middleware, strlen('throttle:')));
            if (isset($parts[2])) { $limiter->clear($parts[2].$signature); }
        }
    }
}

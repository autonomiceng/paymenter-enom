<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Reuse isolated native controller/session fixture; add an actual native routed POST pipeline.
require __DIR__.'/ui-controller-regressions.php';
$db->table('enom_order_intents')->where('id', $intent)->update(['action' => 'register', 'expires_at' => date('Y-m-d H:i:s', time() + 900)]);
$app->instance(Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class, $controller);
$router = new Illuminate\Routing\Router($app['events'], $app);
$router->post('/domains/order', [Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class, 'place'])->middleware([
    Illuminate\Foundation\Http\Middleware\TrimStrings::class,
    Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
]);
$raw = ['first_name' => " \t".str_repeat('A', 255)."\u{00A0}\0", 'last_name' => "\u{00A0}", 'address1' => 'Example Road', 'city' => 'Test', 'postal_code' => '12345', 'country' => "\u{200B}US\u{200B}", 'email_address' => 'demo@example.test', 'phone' => ' +1.5552345678 ', 'organization_name' => ''];
$request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $raw]);
$placements = $orders->placements;
$before = $db->table('enom_order_intents')->get()->toJson();
$response = $router->dispatch($request);
check(parse_url($response->getTargetUrl(), PHP_URL_PATH) === '/domains/order/'.$intent, 'Actual routed invalid POST retains order route');
check($request->input('contact.first_name') === str_repeat('A', 255), 'Native trim removes padded tabs/NUL/NBSP before byte limit');
check($request->input('contact.last_name') === null && $request->input('contact.organization_name') === null, 'Native empty conversion follows Unicode trim');
check($request->input('contact.country') === 'US', 'Native invisible characters removed at country boundaries');
check($request->session()->get('errors')->keys() === ['contact.last_name'], 'Only effectively empty required field rejected after native pipeline');
check($orders->placements === $placements && $db->table('enom_order_intents')->get()->toJson() === $before, 'Routed invalid input writes no order or invoice and reaches no provider');
fwrite(STDOUT, "Live validation native routed pipeline: 6 passed\n");

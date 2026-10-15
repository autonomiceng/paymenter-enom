<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Exercise the authoritative native middleware and contact validator without app bootstrap.
$root = dirname(__DIR__, 2);
require paymenterTestApp().'/vendor/autoload.php';
require $root.'/Enom/Services/DomainManager.php';
require $root.'/Enom/Services/InputValidationException.php';
$app = new Illuminate\Foundation\Application(paymenterTestApp());
$native = require $app->basePath('config/app.php');
$app->instance('config', new Illuminate\Config\Repository(['app' => ['countries' => $native['countries']]]));
$cases = [];
foreach (['US', 'CA', 'us', 'GB', '', 'United States'] as $country) {
    foreach (['+1 (555) 123-4567', '555-123-4567', '+12125550123', '12125550123', '(555) 234-5678', '+44 20 7946 0123', '+447946012345', '+1234.5678', '+1.123', '+1.12345678901234', '+12.12345678901234', '+1.123456789012345', '+1.123.4567', '５５５２３４５６７８', '٥٥٥٢٣٤٥٦٧٨', " \t+1.5552345678\u{00A0}", "+1.555234\t5678"] as $phone) {
        $cases[] = ['field' => 'phone', 'country' => $country, 'value' => $phone];
    }
}
foreach ([str_repeat('a', 255), str_repeat('a', 256), str_repeat('é', 128), str_repeat('😀', 63).'aaa', str_repeat('😀', 64), str_repeat('a', 254).'é', "A\tB", "A\x7fB", "A\u{0085}B", "A\u{009F}B", "A\u{2028}B", '   ', "\u{00A0}", "\u{200B}", " \t".str_repeat('a', 255)."\0\u{00A0}", "\u{1D173}A\u{1D173}"] as $value) {
    $cases[] = ['field' => 'first_name', 'country' => 'US', 'value' => $value];
}
foreach ($cases as &$case) {
    $contact = ['first_name' => 'Demo', 'last_name' => 'Customer', 'address1' => '123 Example Road', 'city' => 'Test', 'postal_code' => '12345', 'country' => $case['country'], 'email_address' => 'demo@example.test', 'phone' => '+1.5552345678'];
    $contact[$case['field']] = $case['value'];
    $request = Illuminate\Http\Request::create('/domains/order', 'POST', ['confirmed' => '1', 'contact_type' => 'registrant', 'contact' => $contact]);
    (new Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($request, fn ($request) => (new Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($request, fn () => null));
    try { Paymenter\Extensions\Servers\Enom\Services\DomainManager::validatePayload('contacts', $request->all()); $errors = []; }
    catch (Paymenter\Extensions\Servers\Enom\Services\InputValidationException $exception) { $errors = $exception->errors; }
    $case['rejects'] = array_key_exists('contact.'.$case['field'], $errors);
}
unset($case);
echo json_encode($cases, JSON_THROW_ON_ERROR);

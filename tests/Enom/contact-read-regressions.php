<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Observed GetContacts role shape, sanitized values; injected transport only, no provider calls.
foreach (['DomainName', 'RegistrarResult', 'RegistrarClient', 'EnomApi'] as $class) {
    require_once __DIR__.'/../../Enom/Api/'.$class.'.php';
}

use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
$body = '<GetContacts>';
foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling', 'Billing'] as $role) {
    $body .= '<'.$role.'>';
    foreach (['FirstName', 'LastName', 'OrganizationName', 'Address1', 'Address2', 'City', 'StateProvince', 'PostalCode', 'Country', 'Phone', 'PhoneExt', 'EmailAddress'] as $field) {
        $value = $role === 'Registrant' ? 'synthetic-registrant' : ($role === 'Billing' ? 'private-account-contact' : '');
        $body .= '<'.$role.$field.'>'.$value.'</'.$role.$field.'>';
    }
    $body .= '<'.$role.'StateProvinceChoice>S</'.$role.'StateProvinceChoice></'.$role.'>';
}
$body .= '<IsContactShared>False</IsContactShared></GetContacts>';
$response = '<interface-response><Command>GetContacts</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
$requests = [];
$api = new EnomApi(new RegistrarClient('synthetic', 'synthetic', function ($command, $parameters) use (&$requests, $response) {
    $requests[] = [$command, $parameters];
    return $response;
}));
$result = $api->contacts('demo.com');
$normalized = EnomApi::normalize('contacts', $result);
check($requests === [['GetContacts', ['SLD' => 'demo', 'TLD' => 'com']]], 'Documented GetContacts reads all roles without an invented ContactType parameter');
check($normalized['status'] === 'success' && $normalized['contacts']['registrant']['first_name'] === 'synthetic-registrant', 'Successful registrar contact read retains populated registrant');
foreach (['admin', 'tech', 'auxbilling'] as $role) {
    check($normalized['contacts'][$role]['first_name'] === '' && $normalized['contacts'][$role]['email_address'] === '', 'Explicitly empty '.$role.' fields remain empty rather than inferred from another role');
}
check(!str_contains(json_encode($normalized), 'private-account-contact') && !isset($normalized['contacts']['billing']), 'Account Billing contact is never exposed or mapped into domain AuxBilling');
fwrite(STDOUT, "Contact read regressions: {$checks} passed; injected transport only.\n");

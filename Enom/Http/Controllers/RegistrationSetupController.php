<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup;

final class RegistrationSetupController
{
    public function __invoke(Request $request, int $domain)
    {
        app(DomainManager::class)->owned($request->user(), $domain);
        $context = 'domain:'.$domain.':registration-contacts';
        FormKeys::verify($request, $context);
        $redirect = redirect()->route('enom.domains.show', $domain);
        try {
            RegistrationSetup::replaceContacts($request->user(), $domain);
            FormKeys::consume($request, $context);

            return $redirect->with('enom_status', 'Account details saved. Any required order review remains in place.');
        } catch (InputValidationException $error) {
            return $redirect->withErrors($error->errors);
        } catch (InvalidArgumentException) {
            return $redirect->withErrors(['registration_contacts' => 'Account details cannot be replaced for this order.']);
        }
    }
}

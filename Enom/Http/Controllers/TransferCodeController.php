<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\TransferOrders;
use Throwable;

class TransferCodeController
{
    public function store(Request $request, int $domain)
    {
        // Accept the secret only in the POST body, never in a query string or retained input.
        $code = $request->isJson() ? $request->json('transfer_code') : ($request->request->all()['transfer_code'] ?? null);
        $request->request->remove('transfer_code');
        $request->query->remove('transfer_code');
        if ($request->isJson()) { $request->json()->remove('transfer_code'); }
        $request->session()->forget('_old_input.transfer_code');
        Domain::whereKey($domain)->where('user_id', $request->user()->id)->firstOrFail();
        $context = 'domain:'.$domain.':transfer_code';
        FormKeys::verify($request, $context);
        try {
            if (!is_string($code)) { throw new InputValidationException(['transfer_code' => 'Enter the transfer code.']); }
            app(TransferOrders::class)->submitCode($request->user(), $domain, $code);
            FormKeys::consume($request, $context);
            return redirect()->route('enom.domains.show', $domain)->with('enom_status', 'Code received. The transfer starts once payment and your details are confirmed.');
        } catch (InputValidationException $error) {
            return redirect()->route('enom.domains.show', $domain)->withErrors($error->errors);
        } catch (Throwable) {
            return redirect()->route('enom.domains.show', $domain)->withErrors(['transfer_code' => "We couldn't save the code. Try again."]);
        }
    }
}

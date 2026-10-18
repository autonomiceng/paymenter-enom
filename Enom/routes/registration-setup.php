<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\RegistrationSetupController;

Route::middleware(['web', 'auth', MustVerfiyEmail::class])->group(function () {
    Route::post('/domains/{domain}/registration-contacts', RegistrationSetupController::class)
        ->whereNumber('domain')->name('enom.domains.registration-contacts')
        ->middleware('throttle:10,1,enom.domains.registration-contacts')->block(10, 10);
});

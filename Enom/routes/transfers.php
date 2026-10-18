<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\TransferCodeController;

Route::middleware(['web', 'auth', MustVerfiyEmail::class])->group(function () {
    Route::post('/domains/{domain}/transfer-code', [TransferCodeController::class, 'store'])
        ->whereNumber('domain')->name('enom.domains.transfer-code')->middleware('throttle:10,1,enom.domains.transfer-code')->block(10, 10);
});

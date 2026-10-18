<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\HelpController;

// Public: search, availability and the price list work without an account. Ordering needs a login.
Route::middleware(['web'])->group(function () {
    Route::get('/domains/register', [DomainController::class, 'search'])->name('enom.domains.search');
    // Alias kept for links that use the design name. The canonical page is /domains/register.
    Route::get('/domains/search', [DomainController::class, 'searchAlias'])->name('enom.domains.search.alias');
    Route::get('/domains/pricing', [DomainController::class, 'pricing'])->name('enom.domains.pricing');
    // Availability reads, one name per request. No session lock, so a search can check several endings at once.
    // Signed-in clients get 60 a minute each; guests also share a 30-a-minute limit per IP inside the controller.
    Route::post('/domains/check', [DomainController::class, 'check'])->name('enom.domains.check')->middleware('throttle:60,1,enom.domains.check');
});

Route::middleware(['web', 'auth', MustVerfiyEmail::class])->group(function () {
    // Client area.
    Route::get('/domains', [DomainController::class, 'index'])->name('enom.domains.index');
    Route::get('/domains/help', [HelpController::class, 'customer'])->name('enom.help');
    Route::get('/domains/transfer', [DomainController::class, 'transfer'])->name('enom.domains.transfer');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->whereNumber('domain')->name('enom.domains.show');
    Route::post('/domains/{domain}/refresh', [DomainController::class, 'refresh'])->whereNumber('domain')->name('enom.domains.refresh')->middleware('throttle:10,1,enom.domains.refresh')->block(10, 10);
    Route::post('/domains/{domain}/renew', [DomainController::class, 'renew'])->whereNumber('domain')->name('enom.domains.renew')->middleware('throttle:10,1,enom.domains.renew')->block(10, 10);
    Route::post('/domains/{domain}/privacy/{action}', [DomainController::class, 'privacy'])->whereNumber('domain')->where('action', 'purchase|renew')->name('enom.domains.privacy')->middleware('throttle:10,1,enom.domains.privacy')->block(10, 10);
    Route::post('/domains/{domain}/renewal-preference', [DomainController::class, 'renewalPreference'])->whereNumber('domain')->name('enom.domains.preference')->middleware('throttle:10,1,enom.domains.preference')->block(10, 10);
    Route::post('/domains/{domain}/manage/{action}', [DomainController::class, 'manage'])->whereNumber('domain')->name('enom.domains.manage')->middleware('throttle:20,1,enom.domains.manage')->block(10, 10);

    // Staff. Route names and controller methods stay stable for the Filament redirects.
});

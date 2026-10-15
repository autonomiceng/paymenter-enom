<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\HelpController;

Route::middleware(['web', 'auth', MustVerfiyEmail::class])->group(function () {
    // Client area.
    Route::get('/domains', [DomainController::class, 'index'])->name('enom.domains.index');
    Route::get('/domains/help', [HelpController::class, 'customer'])->name('enom.help');
    Route::get('/domains/register', [DomainController::class, 'search'])->name('enom.domains.search');
    Route::get('/domains/transfer', [DomainController::class, 'transfer'])->name('enom.domains.transfer');
    // Availability reads, one name per request. No session lock, so a search can check several endings at once.
    Route::post('/domains/check', [DomainController::class, 'check'])->name('enom.domains.check')->middleware('throttle:60,1,enom.domains.check');
    Route::post('/domains/quote', [DomainController::class, 'quote'])->name('enom.domains.quote')->middleware('throttle:20,1,enom.domains.quote')->block(10, 10);
    Route::post('/domains/order', [DomainController::class, 'place'])->name('enom.domains.place')->middleware('throttle:10,1,enom.domains.place')->block(10, 10);
    Route::get('/domains/order/{intent}', [DomainController::class, 'order'])->where('intent', '[a-f0-9]{48}')->name('enom.domains.order');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->whereNumber('domain')->name('enom.domains.show');
    Route::post('/domains/{domain}/refresh', [DomainController::class, 'refresh'])->whereNumber('domain')->name('enom.domains.refresh')->middleware('throttle:10,1,enom.domains.refresh')->block(10, 10);
    Route::post('/domains/{domain}/renew', [DomainController::class, 'renew'])->whereNumber('domain')->name('enom.domains.renew')->middleware('throttle:10,1,enom.domains.renew')->block(10, 10);
    Route::post('/domains/{domain}/privacy/{action}', [DomainController::class, 'privacy'])->whereNumber('domain')->where('action', 'purchase|renew')->name('enom.domains.privacy')->middleware('throttle:10,1,enom.domains.privacy')->block(10, 10);
    Route::post('/domains/{domain}/renewal-preference', [DomainController::class, 'renewalPreference'])->whereNumber('domain')->name('enom.domains.preference')->middleware('throttle:10,1,enom.domains.preference')->block(10, 10);
    Route::post('/domains/{domain}/manage/{action}', [DomainController::class, 'manage'])->whereNumber('domain')->name('enom.domains.manage')->middleware('throttle:20,1,enom.domains.manage')->block(10, 10);

    // Staff. Route names and controller methods stay stable for the Filament redirects.
    Route::get('/admin/enom/help/{topic?}', [HelpController::class, 'admin'])->where('topic', 'setup|enrollment|calendar|sponsorship|costs|results|review')->name('enom.admin.help');
    Route::get('/admin/enom', [AdminController::class, 'index'])->name('enom.admin.index');
    Route::get('/admin/enom/domains/{domain}', [AdminController::class, 'domain'])->whereNumber('domain')->name('enom.admin.domain');
    Route::post('/admin/enom/domains/{domain}/policy', [AdminController::class, 'policy'])->whereNumber('domain')->name('enom.admin.policy')->middleware('throttle:10,1,enom.admin.policy')->block(10, 10);
    Route::post('/admin/enom/domains/{domain}/sponsorship', [AdminController::class, 'sponsorship'])->whereNumber('domain')->name('enom.admin.sponsorship')->middleware('throttle:10,1,enom.admin.sponsorship')->block(10, 10);
    Route::post('/admin/enom/domains/{domain}/refresh', [AdminController::class, 'refresh'])->whereNumber('domain')->name('enom.admin.refresh')->middleware('throttle:10,1,enom.admin.refresh')->block(10, 10);
    Route::post('/admin/enom/domains/{domain}/sponsored-renewal', [AdminController::class, 'sponsoredRenewal'])->whereNumber('domain')->name('enom.admin.sponsored-renewal')->middleware('throttle:10,1,enom.admin.sponsored-renewal')->block(10, 10);
    Route::post('/admin/enom/{operation}/reconcile', [AdminController::class, 'reconcile'])->whereNumber('operation')->name('enom.admin.reconcile')->middleware('throttle:10,1,enom.admin.reconcile')->block(10, 10);
    Route::post('/admin/enom/{operation}/readmit', [AdminController::class, 'readmit'])->whereNumber('operation')->name('enom.admin.readmit')->middleware('throttle:10,1,enom.admin.readmit')->block(10, 10);
    Route::post('/admin/enom/{operation}/provider-fact', [AdminController::class, 'providerFact'])->whereNumber('operation')->name('enom.admin.provider-fact')->middleware('throttle:10,1,enom.admin.provider-fact')->block(10, 10);
    Route::post('/admin/enom/{operation}/disposition', [AdminController::class, 'disposition'])->whereNumber('operation')->name('enom.admin.disposition')->middleware('throttle:10,1,enom.admin.disposition')->block(10, 10);
    Route::post('/admin/enom/{operation}/fund', [AdminController::class, 'fund'])->whereNumber('operation')->name('enom.admin.fund')->middleware('throttle:10,1,enom.admin.fund')->block(10, 10);
});

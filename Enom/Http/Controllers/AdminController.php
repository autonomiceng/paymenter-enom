<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

/**
 * Staff work happens in the Filament admin (Domains, TLDs & pricing, Registrar requests, Enom setup).
 * These 0.2 URLs keep their route names and only redirect. Old forms posted here change nothing.
 */
class AdminController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);
    }

    public function index(Request $request): RedirectResponse
    {
        $this->authorizeStaff($request);
        return redirect()->to(OperationResource::getUrl('index'));
    }

    public function domain(Request $request, int $domain): RedirectResponse
    {
        $this->authorizeStaff($request);
        return redirect()->to(self::domainUrl($domain));
    }

    public function policy(Request $request, int $domain): RedirectResponse { return $this->movedDomain($request, $domain); }

    public function sponsorship(Request $request, int $domain): RedirectResponse { return $this->movedDomain($request, $domain); }

    public function refresh(Request $request, int $domain): RedirectResponse { return $this->movedDomain($request, $domain); }

    public function sponsoredRenewal(Request $request, int $domain): RedirectResponse { return $this->movedDomain($request, $domain); }

    public function reconcile(Request $request, int $operation): RedirectResponse { return $this->movedRequest($request); }

    public function readmit(Request $request, int $operation): RedirectResponse { return $this->movedRequest($request); }

    public function providerFact(Request $request, int $operation): RedirectResponse { return $this->movedRequest($request); }

    public function disposition(Request $request, int $operation): RedirectResponse { return $this->movedRequest($request); }

    public function fund(Request $request, int $operation): RedirectResponse { return $this->movedRequest($request); }

    private function movedDomain(Request $request, int $domain): RedirectResponse
    {
        $this->authorizeStaff($request);
        self::notice();
        return redirect()->to(self::domainUrl($domain));
    }

    private function movedRequest(Request $request): RedirectResponse
    {
        $this->authorizeStaff($request);
        self::notice();
        return redirect()->to(OperationResource::getUrl('index'));
    }

    private static function domainUrl(int $domain): string
    {
        return Domain::query()->whereKey($domain)->exists()
            ? DomainResource::getUrl('view', ['record' => $domain])
            : DomainResource::getUrl('index');
    }

    private static function notice(): void
    {
        Notification::make()->title('This page has moved. Nothing was changed; use the actions here instead.')->warning()->send();
    }
}

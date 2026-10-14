<?php

namespace Paymenter\Extensions\Servers\Enom;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Invoice\Paid;
use App\Events\InvoiceItem\Created;
use App\Events\CartItem\Creating as CartItemCreating;
use App\Exceptions\DisplayException;
use App\Models\Service;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Jobs\ObserveDomain;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainObservation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use RuntimeException;
use Throwable;

#[ExtensionMeta(name: 'Enom sandbox', description: 'Registrar domains with native billing, daily observation and explicit reseller funding.', version: '0.2.6', author: 'Autonomic Engineering')]
class Enom extends Server
{
    public function getConfig($values = [])
    {
        // Descriptions are plain strings. FilamentInput shows them as helper text under each field.
        return [
            ['name' => 'sandbox', 'label' => 'Sandbox only', 'type' => 'checkbox', 'default' => true, 'database_type' => 'boolean',
                'description' => 'Keep this on. The extension only works with the Enom test environment, and turning this off stops every registrar request.'],
            ['name' => 'operations_enabled', 'label' => 'Allow registrar changes', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Lets the worker send registrations, transfers, renewals and domain changes. When on, the worker considers every queued request, including older ones. Each request must still pass its own checks, and a request waits while another request for the same domain is unresolved. Review Enom administration at /admin/enom before turning this on.'],
            ['name' => 'reads_enabled', 'label' => 'Allow registrar reads', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Lets Paymenter read domain status, expiry and prices from Enom. Reads never buy or change anything. Needed for registrar checks, enrollment and reconciliation.'],
            ['name' => 'observations_enabled', 'label' => 'Check domains daily', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Checks each domain\'s status and expiry once a day. Also needs Allow registrar reads.'],
            ['name' => 'billing_enabled', 'label' => 'Schedule domain renewal invoices', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Lets the daily billing run issue renewal invoices for managed domains and finish renewal cancellations. New invoices also need Allow registrar changes.'],
            ['name' => 'grace_renewals_enabled', 'label' => 'Allow paid renewals during grace', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Allows eligible paid or explicitly sponsored renewals after expiry, subject to current registrar eligibility and complete cost checks. Test expired-domain renewal before enabling this setting. When off, eligible unpaid invoices with no recorded transactions or registrar attempt are cancelled after expiry.'],
            ['name' => 'managed_billing', 'label' => 'Use registrar expiry for new domains', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Applies to registrations and transfers placed after this is on. Their renewal invoices follow the registrar expiry date. Enroll existing domains one at a time in Enom administration at /admin/enom.'],
            ['name' => 'issue_days', 'label' => 'Issue renewal invoice, days before expiry', 'type' => 'number', 'default' => 21, 'database_type' => 'integer', 'validation' => 'integer|min:2|max:365',
                'description' => 'Default for new managed domains. 2 to 365, and more than the payment due days.'],
            ['name' => 'due_days', 'label' => 'Payment due, days before expiry', 'type' => 'number', 'default' => 7, 'database_type' => 'integer', 'validation' => 'integer|min:1|max:364',
                'description' => 'Default for new managed domains. 1 to 364, and fewer than the issue days.'],
            ['name' => 'credits_auto_use', 'label' => 'Pay renewal invoices from customer credit', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Default for managed domains without their own setting. Credit is used only when the customer\'s balance covers the whole invoice.'],
            ['name' => 'observer_requests_per_minute', 'label' => 'Domain check reads per minute', 'type' => 'number', 'default' => 60, 'database_type' => 'integer', 'validation' => 'integer|min:4|max:300',
                'description' => 'Limits domain status and renewal-price checks, shared by servers using the same Enom account. This does not cap availability searches or renewal admission reads. 4 to 300.'],
            ['name' => 'uid', 'label' => 'Sandbox login ID', 'type' => 'password', 'encrypted' => true,
                'description' => 'Leave blank when creating the server. Add it afterwards with Edit server so it is stored encrypted. Registrar requests refuse credentials that are not encrypted.'],
            ['name' => 'token', 'label' => 'Sandbox API token', 'type' => 'password', 'encrypted' => true,
                'description' => 'Leave blank when creating the server. Add it afterwards with Edit server so it is stored encrypted.'],
            ['name' => 'lead_days', 'label' => 'Service due date offset, unmanaged domains', 'type' => 'number', 'default' => 30, 'database_type' => 'integer', 'validation' => 'integer|min:1|max:90',
                'description' => 'Only for domains outside managed billing. Sets the native service due date this many days before the registrar expiry. 1 to 90.'],
        ];
    }

    public function getProductConfig($values = [])
    {
        return [
            ['name' => 'enom_tld', 'label' => 'Domain ending', 'type' => 'select', 'options' => ['com' => '.com'], 'default' => 'com', 'required' => true,
                'description' => '.com is the only supported ending. Keep this product hidden, with one yearly recurring plan priced in USD, no setup fee, no configurable options and no upgrades. Customers order on the Domains page.'],
            ['name' => 'enom_register_price', 'label' => 'Registration price, USD before tax', 'type' => 'number', 'required' => true, 'validation' => 'numeric|min:0.01',
                'description' => 'Charged for a new one-year registration. The renewal price is the yearly plan price.'],
            ['name' => 'enom_transfer_price', 'label' => 'Transfer price, USD before tax', 'type' => 'number', 'required' => true, 'validation' => 'numeric|min:0.01',
                'description' => 'Charged for an incoming transfer.'],
            ['name' => 'enom_privacy_price', 'label' => 'Contact privacy price, USD before tax', 'type' => 'number', 'validation' => 'nullable|numeric|min:0.01',
                'description' => 'One year of contact privacy. Leave blank to hide privacy purchases.'],
        ];
    }

    public function getCheckoutConfig()
    {
        return [['name' => 'enom_order_path', 'type' => 'text', 'label' => 'Order domains through the Domains page',
            'required' => true, 'validation' => [function ($attribute, $value, $fail) { $fail('Order domains through the Domains page.'); }]]];
    }

    public function installed(): void
    {
        app(Migrator::class)->run([base_path('extensions/Servers/Enom/database/migrations')]);
        $this->verifySchema();
    }

    public function upgraded($oldVersion = null): void { $this->installed(); }
    public function uninstalled(): void {} // Retain registrations, ownership and financial ledger.

    public function verifySchema(): void
    {
        $required = [
            'enom_domains' => ['id', 'service_id', 'user_id', 'server_id', 'domain', 'sld', 'tld', 'status', 'provider_expires_at', 'provider_order_id', 'provider_domain_id', 'snapshot', 'capabilities', 'last_synced_at', 'drift_detected_at', 'billing_managed', 'renewal_policy', 'sponsorship', 'observation', 'authority_generation', 'confirmed_revision', 'created_at', 'updated_at'],
            'enom_operations' => ['id', 'domain_id', 'service_id', 'invoice_id', 'operation_key', 'invoice_item_id', 'action', 'term', 'state', 'payload', 'policy', 'cost', 'funding_basis', 'amount', 'currency', 'lease_expires_at', 'base_expires_at', 'expected_expires_at', 'confirmed_expires_at', 'provider_order_id', 'safe_code', 'request_fingerprint', 'authorized_by', 'funding_reason', 'started_at', 'completed_at', 'created_at', 'updated_at'],
            'enom_order_intents' => ['id', 'user_id', 'server_id', 'product_id', 'plan_id', 'domain', 'sld', 'tld', 'action', 'term', 'amount', 'renewal_amount', 'currency', 'service_id', 'invoice_id', 'invoice_item_id', 'payload', 'claimed_at', 'expires_at', 'created_at', 'updated_at'],
        ];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) { throw new RuntimeException('Enom extension schema is incomplete.'); }
        }
        foreach (['enom_domains' => [['domain'], ['service_id']], 'enom_operations' => [['operation_key'], ['invoice_item_id', 'action']], 'enom_order_intents' => [['service_id']]] as $table => $constraints) {
            $indexes = Schema::getIndexes($table);
            foreach ($constraints as $columns) {
                if (!collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) { throw new RuntimeException('Enom extension uniqueness constraints are incomplete.'); }
            }
        }
    }

    public function boot(): void
    {
        // Preserve TXT bytes before the global middleware reaches our DNS controller.
        // Other routes keep native trimming; this action explicitly validates its own fields.
        TrimStrings::skipWhen(fn (Request $request) => $request->isMethod('POST')
            && preg_match('/\Adomains\/[0-9]+\/manage\/hosts\z/', $request->path()) === 1);
        View::addNamespace('enom', __DIR__.'/resources/views');
        if (is_file(__DIR__.'/routes/web.php')) { require __DIR__.'/routes/web.php'; }
        Event::listen(Created::class, function (Created $event) {
            try { app(DomainOperations::class)->bindInvoiceItem($event->invoiceItem); } catch (Throwable) {}
        });
        Event::listen(Paid::class, function (Paid $event) {
            try { app(DomainOperations::class)->acceptPaidInvoice($event->invoice); } catch (Throwable) {}
        });
        Service::updating(fn (Service $service) => app(DomainBilling::class)->preserveNativeDate($service));
        Event::listen(CartItemCreating::class, function (CartItemCreating $event) {
            if ($event->cartItem->product?->server?->extension === 'Enom') { throw new DisplayException('Order domains through the Domains page.'); }
        });
        Event::listen('permissions', fn () => ['admin.enom.manage' => 'Manage Enom domains',
            'admin.enom.fund' => 'Authorize Enom sponsorship', 'admin.enom.costs' => 'View internal Enom costs']);
        Event::listen('navigation.dashboard', fn () => ['name' => 'Domains', 'url' => route('enom.domains.index'), 'icon' => 'ri-global', 'priority' => 12]);
        Schedule::call(function () {
            try { app(DomainOperations::class)->sweep(); } catch (Throwable) {}
        })->name('enom-reconcile')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();
        Schedule::call(function () {
            Domain::whereIn('status', DomainObservation::STATUSES)->orderBy('id')->chunkById(100, function ($domains) {
                foreach ($domains as $domain) {
                    $server = $domain->service?->product?->server;
                    if (!$server?->enabled || $server->extension !== 'Enom' || (int) $server->id !== (int) $domain->server_id) { continue; }
                    if (!filter_var($server->settings->firstWhere('key', 'observations_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN)) { continue; }
                    ObserveDomain::dispatch((int) $domain->id);
                }
            });
        })->name('enom-domain-observation')->dailyAt('00:05')->withoutOverlapping(15)->onOneServer();
        Schedule::call(fn () => app(DomainBilling::class)->sweep())
            ->name('enom-domain-billing')->dailyAt('00:10')->withoutOverlapping(15)->onOneServer();
        Schedule::call(fn () => app(DomainOperations::class)->reportRetiredInvoicePayments())
            ->name('enom-retired-invoice-payments')->dailyAt('00:15')->withoutOverlapping(15)->onOneServer();
    }

    public function createServer(Service $service, $settings = [], $properties = [])
    {
        app(DomainOperations::class)->enqueueService($service);
        return ['status' => 'Registrar operation queued; confirmation is shown in Domains.'];
    }

    public function unsuspendServer(Service $service, $settings = [], $properties = []) { return $this->createServer($service, $settings, $properties); }
    public function suspendServer(Service $service, $settings = [], $properties = []) { return []; }
    public function terminateServer(Service $service, $settings = [], $properties = []) { return []; }

    public function disabled($record): void
    {
        $record->settings()->updateOrCreate(['key' => 'operations_enabled'], ['value' => false, 'type' => 'boolean', 'encrypted' => false]);
    }

    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        $domain = Domain::where('service_id', $service->id)->where('user_id', Auth::id())->first();
        return $domain ? [['type' => 'view', 'label' => 'Domain', 'name' => 'domain']] : [];
    }

    public function getView(Service $service, $settings = [], $properties = [], $name = 'domain')
    {
        abort_unless($name === 'domain' && Auth::check(), 404);
        $domain = Domain::where('service_id', $service->id)->where('user_id', Auth::id())->firstOrFail();
        $domain = app(Services\DomainManager::class)->owned(Auth::user(), $domain->id);
        return view('enom::service', ['snapshot' => app(Services\DomainManager::class)->snapshot(Auth::user(), $domain->id)]);
    }
}

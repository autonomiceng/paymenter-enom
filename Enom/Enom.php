<?php

namespace Paymenter\Extensions\Servers\Enom;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Invoice\Paid;
use App\Events\InvoiceItem\Created;
use App\Events\CartItem\Creating as CartItemCreating;
use App\Exceptions\DisplayException;
use App\Models\Server as ServerModel;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
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

#[ExtensionMeta(name: 'Enom', description: 'Sell and manage domain names through an Enom reseller account.', version: '0.3.0', author: 'Autonomic Engineering')]
class Enom extends Server
{
    private static bool $credentialHook = false;

    /**
     * Native server Create saves settings without their encrypted flag. Encrypt Enom credentials
     * before the first write. Registered from boot() and from getConfig(), because Paymenter only
     * boots server extensions that already have a server, and the first one is created through getConfig().
     */
    public static function encryptCredentialsOnSave(): void
    {
        if (self::$credentialHook) { return; }
        self::$credentialHook = true;
        Setting::saving(function (Setting $setting) {
            if (!in_array($setting->key, ['uid', 'token'], true) || $setting->encrypted || !is_string($setting->value) || $setting->value === ''
                || $setting->settingable_type !== (new ServerModel)->getMorphClass()
                || ServerModel::query()->whereKey($setting->settingable_id)->value('extension') !== 'Enom') { return; }
            $setting->encrypted = true;
            $setting->value = Crypt::encryptString(trim($setting->value));
        });
    }

    public function getConfig($values = [])
    {
        self::encryptCredentialsOnSave();
        $live = ($values['environment'] ?? null) === 'live';
        return [
            ['name' => 'environment', 'label' => 'Enom environment', 'type' => 'select', 'required' => true, 'default' => 'test', 'live' => true,
                'options' => ['test' => 'Test (resellertest.enom.com)', 'live' => 'Live (reseller.enom.com)'],
                'description' => $live
                    ? 'Live registers real domains and charges your Enom balance. After saving, confirm live mode on Domains → Enom setup.'
                    : 'Test uses Enom\'s reseller test account. Nothing is charged and test domains never go live. Use a separate server for live.'],
            ['name' => 'uid', 'label' => 'Enom account ID', 'type' => 'text', 'required' => true, 'encrypted' => true,
                'description' => 'The login ID of your Enom reseller account.'],
            ['name' => 'token', 'label' => 'API token', 'type' => 'password', 'required' => true, 'encrypted' => true,
                'description' => 'Create one in Enom under Resellers → API tokens. Enom also needs this server\'s IP address on its allowlist. After saving, test the connection on Domains → Enom setup.'],
            ['name' => 'operations_enabled', 'label' => 'Process orders and changes', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Sends paid registrations, transfers, renewals and domain changes to Enom. Turn off to pause; waiting work is kept and sent when you turn it back on.'],
            ['name' => 'observations_enabled', 'label' => 'Check domain status daily', 'type' => 'checkbox', 'default' => true, 'database_type' => 'boolean',
                'description' => 'Reads each domain\'s status and expiry date from Enom once a day.'],
            ['name' => 'billing_enabled', 'label' => 'Send renewal invoices', 'type' => 'checkbox', 'default' => true, 'database_type' => 'boolean',
                'description' => 'Invoices clients before their domains expire. Complimentary domains renew without an invoice.'],
            ['name' => 'issue_days', 'label' => 'Invoice days before expiry', 'type' => 'number', 'default' => 21, 'database_type' => 'integer', 'validation' => 'integer|min:2|max:365'],
            ['name' => 'due_days', 'label' => 'Due days before expiry', 'type' => 'number', 'default' => 7, 'database_type' => 'integer', 'validation' => 'integer|min:1|max:364',
                'description' => 'Must be fewer than the invoice days.'],
            ['name' => 'credits_auto_use', 'label' => 'Pay renewals from account credit', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Used only when the client\'s credit covers the whole invoice.'],
            ['name' => 'grace_renewals_enabled', 'label' => 'Allow renewal after expiry', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Renews paid domains during the registry grace period, if Enom still allows it at the expected cost.'],
            ['name' => 'default_nameservers', 'label' => 'Default nameservers', 'type' => 'textarea', 'rows' => 4,
                'placeholder' => "ns1.example.com\nns2.example.com", 'validation' => ['nullable', 'string', 'max:3000', static function ($attribute, $value, $fail) {
                    if (!is_string($value) || trim($value) === '') { return; }
                    if ($error = self::nameserverError(Services\RuntimeGuard::nameserverList($value))) { $fail($error); }
                }],
                'description' => 'One per line, 2 to 12. New registrations use these, and clients can switch back to them. Leave blank to use Enom\'s own nameservers.'],
            ['name' => 'dns_tools', 'label' => 'Show Enom DNS tools', 'type' => 'checkbox', 'default' => false, 'database_type' => 'boolean',
                'description' => 'Lets clients and staff edit DNS records, SRV records and DNSSEC hosted at Enom. Leave off if your nameservers are elsewhere.'],
            ['name' => 'privacy_price', 'label' => 'ID Protect price per year (USD)', 'type' => 'number', 'validation' => 'nullable|numeric|min:0.01|max:1000',
                'description' => 'Leave blank to stop selling ID Protect (WHOIS privacy).'],
            ['name' => 'complimentary_cost_cap', 'label' => 'Complimentary cost limit (USD)', 'type' => 'number', 'default' => 50, 'validation' => 'numeric|min:0|max:10000',
                'description' => 'A complimentary registration or renewal waits for staff if Enom quotes more than this.'],
            ['name' => 'observer_requests_per_minute', 'label' => 'Enom requests per minute', 'type' => 'number', 'default' => 60, 'database_type' => 'integer', 'validation' => 'integer|min:4|max:300',
                'description' => 'Limit for daily status checks, shared by servers using the same Enom account.'],
        ];
    }

    /** First problem with a nameserver list, or null when it is usable. */
    public static function nameserverError(array $hosts): ?string
    {
        if (count($hosts) < 2 || count($hosts) > 12) { return 'Enter 2 to 12 nameservers, one per line.'; }
        foreach ($hosts as $host) {
            if (strlen($host) > 253 || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host)) {
                return $host.' is not a valid nameserver hostname.';
            }
        }
        return null;
    }

    public function getProductConfig($values = [])
    {
        return [['name' => 'enom_pricing_note', 'type' => 'placeholder',
            'label' => 'Domain prices are set per ending in Domains → TLDs & pricing. This product is managed by the Enom extension.']];
    }

    public function getCheckoutConfig()
    {
        return [['name' => 'enom_order_path', 'type' => 'text', 'label' => 'Domain',
            'required' => true, 'validation' => [function ($attribute, $value, $fail) { $fail('Search for domains on the Domains page.'); }]]];
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
            'enom_domains' => ['id', 'service_id', 'user_id', 'server_id', 'domain', 'sld', 'tld', 'status', 'provider_expires_at', 'provider_order_id', 'provider_domain_id', 'snapshot', 'capabilities', 'last_synced_at', 'drift_detected_at', 'billing_managed', 'renewal_policy', 'sponsorship', 'observation', 'authority_generation', 'confirmed_revision', 'price_mode', 'created_at', 'updated_at'],
            'enom_operations' => ['id', 'domain_id', 'service_id', 'invoice_id', 'operation_key', 'invoice_item_id', 'action', 'term', 'state', 'payload', 'policy', 'cost', 'funding_basis', 'amount', 'currency', 'lease_expires_at', 'base_expires_at', 'expected_expires_at', 'confirmed_expires_at', 'provider_order_id', 'safe_code', 'request_fingerprint', 'authorized_by', 'funding_reason', 'started_at', 'completed_at', 'created_at', 'updated_at'],
            'enom_tlds' => ['id', 'server_id', 'tld', 'enabled', 'pricing_mode', 'markup', 'register_price', 'renew_price', 'transfer_price',
                'cost_register', 'cost_renew', 'cost_transfer', 'costs_synced_at', 'sort_order', 'created_at', 'updated_at'],
            'enom_order_intents' => ['id', 'user_id', 'server_id', 'product_id', 'plan_id', 'domain', 'sld', 'tld', 'action', 'term', 'amount', 'renewal_amount', 'currency', 'service_id', 'invoice_id', 'invoice_item_id', 'payload', 'claimed_at', 'expires_at', 'created_at', 'updated_at'],
        ];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) { throw new RuntimeException('Enom extension schema is incomplete.'); }
        }
        foreach (['enom_domains' => [['domain'], ['service_id']], 'enom_operations' => [['operation_key'], ['invoice_item_id', 'action']], 'enom_order_intents' => [['service_id']], 'enom_tlds' => [['server_id', 'tld']]] as $table => $constraints) {
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
            if ($event->cartItem->product?->server?->extension === 'Enom') { throw new DisplayException('Search for domains on the Domains page.'); }
        });
        self::encryptCredentialsOnSave();
        Event::listen('permissions', fn () => ['admin.enom.manage' => 'Manage domains',
            'admin.enom.fund' => 'Give complimentary domains', 'admin.enom.costs' => 'See Enom costs and margins']);
        Event::listen('navigation.dashboard', fn () => ['name' => 'Domains', 'url' => route('enom.domains.index'), 'icon' => 'ri-global', 'priority' => 12]);
        foreach (['Domain', 'Operation', 'Tld'] as $model) {
            $policy = __NAMESPACE__.'\\Policies\\'.$model.'Policy';
            if (class_exists($policy)) { Gate::policy(__NAMESPACE__.'\\Models\\'.$model, $policy); }
        }
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
        return ['status' => 'Sent to the domain queue. Progress is shown on the Domains page.'];
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
        return $domain ? [['type' => 'button', 'label' => 'Manage domain', 'url' => route('enom.domains.show', $domain->id)]] : [];
    }

    public function getView(Service $service, $settings = [], $properties = [], $name = 'domain')
    {
        abort_unless($name === 'domain' && Auth::check(), 404);
        $domain = Domain::where('service_id', $service->id)->where('user_id', Auth::id())->firstOrFail();
        $domain = app(Services\DomainManager::class)->owned(Auth::user(), $domain->id);
        return view('enom::service', ['snapshot' => app(Services\DomainManager::class)->snapshot(Auth::user(), $domain->id)]);
    }
}

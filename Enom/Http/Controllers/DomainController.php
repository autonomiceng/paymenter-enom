<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use RuntimeException;
use Throwable;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use Paymenter\Extensions\Servers\Enom\Services\PriceChangedException;

/** Client area: domain list, search, order and management. */
class DomainController extends Controller
{
    /** Most endings checked for one search. */
    public const MAX_RESULTS = 12;

    /** Availability checks a guest's address can make per minute. */
    public const GUEST_CHECKS_PER_MINUTE = 30;

    public const ROLES = ['registrant' => 'Owner', 'admin' => 'Administrative', 'tech' => 'Technical', 'auxbilling' => 'Billing'];

    /** List filters: query value => [label, statuses]. "expiring" is computed separately. */
    public const FILTERS = [
        'active' => ['Active', ['active']],
        'pending' => ['In progress', ['registration_pending', 'transfer_pending']],
        'expiring' => ['Expiring soon', ['active']],
        'expired' => ['Expired', ['expired', 'grace', 'redemption']],
        'attention' => ['Needs attention', ['registration_rejected', 'transfer_rejected', 'needs_review']],
    ];

    public const SORTS = ['expiry', '-expiry', 'name', '-name'];

    public const GLUE_ACTIONS = ['glue_add', 'glue_update', 'glue_delete'];

    public const PHONE_MESSAGE = 'Enter the number with + and the country code, for example +1 206 555 0100.';

    /** Local messages from DomainOrders that mean "this name can't be ordered here". */
    private const UNAVAILABLE_MESSAGES = ['This domain is unavailable or requires unsupported registry pricing.'];

    private const EXISTING_MESSAGE = 'This domain already has a portal record.';

    public function __construct(private DomainManager $manager, private DomainOrders $orders, private DomainOperations $operations) {}

    // ---------------------------------------------------------------- list

    public function index(Request $request)
    {
        $user = $request->user();
        $search = self::queryString($request, 'q', 253);
        $status = $request->query('status');
        $filter = is_string($status) && array_key_exists($status, self::FILTERS) ? $status : '';
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'expiry';

        $query = Domain::query()->where('user_id', $user->id)->with('service.cancellation')
            ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.addcslashes(strtolower($search), '%_\\').'%'));
        if ($filter !== '') {
            $query->whereIn('status', self::FILTERS[$filter][1]);
            if ($filter === 'expiring') {
                $query->whereNotNull('provider_expires_at')->whereBetween('provider_expires_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()]);
            }
        }
        match ($sort) {
            'name' => $query->orderBy('domain'),
            '-name' => $query->orderByDesc('domain'),
            'expiry' => $query->orderByRaw('provider_expires_at IS NULL')->orderBy('provider_expires_at')->orderBy('domain'),
            '-expiry' => $query->orderByRaw('provider_expires_at IS NULL')->orderByDesc('provider_expires_at')->orderBy('domain'),
        };
        $domains = $query->paginate(25)->withQueryString();
        $progress = DomainAdministration::progress($domains->getCollection());

        $counts = Domain::query()->where('user_id', $user->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $filterCounts = ['' => (int) $counts->sum()];
        foreach (self::FILTERS as $key => [, $statuses]) {
            $filterCounts[$key] = (int) $counts->only($statuses)->sum();
        }
        $filterCounts['expiring'] = Domain::query()->where('user_id', $user->id)->where('status', 'active')->whereNotNull('provider_expires_at')
            ->whereBetween('provider_expires_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])->count();

        return view('enom::index', [
            'domains' => $domains, 'progress' => $progress, 'search' => $search, 'filter' => $filter, 'sort' => $sort,
            'filterCounts' => $filterCounts, 'testEnvironment' => $this->catalogueInTest($this->endings()),
        ]);
    }

    // ---------------------------------------------------------------- search and transfer

    public function search(Request $request)
    {
        $user = $request->user();
        $endings = $this->endings();
        $raw = self::queryString($request, 'name', 300);
        $chosen = self::queryString($request, 'tld', 63);
        $parsed = $raw === '' ? ['sld' => null, 'ending' => null, 'error' => null] : self::parseName($raw);
        $names = $endings->pluck('tld')->all();
        $preferred = in_array($chosen, $names, true) ? $chosen : ($names[0] ?? null);
        $notice = null;
        if ($parsed['ending'] !== null) {
            if (in_array($parsed['ending'], $names, true)) {
                $preferred = $parsed['ending'];
            } elseif (self::endsWithAny($parsed['ending'], $names)) {
                $parsed['error'] = 'Enter the name without a subdomain, for example '.($parsed['ending']).'.';
            } else {
                $notice = '.'.$parsed['ending'].' isn’t available here. We offer '.self::listEndings($names).'.';
            }
        }
        $results = [];
        if ($parsed['sld'] !== null && $parsed['error'] === null && $endings->isNotEmpty()) {
            $ordered = $endings->sortBy(fn ($tld) => $tld->tld === $preferred ? -1 : 0, SORT_REGULAR, false)->values()->take(self::MAX_RESULTS);
            foreach ($ordered as $tld) {
                $results[] = ['domain' => $parsed['sld'].'.'.$tld->tld, 'sld' => $parsed['sld'], 'tld' => $tld->tld, 'terms' => self::termsFor($tld),
                    'register_url' => DomainOrders::checkoutUrl($tld, 'register', $parsed['sld'].'.'.$tld->tld, $tld->terms()[0] ?? null),
                    'transfer_url' => DomainOrders::checkoutUrl($tld, 'transfer', $parsed['sld'].'.'.$tld->tld, 1)];
            }
        }
        $years = (int) self::queryString($request, 'years', 2);

        return view('enom::search', [
            'endings' => $this->endingOptions($user, $endings, 'register'), 'name' => $raw, 'preferred' => $preferred,
            'sld' => $parsed['sld'], 'error' => $parsed['error'], 'notice' => $notice, 'results' => $results,
            'testEnvironment' => $this->catalogueInTest($endings),
            'guest' => !$user, 'years' => $years >= 1 && $years <= 10 ? $years : null,
        ]);
    }

    /** /domains/search is an alias; the canonical page is /domains/register. */
    public function searchAlias(Request $request)
    {
        return redirect()->route('enom.domains.search', array_filter($request->query(), 'is_string'), 301);
    }

    /** Public price list: enabled endings with pre-tax prices. */
    public function pricing(Request $request)
    {
        $endings = $this->endings();
        $rows = [];
        try {
            $public = app(Catalogue::class)->publicPrices();
        } catch (Throwable $exception) {
            report($exception);
            $public = [];
        }
        foreach ($public as $row) {
            $rows[] = ['tld' => (string) ($row['tld'] ?? ''), 'register' => self::money($row['register'] ?? null), 'renew' => self::money($row['renew'] ?? null),
                'transfer' => self::money($row['transfer'] ?? null), 'min_years' => (int) ($row['min_years'] ?? 1), 'max_years' => (int) ($row['max_years'] ?? 10)];
        }
        $user = $request->user();

        return view('enom::pricing', [
            'rows' => array_values(array_filter($rows, fn ($row) => $row['tld'] !== '')), 'guest' => !$user,
            'testEnvironment' => $this->catalogueInTest($endings),
        ]);
    }

    /** @return list<int> registration terms on sale for an ending */
    public static function termsFor(mixed $tld): array
    {
        $terms = $tld->terms();

        return $terms ?: [1];
    }

    public function transfer(Request $request)
    {
        $endings = $this->endings()->filter(fn ($tld) => $tld->orderable('transfer') && app(\Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class)->allowed($tld));

        $name = self::queryString($request, 'name', 253);
        $ending = self::queryString($request, 'tld', 63);
        if ($name !== '' && !str_contains($name, '.') && $ending !== '') { $name .= '.'.$ending; }
        $result = null;
        if ($name !== '') {
            try { $result = $this->orders->check($request->user(), $name); }
            catch (\Throwable) { $result = ['error' => 'This domain could not be checked. Try again.']; }
        }
        return view('enom::transfer', [
            'endings' => $this->endingOptions($request->user(), $endings, 'transfer'),
            'name' => $name, 'result' => $result,
            'testEnvironment' => $this->catalogueInTest($endings),
        ]);
    }

    /** Availability for one full name. JSON for the search page; checked one ending at a time. */
    public function check(Request $request): JsonResponse
    {
        $domain = $request->input('domain');
        if (!is_string($domain) || strlen($domain) > 253 || !preg_match('/\A[a-z0-9-]{1,63}(\.[a-z0-9]{1,63}){1,3}\z/', $domain = strtolower(trim($domain)))) {
            return response()->json(['status' => 'invalid', 'message' => 'Enter a valid domain name.'], 422);
        }
        foreach (\App\Classes\Cart::get()->items()->with('product.server')->get() as $line) {
            if ($line->product?->server?->extension === 'Enom' && ($line->checkout_config['domain'] ?? null) === $domain) {
                return response()->json(['domain' => $domain, 'status' => 'in_cart', 'url' => route('cart')]);
            }
        }
        $user = $request->user();
        if ($user) {
            $reservation = \Paymenter\Extensions\Servers\Enom\Services\DomainReservations::blocking($domain);
            $owned = $reservation && (int) $reservation->user_id === (int) $user->id ? $reservation->id : null;
            if ($owned) {
                return response()->json(['domain' => $domain, 'status' => 'yours', 'url' => route('enom.domains.show', $owned)]);
            }
        } else {
            // Guests share one small per-address budget on top of the route throttle, and see a short-lived cached answer.
            $limiter = 'enom.domains.check.guest:'.sha1((string) $request->ip());
            if (RateLimiter::tooManyAttempts($limiter, self::GUEST_CHECKS_PER_MINUTE)) {
                return response()->json(['domain' => $domain, 'status' => 'error', 'message' => 'Too many searches. Wait a minute, then retry.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($limiter));
            }
            $cached = Cache::get('enom.domains.check.guest:'.$domain);
            if (is_array($cached)) {
                return response()->json($cached);
            }
            RateLimiter::hit($limiter, 60);
        }
        try {
            $result = $this->orders->check($user, $domain);
        } catch (InvalidArgumentException) {
            return response()->json(['domain' => $domain, 'status' => 'invalid', 'message' => 'This name can’t be registered.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['domain' => $domain, 'status' => 'error', 'message' => 'Couldn’t check this name.']);
        }
        $status = match (true) {
            !empty($result['available']) => 'available',
            !empty($result['existing']) => 'managed',
            !empty($result['premium']) => 'premium',
            !empty($result['transferable']) => 'transferable',
            default => 'taken',
        };
        $currency = is_string($result['currency'] ?? null) ? $result['currency'] : 'USD';
        // Each offered term has its own catalogue total.
        $available = array_values(array_filter(array_map('intval', (array) ($result['terms'] ?? [1])), fn ($y) => $y >= 1 && $y <= 10)) ?: [1];
        $terms = [];
        foreach ($available as $years) {
            $total = $result['register_totals'][$years] ?? null;
            $terms[(string) $years] = self::money($total, $currency);
        }
        [$min, $max] = [min($available), max($available)];
        $alternative = null;
        $primary = $result['register_totals'][$min] ?? null;
        foreach ($available as $years) {
            $total = $result['register_totals'][$years] ?? null;
            if ($years > $min && is_numeric($primary) && is_numeric($total) && (float) $total / $years <= (float) $primary / $min * 0.9) {
                $alternative = ['years' => $years, 'total' => self::money($total, $currency)];
                break;
            }
        }
        $payload = [
            'domain' => $result['domain'] ?? $domain, 'status' => $status,
            'price' => self::money($result['register_price'] ?? null, $currency),
            'transfer_price' => self::money($result['transfer_price'] ?? null, $currency),
            'renew_price' => self::money($result['renew_price'] ?? null, $currency),
            'register_url' => $result['register_url'] ?? null, 'transfer_url' => $result['transfer_url'] ?? null,
            'warning' => $result['warning'] ?? null,
            'min_years' => $min, 'max_years' => $max, 'terms' => $terms, 'alternative' => $alternative,
        ];
        if (!$user) {
            Cache::put('enom.domains.check.guest:'.$domain, $payload, now()->addSeconds(60));
        }

        return response()->json($payload);
    }

    /**
     * The ending's registry details schema: [{name, label, required, parent?, text, options: [{value, label, requires}]}].
     *
     * @return list<array>
     */
    public function quote(Request $request)
    {
        FormKeys::verify($request, 'quote');
        $action = in_array($request->input('action'), ['register', 'transfer'], true) ? $request->input('action') : 'register';
        // Transfers always add one year. Registrations take the chosen term; the service checks the ending's limits.
        $years = $action === 'transfer' ? 1 : self::years($request->input('years'));
        $domain = $request->input('domain');
        $domain = is_string($domain) ? strtolower(trim(substr($domain, 0, 253))) : '';
        // The transfer page sends a name and an ending separately.
        $ending = $request->input('tld');
        if ($ending !== null && (!is_string($ending) || strlen($ending) > 63 || ($ending !== '' && !preg_match('/\A[a-z0-9.-]+\z/', $ending)))) {
            return redirect()->route($action === 'transfer' ? 'enom.domains.transfer' : 'enom.domains.search')
                ->withErrors(['domain' => 'Choose a valid domain ending.']);
        }
        if ($domain !== '' && is_string($ending) && $ending !== '' && !str_contains($domain, '.')) {
            $domain .= '.'.strtolower($ending);
        }
        $back = fn (array $errors) => redirect()->route($action === 'transfer' ? 'enom.domains.transfer' : 'enom.domains.search',
            $action === 'transfer' ? [] : ['name' => $domain, 'years' => $years])->withErrors($errors)->withInput(['domain' => $domain]);
        if ($domain === '') {
            return $back(['domain' => 'Enter the domain name, for example example.com.']);
        }
        $parsed = self::parseName($domain);
        $names = $this->endings()->pluck('tld')->all();
        if ($parsed['error']) {
            return $back(['domain' => $parsed['error']]);
        }
        if ($parsed['ending'] === null || !in_array($parsed['ending'], $names, true)) {
            return $back(['domain' => ($parsed['ending'] ? '.'.$parsed['ending'].' isn’t available here. ' : 'Add the ending, for example '.$parsed['sld'].'.com. ')
                .'We offer '.self::listEndings($names).'.']);
        }
        try {
            $quote = $this->orders->quote($request->user(), $domain, $action, $years);
        } catch (InvalidArgumentException $exception) {
            if ($years !== 1 && str_contains(strtolower($exception->getMessage()), 'year')) {
                $terms = self::termsFor($this->endings()->firstWhere('tld', $parsed['ending']));

                return $back(['domain' => '.'.$parsed['ending'].' can be registered for '.self::termRange($terms).'.']);
            }

            return $back(['domain' => 'This name can’t be registered. Use letters a–z, numbers and hyphens.']);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if ($message !== self::EXISTING_MESSAGE && !in_array($message, self::UNAVAILABLE_MESSAGES, true)) {
                report($exception);
            }

            return $back(['domain' => match (true) {
                $message === self::EXISTING_MESSAGE => $domain.' is already managed here.',
                in_array($message, self::UNAVAILABLE_MESSAGES, true) => $action === 'transfer'
                    ? $domain.' can’t be transferred here. Check that it’s registered and unlocked at your current registrar.'
                    : $domain.' isn’t available to register.',
                default => 'We couldn’t check '.$domain.' right now. Try again in a minute.',
            }]);
        }

        return redirect()->route('enom.domains.order', $quote['intent_id']);
    }

    public function order(Request $request, string $intent)
    {
        $user = $request->user();
        $record = OrderIntent::query()->where('user_id', $user->id)->whereKey($intent)->firstOrFail();
        if ($record->claimed_at) {
            try {
                return $this->afterPlace($this->orders->place($user, $intent));
            } catch (ModelNotFoundException) {
                return redirect()->route('enom.domains.search')->with('enom_notice', 'This order is no longer available. Search for the name again.');
            }
        }
        try {
            $quote = $this->orders->pendingQuote($user, $intent);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable) {
            return redirect()->route($record->action === 'transfer' ? 'enom.domains.transfer' : 'enom.domains.search', ['name' => $record->domain])
                ->with('enom_notice', 'That price quote expired. Check the name again to continue.');
        }

        $tld = $this->endings()->first(fn ($tld) => $tld->tld === $record->tld && (int) $tld->server_id === (int) $record->server_id);
        $terms = $tld ? self::termsFor($tld) : [1];
        $quote += ['years' => (int) ($quote['term'] ?? 1), 'min_years' => $terms[0], 'max_years' => end($terms)];

        return view('enom::order', [
            'quote' => $quote, 'orderKey' => FormKeys::issue($request, 'order:'.$intent),
            'prefill' => self::profileContact($user), 'formId' => 'order:'.$intent,
            'registryFields' => self::registryFields($quote, $tld),
        ]);
    }

    public function place(Request $request)
    {
        $intent = $request->input('intent_id');
        abort_unless(is_string($intent) && preg_match('/\A[a-f0-9]{48}\z/', $intent), 422);
        $user = $request->user();
        $record = OrderIntent::query()->where('user_id', $user->id)->whereKey($intent)->firstOrFail();
        if ($record->claimed_at) {
            try {
                return $this->afterPlace($this->orders->place($user, $intent));
            } catch (ModelNotFoundException) {
                return redirect()->route('enom.domains.search')->with('enom_notice', 'This order is no longer available. Search for the name again.');
            }
        }
        FormKeys::verify($request, 'order:'.$intent);
        $retained = $this->orderInput($request);
        $term = max(1, (int) ($record->term ?? 1));
        $years = $record->action === 'register' && $request->has('years') ? self::years($request->input('years')) : $term;
        if ($request->input('requote') === '1' && $years === $term) {
            // "Update" with the same term never places the order.
            return redirect()->route('enom.domains.order', $intent)->withInput([...$retained, '_enom_form' => 'order:'.$intent]);
        }
        if ($years !== $term) {
            // A different term needs its own quote. Keep what the client typed and show the new total.
            try {
                // Same quote, new term, so the form key and everything typed stay valid.
                $fresh = method_exists($this->orders, 'changeTerm') && !$record->expires_at->isPast()
                    ? $this->orders->changeTerm($user, $intent, $years)
                    : $this->orders->quote($user, $record->domain, $record->action, $years);
            } catch (Throwable $exception) {
                if (!$exception instanceof InvalidArgumentException && !$exception instanceof RuntimeException) {
                    report($exception);
                }

                return redirect()->route('enom.domains.order', $intent)->withErrors(['years' => $record->domain.' can’t be registered for '.self::yearsLabel($years).'. Choose another term.'])
                    ->withInput([...$retained, '_enom_form' => 'order:'.$intent]);
            }

            return redirect()->route('enom.domains.order', $fresh['intent_id'])
                ->withInput([...$retained, '_enom_form' => 'order:'.$fresh['intent_id']])
                ->with('enom_status', 'Updated to '.self::yearsLabel($years).'. Check the total, then continue.');
        }
        if ($record->expires_at->isPast()) {
            // Keep what the client typed: refresh the quote and return to the new order page.
            try {
                $fresh = $this->orders->quote($user, $record->domain, $record->action, $term);
            } catch (Throwable) {
                return redirect()->route($record->action === 'transfer' ? 'enom.domains.transfer' : 'enom.domains.search', ['name' => $record->domain])
                    ->withErrors(['domain' => $record->domain.' is no longer available at the quoted price. Check it again.']);
            }

            return redirect()->route('enom.domains.order', $fresh['intent_id'])
                ->withInput([...$retained, '_enom_form' => 'order:'.$fresh['intent_id']])
                ->with('enom_notice', 'Your price quote expired, so we refreshed it. Check the total and submit again.');
        }
        try {
            $quote = $this->orders->pendingQuote($user, $intent);
            // Collect every field problem at once so the client fixes them in one pass.
            $errors = [];
            $contacts = [];
            try {
                $contacts = $this->orderContacts($request);
            } catch (InputValidationException $exception) {
                $errors += $exception->errors;
            }
            $epp = null;
            if ($quote['action'] === 'transfer') {
                $epp = $request->input('epp');
                if (!is_string($epp) || trim($epp) === '' || strlen($epp) > 255 || preg_match('/[\x00-\x1f\x7f]/', $epp)) {
                    $errors['epp'] = 'Enter the transfer code from your current registrar.';
                } else {
                    $epp = trim($epp);
                }
            }
            $nameservers = [];
            if ($quote['action'] === 'register' && $request->input('ns_mode') === 'custom') {
                try {
                    $nameservers = DomainManager::validatePayload('nameservers', ['confirmed' => '1', 'nameservers' => (string) $request->input('nameservers', '')])['nameservers'];
                } catch (InvalidArgumentException $exception) {
                    $errors['nameservers'] = $exception instanceof InputValidationException ? (string) (array_values($exception->errors)[0] ?? 'Enter valid nameserver hostnames, one per line.') : 'Enter valid nameserver hostnames, one per line.';
                }
            }
            $registry = $this->registryInput($request, self::registryFields($quote, $this->endings()
                ->first(fn ($tld) => $tld->tld === $record->tld && (int) $tld->server_id === (int) $record->server_id)));
            if ($errors) {
                throw new InputValidationException($errors);
            }
            $result = $this->orders->place($user, $intent, $contacts, $epp, $nameservers, $registry);
            FormKeys::consume($request, 'order:'.$intent);

            return $this->afterPlace($result);
        } catch (InvalidArgumentException $exception) {
            $errors = $exception instanceof InputValidationException ? $exception->errors : ['enom' => 'Check the details and try again.'];

            return redirect()->route('enom.domains.order', $intent)->withErrors($errors)
                ->withInput([...$retained, '_enom_form' => 'order:'.$intent]);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof PriceChangedException) {
                // Keep what the client typed and show the new price on a fresh quote.
                try {
                    $fresh = $this->orders->quote($user, $record->domain, $record->action, $term);
                    return redirect()->route('enom.domains.order', $fresh['intent_id'])
                        ->withInput([...$retained, '_enom_form' => 'order:'.$fresh['intent_id']])
                        ->with('enom_notice', 'The price changed while you were ordering. Check the new total and submit again.');
                } catch (Throwable) {
                    // A failed refresh returns to search with the pricing message.
                }
            }
            report($exception);
            $message = $exception instanceof PriceChangedException
                ? 'The price changed while you were ordering. Check the name again to see the current price.'
                : 'We couldn’t place this order. Try again, or contact support if it keeps happening.';

            return redirect()->route($record->action === 'transfer' ? 'enom.domains.transfer' : 'enom.domains.search', ['name' => $record->domain])
                ->withErrors(['enom' => $message]);
        }
    }

    private function afterPlace(Invoice|Domain $result)
    {
        if ($result instanceof Invoice) {
            return redirect()->route('invoices.show', $result);
        }

        return redirect()->route('enom.domains.show', $result->id)
            ->with('enom_status', $result->status === 'transfer_pending' ? 'Transfer started. We’ll update this page as it progresses.' : 'Order placed. We’re registering '.$result->domain.' now.');
    }

    private function orderContacts(Request $request): array
    {
        $owner = $this->validContact($request->input('contact'), 'contact', 'registrant');
        $contacts = array_fill_keys(array_keys(self::ROLES), $owner);
        if ($request->input('same_contact') === '1') {
            return $contacts;
        }
        $errors = [];
        foreach (['admin', 'tech', 'auxbilling'] as $role) {
            $input = $request->input('roles.'.$role);
            // The country select always has a value, so it doesn't count as "filled in".
            if (!is_array($input) || !array_filter($input, fn ($value, $field) => $field !== 'country' && is_string($value) && trim($value) !== '', ARRAY_FILTER_USE_BOTH)) {
                continue; // Left blank: use the owner's details.
            }
            try {
                $contacts[$role] = $this->validContact($input, 'roles.'.$role, $role);
            } catch (InputValidationException $exception) {
                $errors += $exception->errors;
            }
        }
        if ($errors) {
            throw new InputValidationException($errors);
        }

        return $contacts;
    }

    private function validContact(mixed $input, string $prefix, string $role): array
    {
        if (!is_array($input)) {
            throw new InputValidationException([$prefix.'.first_name' => DomainManager::CONTACT_MESSAGES['required']]);
        }
        try {
            return DomainManager::validatePayload('contacts', ['confirmed' => '1', 'contact_type' => $role, 'contact' => $input])['contact'];
        } catch (InputValidationException $exception) {
            $errors = [];
            foreach ($exception->errors as $key => $message) {
                $errors[$prefix.substr($key, strlen('contact'))] = str_ends_with($key, '.phone') ? self::PHONE_MESSAGE : $message;
            }
            throw new InputValidationException($errors);
        }
    }

    private function orderInput(Request $request): array
    {
        $roles = [];
        foreach (['admin', 'tech', 'auxbilling'] as $role) {
            $roles[$role] = $this->contactInput($request->input('roles.'.$role));
        }
        $nameservers = $request->input('nameservers');

        $registry = [];
        foreach ((array) $request->input('registry', []) as $field => $value) {
            if (is_string($field) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $field) && is_string($value) && count($registry) < 50) {
                $registry[$field] = substr($value, 0, 255);
            }
        }

        return ['contact' => $this->contactInput($request->input('contact')), 'roles' => $roles, 'registry' => $registry,
            'same_contact' => $request->input('same_contact') === '1' ? '1' : '0',
            'ns_mode' => $request->input('ns_mode') === 'custom' ? 'custom' : 'default',
            'nameservers' => is_string($nameservers) ? substr($nameservers, 0, 4096) : ''];
    }

    public static function registryFields(array $quote, mixed $tld): array
    {
        $fields = $quote['registry_fields'] ?? null;
        if (!is_array($fields) && $tld) {
            try {
                $fields = app(Catalogue::class)->attributesFor($tld);
            } catch (Throwable $exception) {
                report($exception);
                $fields = null;
            }
        }
        $clean = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null) || $field['name'] === '') {
                continue;
            }
            $options = [];
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_array($option) && is_scalar($option['value'] ?? null)) {
                    $options[] = ['value' => (string) $option['value'], 'label' => (string) ($option['label'] ?? $option['value']),
                        'requires' => array_values(array_filter((array) ($option['requires'] ?? []), 'is_string'))];
                }
            }
            $clean[] = ['name' => $field['name'], 'label' => (string) ($field['label'] ?? $field['name']), 'required' => !empty($field['required']),
                'parent' => is_string($field['parent'] ?? null) && $field['parent'] !== '' ? $field['parent'] : null,
                'text' => !empty($field['text']) || !$options, 'options' => $options];
        }
        // A field another field's option requires is that field's child, even when the schema doesn't say so.
        $names = array_column($clean, 'name');
        foreach ($clean as $parent) {
            foreach ($parent['options'] as $option) {
                foreach ($option['requires'] as $required) {
                    $index = array_search($required, $names, true);
                    if ($index !== false && $clean[$index]['parent'] === null && $required !== $parent['name']) {
                        $clean[$index]['parent'] = $parent['name'];
                    }
                }
            }
        }

        return $clean;
    }

    /** Submitted registry details, limited to the ending's fields. */
    private function registryInput(Request $request, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = $request->input('registry.'.$field['name']);
            if (is_string($value) && trim($value) !== '') {
                $values[$field['name']] = trim(substr($value, 0, 255));
            }
        }

        return $values;
    }

    private static function years(mixed $value): int
    {
        $years = is_numeric($value) ? (int) $value : 1;

        return max(1, min(10, $years));
    }

    public static function yearsLabel(int $years): string
    {
        return $years.' '.($years === 1 ? 'year' : 'years');
    }

    /** "1 to 10 years", "2 years". */
    public static function termRange(array $terms): string
    {
        $terms = $terms ?: [1];
        $min = min($terms);
        $max = max($terms);

        return $min === $max ? self::yearsLabel($min) : $min.' to '.$max.' years';
    }

    // ---------------------------------------------------------------- domain page

    public function show(Request $request, int $domain)
    {
        $user = $request->user();
        $model = $this->manager->owned($user, $domain);
        $snapshot = $this->manager->snapshot($user, $domain);
        $keys = [];
        foreach (array_unique([...DomainManager::ACTIONS, ...self::GLUE_ACTIONS, 'refresh', 'renew', 'privacy_purchase', 'privacy_renew', 'renewal_preference']) as $action) {
            $keys[$action] = FormKeys::issue($request, 'domain:'.$domain.':'.$action);
        }
        $privacyPrice = RuntimeGuard::setting((int) $model->server_id, 'privacy_price');

        return view('enom::show', [
            'domain' => $model, 'snapshot' => $snapshot, 'keys' => $keys, 'renewal' => $this->renewalChoices($user, $model, $snapshot),
            'creditsUrl' => \Illuminate\Support\Facades\Route::has('account.credits') ? route('account.credits') : null,
            'privacyPrice' => is_numeric($privacyPrice) && (float) $privacyPrice > 0 ? self::money(Pricing::taxed($user, number_format((float) $privacyPrice, 2, '.', ''), $model->service?->currency_code ?? 'USD')) : null,
            'supportUrl' => \Illuminate\Support\Facades\Route::has('tickets.create') ? route('tickets.create') : null,
        ]);
    }

    /**
     * Renew-now terms with their totals, from DomainOrders::renewalOptions.
     *
     * @return array{options: list<array{years: int, total: ?string}>, per_year: ?string}
     */
    private function renewalChoices(User $user, Domain $domain, array $snapshot): array
    {
        $currency = $snapshot['price']['currency'] ?? 'USD';
        $perYear = $snapshot['price']['amount'] ?? null;
        $years = [1];
        $totals = [];
        $open = null;
        if (!empty($snapshot['capabilities']['renew'])) {
            try {
                $options = $this->orders->renewalOptions($user, (int) $domain->id);
                $years = array_values(array_filter(array_map('intval', (array) ($options['years'] ?? [])), fn ($y) => $y >= 1 && $y <= 10));
                $perYear = $options['price_per_year'] ?? $perYear;
                $totals = is_array($options['totals'] ?? null) ? $options['totals'] : [];
                $open = is_array($options['open_invoice'] ?? null) ? $options['open_invoice'] : null;
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $choices = [];
        foreach ($years as $count) {
            $total = $totals[$count] ?? (is_numeric($perYear) ? number_format((int) round((float) $perYear * 100) * $count / 100, 2, '.', '') : null);
            $choices[] = ['years' => $count, 'total' => self::money($total, $currency)];
        }

        return ['options' => $open ? [] : $choices, 'per_year' => self::money($perYear, $currency), 'open_invoice' => $open];
    }

    private function snapshotDefaults(Domain $domain): array
    {
        $server = (int) $domain->server_id;
        $contacts = DomainManager::safeSnapshot($domain->snapshot ?? [])['contacts'];

        return [
            'default_nameservers' => RuntimeGuard::defaultNameservers($server),
            'dns_tools' => RuntimeGuard::flag($server, 'dns_tools'),
            'test_environment' => RuntimeGuard::isTest($server),
            'registrant_email_masked' => null,
            'epp_email_available_at' => null,
            'contacts_match' => method_exists(DomainManager::class, 'contactsMatch') ? DomainManager::contactsMatch($contacts) : true,
            'price' => ['mode' => 'standard', 'source' => 'client', 'amount' => $domain->service?->price, 'currency' => $domain->service?->currency_code ?? 'USD'],
            'pending_invoice_id' => null,
        ];
    }

    public function manage(Request $request, int $domain, string $action)
    {
        $this->manager->owned($request->user(), $domain);
        abort_unless(in_array($action, DomainManager::ACTIONS, true), 404);
        $key = FormKeys::verify($request, 'domain:'.$domain.':'.$action);
        $tab = self::tabFor($action);
        try {
            $payload = [...$request->except(['_token', 'confirmed']), 'confirmed' => '1', '_request_key' => $key];
            if ($action === 'contacts' && $request->input('apply_all') === '1') {
                $payload['contact_type'] = 'all';
            }
            if (in_array($action, self::GLUE_ACTIONS, true)) {
                $payload['host'] = self::glueHost($request->input('host'), $this->manager->owned($request->user(), $domain)->domain);
                $payload['ip'] = is_string($request->input('ip')) ? trim($request->input('ip')) : '';
            }
            if ($action === 'nameservers' && $request->input('use_default') === '1') {
                $defaults = $this->manager->snapshot($request->user(), $domain)['default_nameservers'] ?? RuntimeGuard::defaultNameservers((int) Domain::query()->whereKey($domain)->value('server_id'));
                if (!$defaults) {
                    throw new InputValidationException(['nameservers' => 'No default nameservers are set up. Enter your own.']);
                }
                $payload['nameservers'] = implode("\n", $defaults);
            }
            $this->manager->request($request->user(), $domain, $action, $payload);
            FormKeys::consume($request, 'domain:'.$domain.':'.$action);

            return $this->toTab($domain, $tab)->with('enom_status', self::submittedMessage($action, $request));
        } catch (Throwable $exception) {
            $response = $this->failure($domain, $tab, $exception, 'We couldn’t save this change. Refresh the page and try again.');
            if ($exception instanceof InvalidArgumentException) {
                $input = ['_enom_form' => 'domain:'.$domain.':'.$action];
                if ($action === 'contacts') {
                    $type = $request->input('contact_type');
                    $input += ['contact' => $this->contactInput($request->input('contact')), 'apply_all' => $request->input('apply_all') === '1' ? '1' : '0',
                        'contact_type' => array_key_exists($type, self::ROLES) ? $type : ''];
                } elseif ($action === 'nameservers') {
                    $value = $request->input('nameservers');
                    $input['nameservers'] = is_string($value) ? substr($value, 0, 4096) : '';
                } elseif (in_array($action, self::GLUE_ACTIONS, true)) {
                    foreach (['host', 'ip'] as $field) {
                        $value = $request->input($field);
                        $input[$field] = is_string($value) ? substr($value, 0, 253) : '';
                    }
                } elseif (in_array($action, ['hosts', 'srv', 'dnssec_add'], true)) {
                    // Ordinary DNS fields only. Never flash arbitrary request input.
                    $input += $this->dnsInput($request, $action);
                }
                $response->withInput($input);
            }

            return $response;
        }
    }

    public function refresh(Request $request, int $domain)
    {
        $this->manager->owned($request->user(), $domain);
        FormKeys::verify($request, 'domain:'.$domain.':refresh');
        $tab = self::queryString($request, 'tab', 20);
        try {
            $this->operations->refreshSnapshot($request->user(), $domain);

            return $this->toTab($domain, $tab)->with('enom_status', 'Domain details updated.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->toTab($domain, $tab)->withErrors(['enom' => 'We couldn’t update the details right now. Showing the last saved details.']);
        }
    }

    public function renew(Request $request, int $domain)
    {
        $this->manager->owned($request->user(), $domain);
        $nonce = FormKeys::verify($request, 'domain:'.$domain.':renew');
        try {
            $years = self::years($request->input('years'));
            if ($years !== 1 && (new \ReflectionMethod($this->orders, 'renew'))->getNumberOfParameters() < 4) {
                throw new InputValidationException(['years' => 'Renewals are one year at a time for now.']);
            }
            $invoice = $this->orders->renew($request->user(), $domain, $nonce, $years);
            FormKeys::consume($request, 'domain:'.$domain.':renew');

            return redirect()->route('invoices.show', $invoice);
        } catch (Throwable $exception) {
            return $this->failure($domain, 'overview', $exception, 'We couldn’t create a renewal invoice right now. Try again later or contact support.');
        }
    }

    public function privacy(Request $request, int $domain, string $action)
    {
        $this->manager->owned($request->user(), $domain);
        abort_unless(in_array($action, ['purchase', 'renew'], true), 404);
        $nonce = FormKeys::verify($request, 'domain:'.$domain.':privacy_'.$action);
        try {
            $invoice = $this->orders->privacy($request->user(), $domain, $action, $nonce);
            FormKeys::consume($request, 'domain:'.$domain.':privacy_'.$action);

            return redirect()->route('invoices.show', $invoice);
        } catch (Throwable $exception) {
            return $this->failure($domain, 'privacy', $exception, 'We couldn’t create an ID Protect invoice right now. Try again later or contact support.');
        }
    }

    public function renewalPreference(Request $request, int $domain)
    {
        $this->manager->owned($request->user(), $domain);
        FormKeys::verify($request, 'domain:'.$domain.':renewal_preference');
        $enabled = $request->input('enabled');
        try {
            if (!in_array($enabled, ['0', '1'], true)) {
                throw new InputValidationException(['enabled' => 'Choose whether the domain renews automatically.']);
            }
            $this->operations->toggleRenewalPreference($request->user(), $domain, $enabled === '1');

            return $this->toTab($domain, 'overview')->with('enom_status', $enabled === '1' ? 'Auto-renew is on.' : 'Auto-renew is off.');
        } catch (Throwable $exception) {
            return $this->failure($domain, 'overview', $exception, $enabled === '1'
                ? 'Auto-renew can’t be turned on right now. The domain needs to be active, not yet expired, with no change still in progress.'
                : 'Auto-renew can’t be changed right now. Try again later or contact support.');
        }
    }

    // ---------------------------------------------------------------- helpers

    private function toTab(int $domain, ?string $tab)
    {
        $url = route('enom.domains.show', $domain);
        if (is_string($tab) && preg_match('/\A[a-z]{2,20}\z/', $tab) && $tab !== 'overview') {
            $url .= '#'.$tab;
        }

        return redirect()->to($url);
    }

    private function failure(int $domain, string $tab, Throwable $exception, string $message)
    {
        if ($exception instanceof InputValidationException) {
            $errors = [];
            foreach ($exception->errors as $key => $message) {
                $errors[$key] = str_ends_with((string) $key, '.phone') ? self::PHONE_MESSAGE : $message;
            }

            return $this->toTab($domain, $tab)->withErrors($errors);
        }
        // Provider and billing exceptions can hold private data. Only our own refusal text (DomainManager and
        // DomainOperations InvalidArgumentException messages, such as the transfer-code cooldown) is shown.
        if ($exception instanceof InvalidArgumentException && in_array($exception->getFile(), [
            (new \ReflectionClass(DomainManager::class))->getFileName(), (new \ReflectionClass(DomainOperations::class))->getFileName()], true)) {
            $message = $exception->getMessage();
        } else {
            report($exception);
        }

        return $this->toTab($domain, $tab)->withErrors(['enom' => $message]);
    }

    /** The form sends the part before the domain ("ns1"); a full hostname under the domain is accepted too. */
    public static function glueHost(mixed $input, string $domain): string
    {
        $host = is_string($input) ? rtrim(strtolower(trim(substr($input, 0, 253))), '.') : '';
        if ($host === '' || $host === $domain || str_ends_with($host, '.'.$domain)) {
            return $host;
        }

        return $host.'.'.$domain;
    }

    public static function tabFor(string $action): string
    {
        return match ($action) {
            'nameservers', 'glue_add', 'glue_update', 'glue_delete' => 'nameservers', 'contacts' => 'contacts',
            'lock', 'epp_sync', 'epp_email' => 'transfer',
            'privacy_enable', 'privacy_disable' => 'privacy',
            'hosts', 'srv', 'dnssec_add', 'dnssec_delete' => 'dns',
            default => 'overview',
        };
    }

    private static function submittedMessage(string $action, Request $request): string
    {
        return match ($action) {
            'nameservers' => 'Nameserver change sent. It can take up to 48 hours to reach everyone on the internet.',
            'contacts' => 'Contact change sent. Check the Activity tab for the result.',
            'lock' => $request->input('locked') === '1' ? 'Turning the transfer lock on.' : 'Turning the transfer lock off.',
            'epp_email' => 'Transfer code requested. Once Enom accepts the request, it emails the code to the domain owner’s address.',
            'epp_sync' => 'Transfer code reset requested.',
            'privacy_enable' => 'Turning ID Protect on.',
            'privacy_disable' => 'Turning ID Protect off.',
            'hosts', 'srv' => 'DNS change sent.',
            'dnssec_add' => 'DS record sent.',
            'dnssec_delete' => 'DS record removal sent.',
            'glue_add' => 'Child nameserver sent to the registry. Check the Activity tab for the result.',
            'glue_update' => 'IP address change sent to the registry.',
            'glue_delete' => 'Child nameserver removal sent.',
            default => 'Change sent.',
        };
    }

    /** @return Collection<int, \Paymenter\Extensions\Servers\Enom\Models\Tld> */
    private function endings(): Collection
    {
        try {
            return app(Catalogue::class)->orderable();
        } catch (Throwable $exception) {
            report($exception);

            return collect();
        }
    }

    /** @return list<array{tld: string, price: ?string}> */
    private function endingOptions(?User $user, Collection $endings, string $action): array
    {
        $options = [];
        foreach ($endings as $tld) {
            try {
                // Guests see public pre-tax prices; clients see their own, tax included.
                $price = self::money($user ? Pricing::taxed($user, $tld->price($action), 'USD') : $tld->price($action));
            } catch (Throwable) {
                $price = null;
            }
            $options[] = ['tld' => $tld->tld, 'price' => $price];
        }

        return $options;
    }

    private function catalogueInTest(Collection $endings): bool|string
    {
        $servers = $endings->pluck('server_id')->unique();
        if ($servers->isEmpty()) {
            $servers = \App\Models\Server::query()->where('extension', 'Enom')->pluck('id');
        }

        $testCount = $servers->filter(fn ($id) => RuntimeGuard::isTest((int) $id))->count();
        return $testCount === 0 ? false : ($testCount === $servers->count() ? true : 'some');
    }

    /**
     * Split typed input into a name and an optional ending. Accepts pasted URLs and a leading www.
     *
     * @return array{sld: ?string, ending: ?string, error: ?string}
     */
    public static function parseName(string $raw): array
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('#\A[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*\z#s', '', $value);
        $value = rtrim(preg_replace('/\Awww\./', '', $value), '.');
        if ($value === '') {
            return ['sld' => null, 'ending' => null, 'error' => 'Enter a name to search for.'];
        }
        if (preg_match('/[^\x21-\x7e]/', $value)) {
            return ['sld' => null, 'ending' => null, 'error' => preg_match('/\s/', $value)
                ? 'Domain names can’t contain spaces.' : 'Use only letters a–z, numbers and hyphens. International characters aren’t supported.'];
        }
        [$sld, $ending] = array_pad(explode('.', $value, 2), 2, null);
        if (strlen($sld) > 63) {
            return ['sld' => null, 'ending' => null, 'error' => 'The name can be at most 63 characters.'];
        }
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $sld) || substr($sld, 2, 2) === '--') {
            return ['sld' => null, 'ending' => null, 'error' => 'Use only letters a–z, numbers and hyphens, without a hyphen at the start or end.'];
        }
        if ($ending !== null && !preg_match('/\A[a-z0-9]+(?:\.[a-z0-9]+)*\z/', $ending)) {
            return ['sld' => $sld, 'ending' => null, 'error' => 'Check the ending after the dot, for example '.$sld.'.com.'];
        }

        return ['sld' => $sld, 'ending' => $ending, 'error' => null];
    }

    private static function endsWithAny(string $ending, array $names): bool
    {
        foreach ($names as $name) {
            if (str_ends_with($ending, '.'.$name)) {
                return true;
            }
        }

        return false;
    }

    public static function listEndings(array $names): string
    {
        $names = array_map(fn ($name) => '.'.$name, $names);
        if (count($names) <= 1) {
            return $names[0] ?? 'no endings yet';
        }
        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    /** Native currency formatting (prefix, separators, suffix). */
    public static function money(mixed $amount, string $currency = 'USD'): ?string
    {
        if (!is_numeric($amount)) {
            return null;
        }
        static $currencies = [];
        $model = $currencies[$currency] ??= Currency::query()->find($currency);
        $value = (float) $amount;
        $number = match ($model?->format) {
            '1.000,00' => number_format($value, 2, ',', '.'),
            '1 000,00' => number_format($value, 2, ',', ' '),
            '1 000.00' => number_format($value, 2, '.', ' '),
            default => number_format($value, 2, '.', ','),
        };

        return $model ? $model->prefix.$number.$model->suffix : $currency.' '.$number;
    }

    /** The client's native profile as a domain contact. */
    public static function profileContact(User $user): array
    {
        $properties = $user->properties()->whereIn('key', ['phone', 'company_name', 'address', 'address2', 'city', 'state', 'zip', 'country'])->pluck('value', 'key');
        $get = fn (string $key) => is_string($properties[$key] ?? null) ? trim($properties[$key]) : '';
        $country = strtoupper($get('country'));
        $phone = $get('phone');
        if ($phone !== '') {
            $phone = DomainManager::phone($phone, $country) ?? $phone;
        }

        return ['first_name' => (string) $user->first_name, 'last_name' => (string) $user->last_name,
            'organization_name' => $get('company_name'), 'address1' => $get('address'), 'address2' => $get('address2'),
            'city' => $get('city'), 'state_province' => $get('state'), 'postal_code' => $get('zip'),
            'country' => $country, 'email_address' => (string) $user->email, 'phone' => $phone];
    }

    private static function queryString(Request $request, string $key, int $max): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? trim(substr($value, 0, $max)) : '';
    }

    private function contactInput(mixed $input): array
    {
        $safe = [];
        foreach (DomainManager::CONTACT_FIELDS as $field) {
            if (is_array($input) && is_string($input[$field] ?? null)) {
                $safe[$field] = substr($input[$field], 0, 255);
            }
        }

        return $safe;
    }

    private function dnsInput(Request $request, string $action): array
    {
        $input = [];
        $fields = $action === 'hosts' ? ['name', 'type', 'address', 'mx_pref', '_delete']
            : ($action === 'srv' ? ['service', 'protocol', 'priority', 'weight', 'port', 'target', 'host_id', '_delete'] : ['key_tag', 'algorithm', 'digest_type', 'digest']);
        $records = $action === 'dnssec_add' ? [$request->input('ds')] : $request->input('records');
        if (is_array($records)) {
            foreach (array_slice($records, 0, 100) as $record) {
                $safe = [];
                foreach ($fields as $field) {
                    if (is_array($record) && is_string($record[$field] ?? null)) {
                        $safe[$field] = substr($record[$field], 0, $field === 'address' ? 2048 : 253);
                    }
                }
                $input['records'][] = $safe;
            }
        }
        if ($action === 'dnssec_add') {
            return ['ds' => $input['records'][0] ?? []];
        }
        $digest = $request->input('preimage_digest');
        $input['preimage_digest'] = is_string($digest) ? substr($digest, 0, 64) : '';

        return $input;
    }

    /** Customer status badge for a domain: [label, tone]. Tone is ok, info, warn, bad or muted. */
    public static function badge(?string $status, ?array $progress = null): array
    {
        $label = $progress['label'] ?? null;
        $kind = $status === 'transfer_pending' ? 'Transferring' : 'Registering';
        if (in_array($status, ['registration_pending', 'transfer_pending'], true)) {
            return match (true) {
                $label === 'Needs your code' => ['Needs your code', 'warn'],
                $label === 'Needs your details' => ['Needs your details', 'warn'],
                !empty($progress['awaiting_payment']) => ['Awaiting payment', 'warn'],
                is_string($label) && str_ends_with($label, 'needs review') => ['Delayed', 'warn'],
                default => [$kind, 'info'],
            };
        }

        if ($status === 'transfer_rejected' && ($progress['safe_code'] ?? null) === 'transfer_code_timeout') {
            return ['Transfer cancelled', 'muted'];
        }

        return match ($status) {
            'active' => ['Active', 'ok'],
            'expired', 'grace' => ['Expired', 'bad'],
            'redemption' => ['Redemption', 'bad'],
            'registration_rejected' => ['Registration failed', 'bad'],
            'transfer_rejected' => ['Transfer failed', 'bad'],
            'needs_review' => ['Needs attention', 'warn'],
            default => [ucfirst(str_replace('_', ' ', (string) ($status ?: 'unknown'))), 'muted'],
        };
    }

    /** Activity row: [what, status label, tone]. */
    public static function activity(array $operation): array
    {
        $action = $operation['action'] ?? null;
        $state = $operation['state'] ?? null;
        $outcome = $operation['outcome'] ?? null;
        $what = match ($action) {
            'register' => 'Registration', 'transfer' => 'Transfer in', 'renew' => 'Renewal',
            'privacy_purchase' => 'ID Protect purchase', 'privacy_renew' => 'ID Protect renewal',
            'nameservers' => 'Nameservers changed', 'contacts' => 'Contacts changed',
            'lock' => 'Transfer lock changed', 'epp_sync' => 'Transfer code reset', 'epp_email' => 'Transfer code email',
            'privacy_enable' => 'ID Protect turned on', 'privacy_disable' => 'ID Protect turned off',
            'hosts' => 'DNS records changed', 'srv' => 'SRV records changed',
            'dnssec_add' => 'DS record added', 'dnssec_delete' => 'DS record removed',
            'provider_renewal_disable' => 'Registrar auto-renew turned off',
            'glue_add' => 'Child nameserver added', 'glue_update' => 'Child nameserver IP changed', 'glue_delete' => 'Child nameserver removed',
            default => ucfirst(str_replace('_', ' ', (string) ($action ?: 'Change'))),
        };
        $term = (int) ($operation['term'] ?? 1);
        if ($term > 1 && in_array($action, ['register', 'renew'], true)) {
            $what .= ', '.self::yearsLabel($term);
        }
        [$label, $tone] = match (true) {
            $state === 'rejected' && in_array($outcome, ['cancelled_unpaid', 'code_timeout'], true) => ['Cancelled', 'muted'],
            $state === 'awaiting_payment' => ['Awaiting payment', 'warn'],
            $state === 'awaiting_code' => ['Needs your code', 'warn'],
            $state === 'held' && $outcome === 'needs_details' => ['Needs your details', 'warn'],
            in_array($state, ['queued', 'running', 'pending'], true) => ['In progress', 'info'],
            in_array($state, ['uncertain', 'held'], true) => ['Being checked', 'warn'],
            $state === 'succeeded' => ['Done', 'ok'],
            $state === 'rejected' => ['Declined', 'bad'],
            $state === 'disposed' => ['Closed by support', 'muted'],
            default => ['Unknown', 'muted'],
        };

        return [$what, $label, $tone];
    }
}

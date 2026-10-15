<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use RuntimeException;
use Throwable;

class DomainController extends Controller
{
    /** Local DomainOrders messages that mean "this name can't be ordered", not "search is down". */
    private const UNAVAILABLE_DOMAIN_MESSAGES = [
        'This domain is unavailable or requires unsupported registry pricing.',
        'This domain already has a portal record.',
    ];

    public function __construct(private DomainManager $manager, private DomainOrders $orders, private DomainOperations $operations) {}

    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? substr($search, 0, 253) : '';
        $domains = Domain::query()->where('user_id', $request->user()->id)
            ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('domain')->paginate(20)->withQueryString();
        // One bulk read for the page: pending registrations and transfers use their verified invoice status.
        $progress = DomainAdministration::progress($domains->getCollection());

        return view('enom::index', ['domains' => $domains, 'progress' => $progress, 'search' => $search, 'searchKey' => FormKeys::issue($request, 'quote')]);
    }

    public function quote(Request $request)
    {
        FormKeys::verify($request, 'quote');
        try {
            $domain = $request->input('domain');
            $action = $request->input('action');
            if (!is_string($domain) || strlen($domain) > 253) {
                throw new InputValidationException(['domain' => 'Enter an ordinary .com domain, for example example.com.']);
            }
            if (!in_array($action, ['register', 'transfer'], true)) {
                throw new InputValidationException(['action' => 'Choose registration or transfer.']);
            }
            try {
                DomainName::parse($domain, ['com']);
            } catch (Throwable) {
                throw new InputValidationException(['domain' => 'Enter an ordinary .com domain without a URL, path or unsupported characters.']);
            }
            $quote = $this->orders->quote($request->user(), $domain, $action, 1);

            return view('enom::order', ['quote' => $quote, 'orderKey' => FormKeys::issue($request, 'order:'.$quote['intent_id'])]);
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.domains.index')->withErrors($exception->errors)
                ->withInput(['domain' => is_string($request->input('domain')) ? substr($request->input('domain'), 0, 253) : '',
                    'action' => in_array($request->input('action'), ['register', 'transfer'], true) ? $request->input('action') : 'register']);
        } catch (Throwable $exception) {
            // Only these exact local messages get specific copy. Anything else, including provider text, stays generic.
            $message = $exception instanceof RuntimeException && in_array($exception->getMessage(), self::UNAVAILABLE_DOMAIN_MESSAGES, true)
                ? 'This domain is not available to order here. It may already be registered or managed here, or it may need premium pricing.'
                : 'Domain search is not available right now. Try again later or contact support.';
            return $this->failure('enom.domains.index', [], $exception, $message)
                ->withInput(['domain' => is_string($request->input('domain')) ? substr($request->input('domain'), 0, 253) : '',
                    'action' => in_array($request->input('action'), ['register', 'transfer'], true) ? $request->input('action') : 'register']);
        }
    }

    public function place(Request $request)
    {
        $intent = $request->input('intent_id');
        abort_unless(is_string($intent) && strlen($intent) <= 100, 422);
        FormKeys::verify($request, 'order:'.$intent);
        try {
            $quote = $this->orders->pendingQuote($request->user(), $intent);
            $contact = $request->input('contact', []);
            if (!is_array($contact)) {
                throw new InputValidationException(['contact' => 'Complete the registration contact fields.']);
            }
            $validated = DomainManager::validatePayload('contacts', ['confirmed' => $request->input('confirmed'), 'contact_type' => 'registrant', 'contact' => $contact]);
            $epp = $request->input('epp');
            if ($quote['action'] === 'transfer' && (!is_string($epp) || $epp === '' || strlen($epp) > 255 || preg_match('/[\x00-\x1f\x7f]/', $epp))) {
                throw new InputValidationException(['epp' => 'Enter a valid transfer authorization code. Re-enter it after correcting other fields.']);
            }
            $contacts = array_fill_keys(['registrant', 'admin', 'tech', 'auxbilling'], $validated['contact']);
            $invoice = $this->orders->place($request->user(), $intent, $contacts, $quote['action'] === 'transfer' ? $epp : null);

            FormKeys::consume($request, 'order:'.$intent);

            return redirect()->route('invoices.show', $invoice);
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.domains.order', $intent)->withErrors($exception->errors)
                ->withInput(['contact' => $this->contactInput($request), '_enom_form' => 'order:'.$intent]);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.index', [], $exception, 'The order could not be created. Search again or contact support.');
        }
    }

    public function order(Request $request, string $intent)
    {
        try {
            $quote = $this->orders->pendingQuote($request->user(), $intent);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.index', [], $exception, 'This quote expired or was already used. Search again for a current price.');
        }

        return view('enom::order', ['quote' => $quote, 'orderKey' => FormKeys::issue($request, 'order:'.$intent)]);
    }

    public function show(Request $request, int $domain)
    {
        $snapshot = $this->manager->snapshot($request->user(), $domain);
        $keys = [];
        foreach ([...DomainManager::ACTIONS, 'refresh', 'renew', 'privacy_purchase', 'privacy_renew', 'renewal_preference'] as $action) {
            $keys[$action] = FormKeys::issue($request, 'domain:'.$domain.':'.$action);
        }

        return view('enom::show', ['snapshot' => $snapshot, 'keys' => $keys]);
    }

    public function manage(Request $request, int $domain, string $action)
    {
        $this->manager->owned($request->user(), $domain);
        abort_unless(in_array($action, DomainManager::ACTIONS, true), 404);
        $key = FormKeys::verify($request, 'domain:'.$domain.':'.$action);
        try {
            $operation = $this->manager->request($request->user(), $domain, $action, [...$request->except('_token'), '_request_key' => $key]);

            FormKeys::consume($request, 'domain:'.$domain.':'.$action);

            return redirect()->route('enom.domains.show', $domain)->with('enom_status', 'Change submitted. Status: '.DomainPresentation::state($operation->state).'. Refresh this page later to see the result.');
        } catch (Throwable $exception) {
            $response = $this->failure('enom.domains.show', [$domain], $exception, 'This change could not be recorded. Refresh the domain status or contact support.');
            if ($exception instanceof InvalidArgumentException) {
                $input = ['_enom_form' => 'domain:'.$domain.':'.$action];
                if ($action === 'contacts') {
                    $type = $request->input('contact_type');
                    $input += ['contact' => $this->contactInput($request), 'contact_type' => in_array($type, ['registrant', 'admin', 'tech', 'auxbilling'], true) ? $type : ''];
                } elseif ($action === 'nameservers') {
                    $value = $request->input('nameservers');
                    $input['nameservers'] = is_string($value) ? substr($value, 0, 4096) : '';
                } elseif (in_array($action, ['hosts', 'srv', 'dnssec_add'], true)) {
                    // These are ordinary DNS fields. Never flash arbitrary request input or EPP.
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
        try {
            $this->operations->refreshSnapshot($request->user(), $domain);

            return redirect()->route('enom.domains.show', $domain)->with('enom_status', 'Status refreshed from the registrar.');
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.show', [$domain], $exception, 'The registrar status could not be read right now. The details below are from the last check.');
        }
    }

    public function renew(Request $request, int $domain)
    {
        $this->manager->owned($request->user(), $domain);
        $nonce = FormKeys::verify($request, 'domain:'.$domain.':renew');
        try {
            $this->confirmed($request);
            $invoice = $this->orders->renew($request->user(), $domain, $nonce);
            FormKeys::consume($request, 'domain:'.$domain.':renew');

            return redirect()->route('invoices.show', $invoice);
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.show', [$domain], $exception, 'A renewal invoice could not be created. Review the domain status or contact support.');
        }
    }

    public function privacy(Request $request, int $domain, string $action)
    {
        $this->manager->owned($request->user(), $domain);
        abort_unless(in_array($action, ['purchase', 'renew'], true), 404);
        $nonce = FormKeys::verify($request, 'domain:'.$domain.':privacy_'.$action);
        try {
            $this->confirmed($request);
            $invoice = $this->orders->privacy($request->user(), $domain, $action, $nonce);
            FormKeys::consume($request, 'domain:'.$domain.':privacy_'.$action);

            return redirect()->route('invoices.show', $invoice);
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.show', [$domain], $exception, 'A privacy invoice could not be created. Refresh privacy status or contact support.');
        }
    }

    public function renewalPreference(Request $request, int $domain)
    {
        $this->manager->owned($request->user(), $domain);
        FormKeys::verify($request, 'domain:'.$domain.':renewal_preference');
        try {
            $this->confirmed($request);
            if (!in_array($request->input('enabled'), ['0', '1'], true)) {
                throw new InputValidationException(['enabled' => 'Choose a renewal preference.']);
            }
            $this->operations->toggleRenewalPreference($request->user(), $domain, $request->input('enabled') === '1');

            return redirect()->route('enom.domains.show', $domain)->with('enom_status', $request->input('enabled') === '1' ? 'Renewal turned back on.' : 'Renewal turned off.');
        } catch (Throwable $exception) {
            return $this->failure('enom.domains.show', [$domain], $exception, 'The renewal setting could not be changed. Turning renewal back on needs an active domain with a future expiry and no request still being checked. Contact support if this continues.');
        }
    }

    private function confirmed(Request $request): void
    {
        if ($request->input('confirmed') !== '1') {
            throw new InputValidationException(['confirmed' => 'Confirm the action before continuing.']);
        }
    }

    private function failure(string $route, array $parameters, Throwable $exception, string $message)
    {
        if ($exception instanceof InputValidationException) {
            return redirect()->route($route, $parameters)->withErrors($exception->errors);
        }
        // Provider and billing exceptions can contain private data. Only local validation text is displayed.
        if ($exception instanceof InvalidArgumentException && $exception->getFile() === (new \ReflectionClass(DomainManager::class))->getFileName()) {
            $message = $exception->getMessage();
        }

        return redirect()->route($route, $parameters)->withErrors(['enom' => $message]);
    }

    private function contactInput(Request $request): array
    {
        $input = $request->input('contact');
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

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'awaiting_payment' => 'awaiting payment', 'queued' => 'waiting', 'running' => 'processing',
            'succeeded' => 'completed', 'rejected' => 'declined', 'uncertain' => 'needs review',
            'held' => 'on hold', 'pending' => 'awaiting registrar confirmation', 'disposed' => 'closed for staff review', default => 'pending',
        };
    }
}

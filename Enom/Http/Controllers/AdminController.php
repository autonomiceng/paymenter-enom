<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Throwable;

class AdminController extends Controller
{
    public function __construct(private DomainOperations $operations, private ?DomainAdministration $administration = null) {}

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);
    }

    /** Display grouping for the operations overview. States themselves are never changed here. */
    public const STATE_GROUPS = [
        'review' => ['uncertain', 'pending', 'running', 'held', 'disposed'],
        'waiting' => ['awaiting_payment', 'queued'],
        'history' => ['succeeded', 'rejected'],
    ];

    public function index(Request $request)
    {
        $this->authorizeStaff($request);
        $show = $request->query('show');
        $show = is_string($show) && isset(self::STATE_GROUPS[$show]) ? $show : null;
        // Never hydrate private funding/cost/policy data for the operations overview.
        $operations = Operation::query()->select(['id', 'domain_id', 'action', 'state', 'safe_code', 'funding_basis',
            'amount', 'currency', 'term', 'invoice_id', 'created_at', 'confirmed_expires_at', 'started_at'])
            ->with('domain:id,domain')->when($show, fn ($query) => $query->whereIn('state', self::STATE_GROUPS[$show]))
            ->latest('id')->paginate(30)->withQueryString();
        $groupCounts = [];
        foreach (self::STATE_GROUPS as $group => $states) {
            $groupCounts[$group] = Operation::query()->whereIn('state', $states)->count();
        }
        $keys = [];
        $fundingBlockers = [];
        $readmissionEligible = [];
        $providerReviews = [];
        foreach ($operations as $operation) {
            $fundingBlockers[$operation->id] = !$operation->domain
                || DomainOperations::hasUnresolvedPeer($operation->domain, $operation->id);
            $readmissionEligible[$operation->id] = $operation->state === 'held' && $operation->action === 'renew'
                && $this->operations->canReadmitHeld($operation, $request->user());
            if ($operation->action === 'renew' && in_array($operation->state, ['uncertain', 'pending', 'disposed'], true)) {
                $providerReviews[$operation->id] = $this->operations->providerReviewData($operation);
            }
            foreach (['reconcile', 'fund', 'readmit', 'provider-fact', 'disposition'] as $action) {
                $keys[$operation->id][$action] = FormKeys::issue($request, 'staff:'.$operation->id.':'.$action);
            }
        }

        $domains = Domain::query()->select(['id', 'domain', 'status', 'service_id', 'user_id'])->orderBy('domain')->get();

        return view('enom::admin', ['operations' => $operations, 'keys' => $keys, 'fundingBlockers' => $fundingBlockers,
            'readmissionEligible' => $readmissionEligible,
            'providerReviews' => $providerReviews, 'retiredInvoicePayments' => $this->operations->retiredInvoicePayments(),
            'canFund' => $request->user()->hasPermission('admin.enom.fund'),
            // One bulk read: pending registrations and transfers use their owner-verified invoice status.
            'domains' => $domains, 'domainProgress' => DomainAdministration::progress($domains),
            'domainCount' => Domain::query()->count(), 'reviewCount' => Operation::query()->where('state', 'uncertain')->count(),
            'show' => $show, 'groupCounts' => $groupCounts]);
    }

    public function reconcile(Request $request, int $operation)
    {
        $this->authorizeStaff($request);
        FormKeys::verify($request, 'staff:'.$operation.':reconcile');
        // Read only the state first. A completed request can only have its charge evidence read,
        // which is private cost data, so it needs the dedicated permission before any cost query.
        $record = Operation::query()->select(['id', 'state'])->findOrFail($operation);
        $chargeCheck = $record->state === 'succeeded';
        if ($chargeCheck) {
            abort_unless($request->user()?->hasPermission('admin.enom.costs'), 403);
        }
        if ($request->input('confirmed') !== '1') {
            return redirect()->route('enom.admin.index')->withErrors(['confirmed' => 'Confirm reconciliation before continuing.']);
        }
        try {
            $result = $this->operations->reconcile($operation);

            return redirect()->route('enom.admin.index')->with('enom_status', $chargeCheck
                ? 'Charge evidence checked for request #'.$operation.'. Open its domain page to see the cost result. Nothing was resent.'
                : 'Registrar evidence checked for request #'.$operation.'. Status: '.DomainPresentation::state($result->state).'. Nothing was resent.');
        } catch (Throwable $exception) {
            return redirect()->route('enom.admin.index')->withErrors(['enom' => 'Reconciliation is unavailable. The request stays recorded for review. Check that registrar reads are allowed on the Enom server.']);
        }
    }

    public function fund(Request $request, int $operation)
    {
        $this->authorizeStaff($request);
        abort_unless($request->user()->hasPermission('admin.enom.fund'), 403);
        FormKeys::verify($request, 'staff:'.$operation.':fund');
        $record = Operation::query()->findOrFail($operation);
        abort_if($record->invoice_id !== null, 403, 'Customer invoice funding requires settled payment.');
        $reason = $request->input('reason');
        $normalized = is_string($reason) ? preg_replace('/\r\n|\r|\n/', ' ', $reason) : '';
        $errors = [];
        if ($request->input('confirmed') !== '1') { $errors['confirmed'] = 'Confirm funding before continuing.'; }
        if (!is_string($reason) || strlen(trim($normalized)) < 10 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $normalized)) {
            $errors['reason'] = 'Enter a funding reason of 10 to 500 characters without control characters.';
        }
        if ($request->input('amount') !== (string) $record->amount || $request->input('currency') !== (string) $record->currency) {
            $errors['enom'] = 'Reload the page and review the exact funding amount and currency.';
        }
        if ($errors) {
            return redirect()->route('enom.admin.index')->withErrors($errors)
                ->withInput(['reason' => is_string($reason) ? substr($reason, 0, 500) : '', '_enom_form' => 'staff:'.$operation.':fund']);
        }
        try {
            $this->operations->approveFunding($operation, $request->user(), trim($normalized));
            FormKeys::consume($request, 'staff:'.$operation.':fund');

            return redirect()->route('enom.admin.index')->with('enom_status', 'Operator funding authorization recorded for this operation.');
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.admin.index')->withErrors($exception->errors)
                ->withInput(['reason' => substr($reason, 0, 500), '_enom_form' => 'staff:'.$operation.':fund']);
        } catch (Throwable $exception) {
            return redirect()->route('enom.admin.index')->withErrors(['enom' => 'Funding could not be authorized. Review payment and registrar evidence first.']);
        }
    }

    public function readmit(Request $request, int $operation)
    {
        $this->authorizeStaff($request);
        $context = 'staff:'.$operation.':readmit';
        FormKeys::verify($request, $context);
        $reason = $request->input('reason');
        $normalized = is_string($reason) ? preg_replace('/\r\n|\r|\n/', ' ', $reason) : '';
        $old = ['_enom_form' => $context, 'reason' => is_string($reason) ? substr($reason, 0, 500) : ''];
        $errors = [];
        if ($request->input('confirmed') !== '1') { $errors['confirmed'] = 'Confirm the reviewed unsent renewal before continuing.'; }
        if (!is_string($reason) || strlen(trim($normalized)) < 10 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $normalized)) {
            $errors['reason'] = 'Enter a review reason of 10 to 500 characters without control characters.';
        }
        if ($errors) {
            return redirect()->route('enom.admin.index')->withErrors($errors)->withInput($old);
        }
        try {
            $this->operations->readmitHeld($operation, $request->user(), trim($normalized));
            FormKeys::consume($request, $context);
            return redirect()->route('enom.admin.index')->with('enom_status', 'The verified unsent renewal was queued again under its existing funded obligation. Current registrar admission checks still apply.');
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.admin.index')->withErrors($exception->errors)->withInput($old);
        } catch (Throwable $exception) {
            return redirect()->route('enom.admin.index')->withErrors(['enom' => 'This held renewal cannot be resumed. Review its funding, current binding and registrar-send evidence.'])->withInput($old);
        }
    }

    public function providerFact(Request $request, int $operation)
    {
        return $this->providerReviewAction($request, $operation, 'provider-fact');
    }

    public function disposition(Request $request, int $operation)
    {
        return $this->providerReviewAction($request, $operation, 'disposition');
    }

    private function providerReviewAction(Request $request, int $operation, string $action)
    {
        $this->authorizeStaff($request);
        $context = 'staff:'.$operation.':'.$action;
        FormKeys::verify($request, $context);
        $old = ['_enom_form' => $context];
        foreach (['reason', 'outcome', 'financial'] as $field) {
            if (is_string($request->input($field))) { $old[$field] = substr($request->input($field), 0, $field === 'reason' ? 500 : 100); }
        }
        try {
            if ($request->input('confirmed') !== '1') { throw new InputValidationException(['confirmed' => 'Confirm this staff review action before continuing.']); }
            if ($action === 'provider-fact') {
                $this->operations->observeProviderFact($operation, $request->user());
                $message = 'Authenticated provider facts recorded. Funding, service status and the registrar request remain unchanged.';
            } else {
                foreach (['fact_digest', 'outcome', 'financial', 'reason'] as $field) {
                    if (!is_string($request->input($field))) { throw new InputValidationException([$field => 'Choose the explicit staff review disposition and provide its reason.']); }
                }
                $reason = preg_replace('/\r\n|\r|\n/', ' ', $request->input('reason'));
                $this->operations->disposeProviderFact($operation, $request->user(), $request->input('fact_digest'), $request->input('outcome'), $request->input('financial'), $reason);
                $message = 'Provider fact and retained financial review recorded. All domain registrar writes and renewal billing remain paused for separate binding review.';
            }
            FormKeys::consume($request, $context);
            return redirect()->route('enom.admin.index')->with('enom_status', $message);
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.admin.index')->withErrors($exception->errors)->withInput($old);
        } catch (Throwable $exception) {
            return redirect()->route('enom.admin.index')->withErrors(['enom' => 'Provider facts or disposition require further review. No request was resent or spending authority granted.'])->withInput($old);
        }
    }

    private function administration(): DomainAdministration
    {
        return $this->administration ?? app(DomainAdministration::class);
    }

    public function domain(Request $request, int $domain)
    {
        $this->authorizeStaff($request);
        $data = $this->administration()->get($request->user(), $domain);
        $keys = [];
        foreach (['policy', 'sponsorship', 'refresh', 'sponsored-renewal'] as $action) {
            $keys[$action] = FormKeys::issue($request, 'staff-domain:'.$domain.':'.$action);
        }
        // Charge-evidence checks reuse the existing reconcile route and need the dedicated cost permission.
        if ($request->user()->hasPermission('admin.enom.costs')) {
            foreach ($data['costs'] ?? [] as $cost) {
                if (!empty($cost['checkable']) && is_int($cost['operation_id'] ?? null)) {
                    $keys['charge'][$cost['operation_id']] = FormKeys::issue($request, 'staff:'.$cost['operation_id'].':reconcile');
                }
            }
        }

        return view('enom::admin-domain', ['domain' => $data, 'keys' => $keys]);
    }

    public function policy(Request $request, int $domain)
    {
        return $this->domainAction($request, $domain, 'policy', ['billing_managed', 'enabled', 'issue_days', 'due_days', 'credits_auto_use'],
            fn ($input) => $this->administration()->savePolicy($request->user(), $domain, $input),
            'Renewal settings saved. Invoices already issued keep their own dates.');
    }

    public function sponsorship(Request $request, int $domain)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.fund'), 403);

        return $this->domainAction($request, $domain, 'sponsorship', ['enabled', 'standing', 'limit_amount', 'currency', 'term', 'reason'],
            fn ($input) => $this->administration()->saveSponsorship($request->user(), $domain, $input),
            'Sponsorship approval saved. Nothing was bought and no registrar request was made.');
    }

    public function refresh(Request $request, int $domain)
    {
        return $this->domainAction($request, $domain, 'refresh', [],
            fn () => $this->administration()->refreshObservation($request->user(), $domain),
            'Registrar check finished. Review the check result and dates below. Nothing was bought, approved or changed.', false);
    }

    public function sponsoredRenewal(Request $request, int $domain)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.fund'), 403);

        return $this->domainAction($request, $domain, 'sponsored-renewal', [], function () use ($request, $domain) {
            $operation = $this->administration()->requestSponsoredRenewal($request->user(), $domain);
            if (!$operation) {
                throw new InputValidationException(['enom' => 'No renewal was requested. It needs an approval for this cycle, current eligibility and a fresh complete registrar quote at or under the limit.']);
            }
        }, 'Sponsored renewal request recorded for this expiry. A repeat request returns the same record. The registrar result appears on Enom administration.');
    }

    private function domainAction(Request $request, int $domain, string $action, array $fields, callable $work, string $message, bool $requireConfirmation = true)
    {
        $this->authorizeStaff($request);
        $context = 'staff-domain:'.$domain.':'.$action;
        FormKeys::verify($request, $context);
        // Resolve permission and domain identity before accepting a mutation.
        $this->administration()->get($request->user(), $domain);
        $input = $request->only($fields);
        $old = ['_enom_form' => $context];
        foreach ($input as $field => $value) {
            if (is_string($value)) { $old[$field] = substr($value, 0, $field === 'reason' ? 500 : 100); }
        }
        try {
            if ($requireConfirmation && $request->input('confirmed') !== '1') {
                throw new InputValidationException(['confirmed' => 'Confirm this action before continuing.']);
            }
            $work($input);
            FormKeys::consume($request, $context);

            return redirect()->route('enom.admin.domain', $domain)->with('enom_status', $message);
        } catch (InputValidationException $exception) {
            return redirect()->route('enom.admin.domain', $domain)->withErrors($exception->errors)->withInput($old);
        } catch (Throwable $exception) {
            return redirect()->route('enom.admin.domain', $domain)->withErrors(['enom' => 'This action is unavailable. Review the domain evidence and policy before continuing.'])->withInput($old);
        }
    }
}

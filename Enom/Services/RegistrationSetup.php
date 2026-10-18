<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Helpers\NotificationHelper;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

/** Account contact snapshots and the separate nameserver change after registration. */
final class RegistrationSetup
{
    private const ROLES = ['registrant', 'admin', 'tech', 'auxbilling'];
    private const FIELDS = [
        'first_name' => 'first name', 'last_name' => 'last name', 'organization_name' => 'organization',
        'address1' => 'address', 'address2' => 'second address line', 'city' => 'city',
        'state_province' => 'state or region', 'postal_code' => 'postcode', 'country' => 'country',
        'email_address' => 'email address', 'phone' => 'phone number',
    ];

    public static function boot(): void
    {
        require __DIR__.'/../routes/registration-setup.php';
    }

    public static function payload(User $user, int $serverId, string $action): array
    {
        if (!in_array($action, ['register', 'transfer'], true)) {
            throw new InvalidArgumentException('Choose registration or transfer.');
        }
        $nameservers = $action === 'register' ? RuntimeGuard::defaultNameservers($serverId) : [];
        if ($nameservers) {
            $nameservers = DomainManager::validatePayload('nameservers', ['confirmed' => true, 'nameservers' => $nameservers])['nameservers'];
        }

        return ['contacts' => array_fill_keys(self::ROLES, self::accountContact($user)), 'nameservers' => $nameservers];
    }

    private static function accountContact(User $user): array
    {
        $properties = $user->properties()->whereIn('key', ['company_name', 'address', 'address2', 'city', 'state', 'zip', 'country', 'phone'])->pluck('value', 'key');
        $get = fn (string $key) => is_string($properties[$key] ?? null) ? $properties[$key] : '';
        $country = self::country($get('country'));
        $phone = $get('phone');

        $contact = ['first_name' => (string) $user->first_name, 'last_name' => (string) $user->last_name,
            'organization_name' => $get('company_name'), 'address1' => $get('address'), 'address2' => $get('address2'),
            'city' => $get('city'), 'state_province' => $get('state'), 'postal_code' => $get('zip'),
            'country' => $country, 'email_address' => (string) $user->email,
            'phone' => $phone];
        try {
            $validated = DomainManager::validatePayload('contacts', ['confirmed' => true, 'contact_type' => 'all', 'contact' => $contact]);
            return array_intersect_key($validated['contact'], self::FIELDS);
        } catch (InputValidationException) {
            return $contact;
        }
    }

    private static function country(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) { return ''; }
        $value = trim($value);
        $countries = config('app.countries', []);
        $code = strtoupper($value);
        if (preg_match('/\A[A-Z]{2}\z/', $code) && isset($countries[$code])) { return $code; }
        $matches = array_keys(array_filter($countries, fn ($label, $key) => preg_match('/\A[A-Z]{2}\z/', (string) $key)
            && is_string($label) && strcasecmp(trim($label), $value) === 0, ARRAY_FILTER_USE_BOTH));

        return count($matches) === 1 ? $matches[0] : '';
    }

    public static function validate(Operation $operation): void
    {
        if (!in_array($operation->action, ['register', 'transfer'], true)) { return; }
        self::validateContacts($operation->payload['contacts'] ?? null);
    }

    private static function validateContacts(mixed $contacts): void
    {
        $errors = [];
        foreach (self::ROLES as $role) {
            $label = $role === 'auxbilling' ? 'billing' : $role;
            $contact = is_array($contacts) && is_array($contacts[$role] ?? null) ? $contacts[$role] : [];
            try {
                DomainManager::validatePayload('contacts', ['confirmed' => true, 'contact_type' => $role, 'contact' => $contact]);
            } catch (InputValidationException $error) {
                foreach ($error->errors as $key => $message) {
                    $field = substr($key, strlen('contact.'));
                    if (isset(self::FIELDS[$field])) { $errors['contacts.'.$role.'.'.$field] = 'Check the '.$label.' contact '.self::FIELDS[$field].'.'; }
                }
            }
            if (in_array(strtoupper(trim(is_string($contact['country'] ?? null) ? $contact['country'] : '')), ['US', 'CA', 'AU'], true)
                && (!is_string($contact['state_province'] ?? null) || trim($contact['state_province']) === '')) {
                $errors['contacts.'.$role.'.state_province'] = 'Complete the '.$label.' contact state or region.';
            }
        }
        if ($errors) { throw new InputValidationException($errors); }
    }

    /** Called by the payment and dispatch gates after contact validation fails. */
    public static function hold(Operation $operation): void
    {
        $ownerId = Domain::whereKey($operation->domain_id)->value('user_id');
        DB::transaction(function () use ($operation, $ownerId) {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->where('domain_id', $domain->id)->lockForUpdate()->firstOrFail();
            if (!in_array($current->action, ['register', 'transfer'], true)
                || !in_array($current->state, ['awaiting_payment', 'awaiting_code', 'queued', 'running', 'held'], true)
                || $domain->status !== ($current->action === 'register' ? 'registration_pending' : 'transfer_pending')
                || !in_array($domain->service?->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
                || !self::unsent($current, $domain) || !app(DomainOperations::class)->funded($current)) { return; }
            try { self::validate($current); return; }
            catch (InputValidationException $error) { $fields = self::fieldLabels(array_keys($error->errors)); }
            $policy = $current->policy ?? [];
            $current->update(['state' => 'held', 'safe_code' => 'contact_details_required', 'lease_expires_at' => null]);
            if (!empty($policy['contact_hold_notified_at'])) { return; }
            $notice = new NotificationTemplate(['in_app_title' => '{{ $domain }} needs account details',
                'in_app_body' => 'The account contact details need attention: {{ $fields }}. The order is on hold until the customer uses updated account details.']);
            foreach (User::whereHas('role')->get() as $staff) {
                if ($staff->hasPermission('admin.enom.manage')) {
                    NotificationHelper::sendInAppNotification($notice, ['domain' => $domain->domain, 'fields' => implode(', ', $fields)], $staff);
                }
            }
            $policy['contact_hold_notified_at'] = now()->toIso8601String();
            $current->update(['policy' => $policy]);
        });
    }

    private static function unsent(Operation $operation, Domain $domain): bool
    {
        return $domain->billing_managed && (int) $operation->service_id === (int) $domain->service_id
            && !array_key_exists('dispatch_attempted_at', $operation->policy ?? [])
            && !array_key_exists('execution', $operation->cost ?? []) && $operation->provider_order_id === null
            && $operation->completed_at === null;
    }

    public static function replaceContacts(User $actor, int $domainId): Operation
    {
        return self::replaceAccountContacts($actor, $domainId);
    }

    public static function canResumeContacts(Operation $operation, User $staff): bool
    {
        try {
            $operation = $operation->fresh() ?? $operation;
            return $staff->hasPermission('admin.enom.manage') && $operation->domain
                && self::contactRecoveryAllowed($operation, $operation->domain)
                && count($operation->policy['contact_readmissions'] ?? []) < 10;
        } catch (\Throwable) { return false; }
    }

    /** Staff use the owner's current details, with the same unsent and initial dispatch gates. */
    public static function resumeContacts(User $staff, int $operationId, string $reason): Operation
    {
        abort_unless($staff->hasPermission('admin.enom.manage'), 403);
        $reason = trim($reason);
        if (strlen($reason) < 5 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InputValidationException(['reason' => 'Enter a review reason between 5 and 500 bytes.']);
        }
        $domainId = Operation::findOrFail($operationId)->domain_id;
        return self::replaceAccountContacts($staff, $domainId, $operationId, $reason);
    }

    private static function contactRecoveryAllowed(Operation $operation, Domain $domain): bool
    {
        $service = $domain->service;
        return in_array($operation->action, ['register', 'transfer'], true)
            && $operation->state === 'held' && $operation->safe_code === 'contact_details_required'
            && self::unsent($operation, $domain)
            && $domain->status === ($operation->action === 'register' ? 'registration_pending' : 'transfer_pending')
            && $service && (int) $service->user_id === (int) $domain->user_id
            && in_array($service->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
            && app(DomainOperations::class)->funded($operation);
    }

    private static function replaceAccountContacts(User $actor, int $domainId, ?int $operationId = null, ?string $reason = null): Operation
    {
        $ownerId = $operationId === null ? $actor->id : Domain::whereKey($domainId)->value('user_id');
        $operation = DB::transaction(function () use ($actor, $domainId, $ownerId, $operationId, $reason) {
            $owner = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($domainId)->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $service = $domain->service;
            abort_unless($service && (int) $service->user_id === (int) $owner->id
                && (int) $service->product?->server_id === (int) $domain->server_id
                && $service->product?->server?->extension === 'Enom', 404);
            $operation = $domain->operations()->where('service_id', $service->id)->whereIn('action', ['register', 'transfer'])
                ->when($operationId !== null, fn ($query) => $query->whereKey($operationId))
                ->orderBy('id')->lockForUpdate()->firstOrFail();
            $operation->setRelation('domain', $domain);
            if (!self::contactRecoveryAllowed($operation, $domain)
                || $operationId !== null && count($operation->policy['contact_readmissions'] ?? []) >= 10) {
                throw new InvalidArgumentException('Account details cannot be replaced for this order.');
            }
            $contacts = array_fill_keys(self::ROLES, self::accountContact($owner));
            self::validateContacts($contacts);
            $payload = $operation->payload ?? [];
            $payload['contacts'] = $contacts;
            $changes = ['payload' => $payload];
            if ($operationId !== null) {
                $policy = $operation->policy ?? [];
                $policy['contact_readmissions'][] = ['staff_id' => (int) $actor->id, 'reason' => $reason,
                    'at' => now()->toIso8601String(), 'previous_code' => $operation->safe_code];
                $changes['policy'] = $policy;
            }
            $operation->update($changes);
            DB::afterCommit(fn () => app(DomainOperations::class)->resumeInitial((int) $operation->id));

            return $operation;
        });

        return $operation->fresh();
    }

    public static function queueNameservers(Operation $registration): ?Operation
    {
        $ownerId = Domain::whereKey($registration->domain_id)->value('user_id');
        // Confirmation may already have purged contacts and codes from the stored payload.
        $snapshot = $registration->payload['nameservers'] ?? [];
        return DB::transaction(function () use ($registration, $ownerId, $snapshot) {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($registration->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($registration->id)->where('domain_id', $domain->id)->lockForUpdate()->firstOrFail();
            $service = $domain->service;
            if ($current->action !== 'register' || $current->state !== 'succeeded' || !$domain->billing_managed || $domain->status !== 'active'
                || !$service || $service->status !== Service::STATUS_ACTIVE || (int) $service->user_id !== (int) $domain->user_id
                || (int) $current->service_id !== (int) $service->id || (int) $service->product?->server_id !== (int) $domain->server_id
                || $service->product?->server?->extension !== 'Enom' || $current->currency !== $service->currency_code) { return null; }
            $policy = $current->policy ?? [];
            if (($policy['domain'] ?? null) !== $domain->domain || ($policy['user_id'] ?? null) !== (int) $domain->user_id
                || ($policy['service_id'] ?? null) !== (int) $service->id || ($policy['server_id'] ?? null) !== (int) $domain->server_id
                || ($policy['authority_generation'] ?? null) !== $domain->authority_generation
                || ($policy['account_fingerprint'] ?? null) !== RuntimeGuard::serverFingerprint($service->product->server)) { return null; }
            $key = 'initial-nameservers:'.$current->id;
            if ($follow = Operation::where('operation_key', $key)->first()) {
                return (int) $follow->domain_id === (int) $domain->id && (int) $follow->service_id === (int) $service->id
                    && $follow->action === 'nameservers' ? $follow : null;
            }
            $nameservers = $current->payload['nameservers'] ?? $snapshot;
            if (!$nameservers) { return null; }
            $nameservers = DomainManager::validatePayload('nameservers', ['confirmed' => true, 'nameservers' => $nameservers])['nameservers'];
            $follow = Operation::create(['operation_key' => $key, 'domain_id' => $domain->id, 'service_id' => $service->id,
                'action' => 'nameservers', 'term' => 1, 'amount' => '0.00', 'currency' => $current->currency,
                'funding_basis' => 'non_billable_management', 'authorized_by' => $domain->user_id, 'state' => 'queued',
                'payload' => ['nameservers' => $nameservers], 'policy' => []]);
            ExecuteOperation::dispatch($follow->id)->afterCommit();

            return $follow;
        });
    }

    private static function fieldLabels(array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            $field = substr($key, strrpos($key, '.') + 1);
            if (isset(self::FIELDS[$field])) { $fields[] = self::FIELDS[$field]; }
        }

        return array_values(array_unique($fields));
    }

    /** Safe data for the owner-only pending domain page. */
    public static function viewData(int $domainId): array
    {
        $held = Operation::where('domain_id', $domainId)->whereIn('action', ['register', 'transfer'])
            ->where('state', 'held')->where('safe_code', 'contact_details_required')->first();
        $fields = [];
        if ($held) {
            try { self::validate($held); }
            catch (InputValidationException $error) { $fields = self::fieldLabels(array_keys($error->errors)); }
        }
        $follow = Operation::where('domain_id', $domainId)->where('action', 'nameservers')
            ->where('operation_key', 'like', 'initial-nameservers:%')->latest('id')->first();

        return ['held' => $held !== null, 'action' => $held?->action, 'fields' => $fields,
            'nameservers_failed' => $follow && in_array($follow->state, ['held', 'rejected', 'uncertain'], true)];
    }
}

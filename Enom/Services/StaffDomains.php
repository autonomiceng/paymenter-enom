<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Throwable;

/**
 * Staff actions on any client's domain. Each method checks its own permission, records the
 * staff member as the requester and goes through the same queue and checks as client requests.
 */
class StaffDomains
{
    public function __construct(private DomainOperations $operations) {}

    private function authorize(User $staff, string $permission = 'admin.enom.manage'): void
    {
        if (!$staff->hasPermission('admin.enom.manage') || !$staff->hasPermission($permission)) {
            throw new AuthorizationException('You do not have permission to do this.');
        }
    }

    /** Queue a registrar change (nameservers, contacts, lock, epp_email, ID Protect, DNS tools). */
    public function requestChange(User $staff, int $domainId, string $action, array $payload): Operation
    {
        $this->authorize($staff);
        if (!in_array($action, DomainManager::ACTIONS, true)) { throw new InvalidArgumentException('Choose a supported change.'); }
        Domain::findOrFail($domainId);
        $nonce = is_string($payload['_request_key'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $payload['_request_key'])
            ? $payload['_request_key'] : bin2hex(random_bytes(32));
        unset($payload['_request_key']);
        return $this->operations->enqueueManagement($staff, $domainId, $action, $payload, $nonce);
    }

    /**
     * Renew this cycle now. Complimentary domains queue a renewal at the reseller's cost;
     * others get the client's renewal invoice (the same one the daily run would issue).
     */
    public function renewNow(User $staff, int $domainId): Invoice|Operation
    {
        $this->authorize($staff);
        $domain = Domain::findOrFail($domainId);
        if (Pricing::isComplimentary($domain)) {
            $this->authorize($staff, 'admin.enom.fund');
            return app(DomainBilling::class)->complimentary($domainId, $staff)
                ?? throw new InvalidArgumentException('This expiry cycle already has a renewal or an invoice.');
        }
        return app(DomainBilling::class)->invoice($domainId);
    }

    /** Who pays: inherit (client default), standard, custom (with price) or complimentary. */
    public function setPricing(User $staff, int $domainId, string $mode, ?string $price = null): Domain
    {
        $this->authorize($staff);
        $ownerId = Domain::whereKey($domainId)->value('user_id');
        return DB::transaction(function () use ($staff, $domainId, $mode, $price, $ownerId) {
            // Decide under the client lock so a concurrent grant can't change the effective result.
            User::whereKey($ownerId)->lockForUpdate()->first();
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            // Judge by the effective result: "follow client default" can make a domain complimentary too.
            $before = Pricing::mode($domain);
            $after = $mode === 'inherit' ? (Pricing::clientComplimentary(User::find($domain->user_id)) ? 'complimentary' : 'standard') : $mode;
            if ($before === 'complimentary' || $after === 'complimentary') { $this->authorize($staff, 'admin.enom.fund'); }
            Pricing::setDomainMode($domain, $mode, $price);
            return $domain->fresh();
        });
    }

    public function setClientComplimentary(User $staff, User $client, bool $on, ?int $maxDomains = null): void
    {
        $this->authorize($staff, 'admin.enom.fund');
        if ($maxDomains !== null && ($maxDomains < 1 || $maxDomains > 10000)) { throw new InvalidArgumentException('Enter a domain limit from 1 to 10000, or leave it blank.'); }
        Pricing::setClientComplimentary($client, $on, $staff, $maxDomains);
    }

    /** Reads the account balance. Never changes anything. */
    public function testConnection(int $serverId): array
    {
        $status = RuntimeGuard::status($serverId);
        try {
            $api = $this->operations->apiForServer($serverId, true);
            $result = $api->balance();
            if ($result->status !== 'success') {
                $message = $result->value('errors.err1');
                return $status + ['ok' => false, 'message' => is_string($message) && $message !== ''
                    ? 'Enom says: '.mb_substr($message, 0, 200) : 'Enom did not accept the request. Check the account ID, API token and IP allowlist.'];
            }
            $balance = $result->value('availablebalance') ?? $result->value('balance');
            return $status + ['ok' => true, 'message' => 'Connected to Enom.',
                'balance' => is_string($balance) ? RegistrarMoney::decimal(str_replace(',', '', trim($balance))) : null];
        } catch (Throwable $error) {
            return $status + ['ok' => false, 'message' => $error->getMessage() ?: 'Could not reach Enom.'];
        }
    }

    /** Refresh registrar details for any domain (read only). */
    public function refresh(User $staff, int $domainId): Domain
    {
        $this->authorize($staff);
        return $this->operations->refreshDomain($domainId);
    }

    /** Renewal on/off for any domain, as the client's own switch would do. */
    public function setRenewal(User $staff, int $domainId, bool $enabled): Domain
    {
        $this->authorize($staff);
        $domain = Domain::findOrFail($domainId);
        return $this->operations->toggleRenewalPreference(User::findOrFail($domain->user_id), $domainId, $enabled);
    }
}

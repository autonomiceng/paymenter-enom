<?php

namespace Paymenter\Extensions\Servers\Enom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainObservation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

/** Read a domain's registrar details (nameservers, contacts, lock, ID Protect) so its management screens are ready. */
class RefreshDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 300;
    public function __construct(public int $domainId, public ?int $operationId = null) {}
    public function backoff(): array { return [60, 300]; }
    public function handle(DomainOperations $operations): void
    {
        if ($this->eligible()) { $operations->refreshDomain($this->domainId); }
    }
    public function failed(?\Throwable $exception): void
    {
        if ($this->eligible()) { app(DomainObservation::class)->recordFailure($this->domainId, 'refresh_failed'); }
    }
    private function eligible(): bool
    {
        $domain = Domain::find($this->domainId);
        if (!$domain || $domain->status !== 'active') { return false; }
        // Keep domain-only jobs already in the queue compatible.
        if (($this->operationId ?? null) === null) { return true; }
        return Operation::whereKey($this->operationId)->where('domain_id', $this->domainId)
            ->where('service_id', $domain->service_id)->where('state', 'succeeded')->where('safe_code', 'expiry_confirmed')
            ->whereNotNull('confirmed_expires_at')->whereIn('action', ['register', 'transfer'])->exists();
    }
}

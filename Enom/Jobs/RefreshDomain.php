<?php

namespace Paymenter\Extensions\Servers\Enom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

/** Read a domain's registrar details (nameservers, contacts, lock, ID Protect) so its management screens are ready. */
class RefreshDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 300;
    public function __construct(public int $domainId) {}
    public function backoff(): array { return [60, 300]; }
    public function handle(DomainOperations $operations): void { $operations->refreshDomain($this->domainId); }
}

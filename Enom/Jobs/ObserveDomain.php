<?php

namespace Paymenter\Extensions\Servers\Enom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\Enom\Services\DomainObservation;

class ObserveDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 0;
    public int $timeout = 150;
    public function __construct(public int $domainId) {}
    public function retryUntil(): \DateTimeInterface { return now()->addHours(24); }
    public function handle(DomainObservation $observation): void
    {
        $result = $observation->observe($this->domainId);
        if (in_array($result, ['busy', 'rate_limited'], true)) { $this->release(65); }
    }
}

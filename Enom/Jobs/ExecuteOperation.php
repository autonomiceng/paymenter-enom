<?php

namespace Paymenter\Extensions\Servers\Enom\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;

class ExecuteOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    // Six admission reads plus dispatch/readback/cost reads fit ten request
    // timeouts. The claim survives the worker timeout and cleanup margin.
    public const EXECUTION_TIMEOUT_SECONDS = 10 * RegistrarClient::REQUEST_TIMEOUT_SECONDS + 50;
    // Leave evidence persistence and domain/operation lock waits ahead of the worker alarm.
    public const FINISH_SECONDS = 30;
    public const CLAIM_LEASE_SECONDS = self::EXECUTION_TIMEOUT_SECONDS + 60;
    public int $tries = 1;
    public int $timeout = self::EXECUTION_TIMEOUT_SECONDS;
    public function __construct(public int $operationId) {}
    public function handle(DomainOperations $operations): void { $operations->execute($this->operationId); }
}

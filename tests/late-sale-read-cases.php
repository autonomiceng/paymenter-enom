<?php
use Paymenter\Extensions\Servers\Enom\Models\Tld;

// The admission filter succeeds; a later real sale read throws while projecting the row.
$healthy = $catalogue->orderable()->whereIn('tld', ['org', 'xyz'])->values();
$healthyRows = $catalogue->publicPrices($healthy);
verify(array_column($healthyRows, 'tld') === ['org', 'xyz'], 'Late-sale fixture lacks healthy neighboring endings.');
foreach (['terms' => ['register', 1], 'register' => ['register', 2], 'transfer' => ['transfer', 1]] as $phase => [$action, $read]) {
    $lateRead = new class extends Tld {
        public ?string $failAction = null;
        public int $failRead = 0;
        public int $reads = 0;
        public function cost(string $action, int $years = 1): ?string
        {
            if ($action === $this->failAction && ++$this->reads === $this->failRead) {
                throw new RuntimeException('Synthetic late sale read failure.');
            }
            return parent::cost($action, $years);
        }
    };
    $lateRead->setRawAttributes($queryTlds['com']->fresh()->getAttributes(), true);
    $lateRead->max_years = 1;
    verify($lateRead->orderable(), 'Late-sale filter did not succeed before the injected failure.');
    $lateRead->failAction = $action; $lateRead->failRead = $read;
    $reportsBefore = count($reportedSales);
    $rows = $catalogue->publicPrices(collect([$lateRead, ...$healthy->all()]));
    verify($rows === $healthyRows, 'Later '.$phase.' sale failure left a public row or changed healthy endings.');
    verify(count($reportedSales) === $reportsBefore + 1 && end($reportedSales) === 'Synthetic late sale read failure.',
        'Later '.$phase.' sale failure lost its report.');
}

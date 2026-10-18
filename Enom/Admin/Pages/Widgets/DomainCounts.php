<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Pages\Widgets;

use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

/** Domain totals with links into the filtered tables. */
class DomainCounts extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return Staff::can(Staff::MANAGE);
    }

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $expiring = Domain::query()->whereDate('provider_expires_at', '>=', $today)->whereDate('provider_expires_at', '<=', $today->addDays(30))->count();
        $attention = count(Staff::attentionDomainIds());
        $requests = Labels::needsStaff(Operation::query())->count();
        $waiting = Labels::waitingOnCustomer(Operation::query())->count();

        return [
            Stat::make('Domains', (string) Domain::query()->count())
                ->description('Open the list')
                ->url(DomainResource::getUrl('index')),
            Stat::make('Expiring in 30 days', (string) $expiring)
                ->description($expiring ? 'Check renewals' : 'Nothing soon')
                ->color($expiring ? 'warning' : 'gray')
                ->url(DomainResource::getUrl('index', ['filters' => ['expiring' => ['value' => '30']]])),
            Stat::make('Need attention', (string) $attention)
                ->description($requests ? $requests.' registrar '.($requests === 1 ? 'request' : 'requests') : 'All clear')
                ->color($attention ? 'danger' : 'success')
                ->url($requests ? OperationResource::getUrl('index', ['tab' => 'attention']) : DomainResource::getUrl('index', ['filters' => ['attention' => ['isActive' => '1']]])),
            Stat::make('Waiting on customer', (string) $waiting)
                ->description($waiting ? 'Payment, transfer code or details' : 'Nothing waiting')
                ->color('gray')
                ->url(OperationResource::getUrl('index', ['tab' => 'customer'])),
        ];
    }
}

<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    public function getTitle(): string
    {
        return 'Registrar requests';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $tab = fn (string $label, string $group, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => Labels::inGroup($query, $group))
            ->badge(($n = Labels::inGroup(Operation::query(), $group)->count()) > 0 ? $n : null)
            ->badgeColor($color ?? 'gray');

        return [
            'attention' => $tab('Needs attention', 'attention', 'danger'),
            'customer' => $tab('Waiting on customer', 'customer'),
            'progress' => $tab('In progress', 'progress', 'info'),
            'completed' => $tab('Completed', 'completed'),
            'failed' => $tab('Failed', 'failed'),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Labels::needsStaff(Operation::query())->exists() ? 'attention' : 'all';
    }
}

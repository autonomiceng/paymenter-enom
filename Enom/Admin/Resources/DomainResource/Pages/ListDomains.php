<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    public function getTitle(): string
    {
        return 'Domains';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

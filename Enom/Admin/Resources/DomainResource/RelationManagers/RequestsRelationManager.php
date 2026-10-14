<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;

/** The domain page's activity list: the same table and actions as Registrar requests, for one domain. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Activity';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return OperationResource::requestsTable($table, nested: true)->heading('Activity');
    }
}

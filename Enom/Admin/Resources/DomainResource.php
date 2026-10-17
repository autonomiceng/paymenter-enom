<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources;

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Relations;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static ?string $navigationLabel = 'Domains';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'domain';

    protected static ?string $pluralModelLabel = 'domains';

    protected static ?string $slug = 'domains';

    protected static ?string $recordTitleAttribute = 'domain';

    public static function getEloquentQuery(): Builder
    {
        Relations::register();
        return parent::getEloquentQuery()->with(['client', 'service.cancellation']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can(Staff::MANAGE)) { return null; }
        $count = count(Staff::attentionDomainIds());
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public static function getNavigationBadgeTooltip(): ?string { return 'Domains that need attention'; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')
                    ->label('Domain')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->description(fn (Domain $record) => $record->client?->email)
                    ->placeholder('No client')
                    ->visibleFrom('md')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('client', fn (Builder $users) => $users
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Labels::domainStatus($state))
                    ->color(fn (?string $state) => Labels::domainStatusColor($state))
                    ->description(fn (Domain $record) => self::pendingProgress($record))
                    ->sortable(),
                TextColumn::make('provider_expires_at')
                    ->label('Expires')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->color(fn (Domain $record) => Labels::expiryColor($record->provider_expires_at))
                    ->description(fn (Domain $record) => implode(', ', array_filter([
                        Labels::expiryHint($record->provider_expires_at),
                        $record->provider_expires_at && $record->service?->cancellation ? 'renewal off' : null,
                    ])) ?: null)
                    ->sortable(),
                IconColumn::make('renews')
                    ->label('Renews')
                    ->state(fn (Domain $record) => in_array($record->status, ['registration_pending', 'transfer_pending', 'registration_rejected', 'transfer_rejected'], true)
                        ? null : ($record->service !== null && !$record->service->cancellation))
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->falseColor('gray')
                    ->tooltip(fn (Domain $record) => $record->service !== null && !$record->service->cancellation ? 'Renewal on' : 'Renewal off')
                    ->alignCenter()
                    ->visibleFrom('2xl'),
                TextColumn::make('who_pays')
                    ->label('Who pays')
                    ->visibleFrom('md')
                    ->state(fn (Domain $record) => Labels::whoPays($record)['label'])
                    ->badge()
                    ->color(fn (Domain $record) => $record->price_mode === 'custom' && (float) ($record->service?->price ?? 0) <= 0 ? 'warning' : Labels::whoPaysColor(Labels::whoPays($record)['mode']))
                    ->description(fn (Domain $record) => (($pays = Labels::whoPays($record))['mode'] === 'complimentary' && $pays['source'] === 'client') ? 'From client' : null),
                TextColumn::make('last_synced_at')
                    ->label('Last checked')
                    ->state(fn (Domain $record) => Labels::lastChecked($record))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('attention')
                    ->label('Needs attention')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('id', Staff::attentionDomainIds())),
                SelectFilter::make('expiring')
                    ->label('Expiring within')
                    ->options(['7' => '7 days', '30' => '30 days', '60' => '60 days', '90' => '90 days'])
                    ->query(function (Builder $query, array $data) {
                        $days = (int) ($data['value'] ?? 0);
                        if (!in_array($days, [7, 30, 60, 90], true)) { return $query; }
                        $today = CarbonImmutable::today();
                        return $query->whereDate('provider_expires_at', '>=', $today)->whereDate('provider_expires_at', '<=', $today->addDays($days));
                    }),
                Filter::make('expired')
                    ->label('Expired')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereDate('provider_expires_at', '<', CarbonImmutable::today())),
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(Labels::domainStatusOptions()),
                Filter::make('complimentary')
                    ->label('Complimentary')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                        ->where('price_mode', 'complimentary')
                        ->orWhere(fn (Builder $default) => $default->whereNull('price_mode')
                            ->whereIn('user_id', self::complimentaryClientIds())))),
                Filter::make('renewal_off')
                    ->label('Renewal off')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('service.cancellation')),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('provider_expires_at is null')->orderBy('provider_expires_at')->orderBy('domain'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->persistFiltersInSession()
            ->emptyStateHeading('No domains yet')
            ->emptyStateDescription('Domains appear here when clients order them or staff add them.');
    }

    /** "Awaiting payment" and similar detail for registrations and transfers that have not finished. */
    private static function pendingProgress(Domain $record): ?string
    {
        if (!in_array($record->status, ['registration_pending', 'transfer_pending'], true)) { return null; }
        $label = DomainAdministration::progress([$record])[(int) $record->id]['label'] ?? null;
        return match (true) {
            $label === null => null,
            str_ends_with($label, 'waiting to be sent') => 'Paid, waiting to send',
            str_ends_with($label, 'in progress') => 'At Enom',
            str_ends_with($label, 'needs review') => 'Needs attention',
            str_ends_with($label, 'not confirmed') => 'Not paid yet',
            default => $label,
        };
    }

    /** @return list<int> clients who hold a complimentary grant */
    public static function complimentaryClientIds(): array
    {
        return User::query()->whereHas('properties', fn (Builder $query) => $query->where('key', Pricing::CLIENT_PROPERTY))
            ->get()->filter(fn (User $user) => Labels::clientComplimentary($user))
            ->map(fn (User $user) => (int) $user->id)->values()->all();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['domain', 'client.email', 'client.first_name', 'client.last_name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        Relations::register();
        return parent::getGlobalSearchEloquentQuery()->with('client');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Domain $record */
        $details = ['Client' => $record->client ? $record->client->name.' ('.$record->client->email.')' : 'No client'];
        $details['Status'] = Labels::domainStatus($record->status);
        if ($record->provider_expires_at) { $details['Expires'] = $record->provider_expires_at->format('M j, Y'); }
        return $details;
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getRelations(): array
    {
        return [RequestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }
}

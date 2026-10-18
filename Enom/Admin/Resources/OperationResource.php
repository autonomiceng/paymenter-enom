<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources;

use App\Admin\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Relations;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Throwable;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-exchange-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-exchange-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static ?string $navigationLabel = 'Registrar requests';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'registrar request';

    protected static ?string $pluralModelLabel = 'registrar requests';

    protected static ?string $slug = 'registrar-requests';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        Relations::register();
        return parent::getEloquentQuery()->with(['domain.client']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can(Staff::MANAGE)) { return null; }
        $count = Operation::query()->whereIn('state', Labels::ATTENTION_STATES)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'danger'; }

    public static function getNavigationBadgeTooltip(): ?string { return 'Requests that need attention'; }

    public static function table(Table $table): Table
    {
        return self::requestsTable($table, nested: false);
    }

    /** Shared by this resource and the domain page's request list. */
    public static function requestsTable(Table $table, bool $nested): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable()
                    ->visibleFrom('2xl'),
                TextColumn::make('domain.domain')
                    ->label('Domain')
                    ->weight('semibold')
                    ->description(fn (Operation $record) => $record->domain?->client?->name)
                    ->url(fn (Operation $record) => $record->domain_id ? DomainResource::getUrl('view', ['record' => $record->domain_id]) : null)
                    ->searchable()
                    ->hidden($nested),
                TextColumn::make('action')
                    ->label('Request')
                    ->formatStateUsing(fn (?string $state, Operation $record) => Labels::request($record))
                    ->description(fn (Operation $record) => '#'.$record->id.', '.$record->created_at?->diffForHumans())
                    ->tooltip(fn (Operation $record) => $record->created_at?->format('M j, Y, H:i')),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Operation $record) => self::refunded($record) ? 'Refunded' : Labels::state($state))
                    ->color(fn (?string $state, Operation $record) => self::refunded($record) ? 'gray' : Labels::stateColor($state))
                    ->tooltip(fn (Operation $record) => self::refunded($record)
                        ? ($record->safe_code === 'refunded_by_staff' && is_string($record->funding_reason) ? $record->funding_reason : 'The client\'s payment was added to their account credit.')
                        : Labels::stateHint($record->state, $record))
                    ->extraHeaderAttributes(['style' => 'min-width: 10rem'])
                    ->description(fn (Operation $record) => implode(' ', array_filter([
                        ($refund = Labels::refundText($record)) ? $refund.'.' : null,
                        ($said = Labels::providerMessage($record)) !== null
                            ? 'Enom said: '.$said
                            : (in_array($record->state, [...Labels::ATTENTION_STATES, 'awaiting_payment'], true) ? Labels::stateHint($record->state, $record) : null),
                    ])) ?: null)
                    ->wrap(),
                TextColumn::make('funding_basis')
                    ->label('Who pays')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn (?string $state) => Labels::funding($state))
                    ->badge()
                    ->color(fn (?string $state) => Labels::fundingColor($state))
                    ->description(fn (Operation $record) => implode(', ', array_filter([
                        (float) $record->amount > 0 ? Labels::money($record->amount, $record->currency) : null,
                        $record->invoice_id ? '#'.$record->invoice_id : null,
                    ])) ?: null)
                    ->toggleable(),
                TextColumn::make('invoice_id')
                    ->label('Invoice')
                    ->visibleFrom('2xl')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->url(fn (Operation $record) => $record->invoice_id && Staff::can('admin.invoices.update') ? self::invoiceUrl((int) $record->invoice_id) : null)
                    ->color('primary')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('transfer_paid_at')
                    ->label('Transfer paid')
                    ->getStateUsing(fn (Operation $record) => $record->action === 'transfer'
                        ? \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($record)?->subDays(30) : null)
                    ->dateTime('M j, Y, H:i T')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('transfer_code_deadline')
                    ->label('Code deadline')
                    ->getStateUsing(fn (Operation $record) => $record->action === 'transfer'
                        ? \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($record) : null)
                    ->dateTime('M j, Y, H:i T')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->label('Sent')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label('Finished')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters($nested ? [] : [
                SelectFilter::make('state')
                    ->label('State')
                    ->multiple()
                    ->options(Labels::stateOptions()),
                SelectFilter::make('action')
                    ->label('Request')
                    ->multiple()
                    ->options(fn () => Operation::query()->distinct()->orderBy('action')->pluck('action')
                        ->mapWithKeys(fn ($action) => [$action => Labels::action($action)])->all()),
                SelectFilter::make('funding_basis')
                    ->label('Who pays')
                    ->options(['paid_invoice' => 'Client invoice', 'complimentary' => 'Complimentary', 'non_billable_management' => 'No charge']),
                Filter::make('refunded')
                    ->label('Refunded as credit')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('id', self::refundedIds())),
            ])
            ->recordActions([
                self::checkAction(),
                self::resumeAction(),
                self::resumeContactsAction(),
                self::readResultAction(),
                self::recordOutcomeAction(),
                self::refundAction(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated($nested ? [10, 25, 50] : [25, 50, 100])
            ->emptyStateHeading($nested ? 'No registrar requests yet' : 'Nothing here')
            ->emptyStateDescription($nested ? null : 'Requests appear when domains are ordered, renewed or changed.');
    }

    private static function refunded(Operation $record): bool
    {
        return $record->state === 'rejected' && Labels::refund($record) !== null;
    }

    /** Refunds live in the encrypted cost data, so they are found in PHP. Only paid requests can carry one. */
    private static function refundedIds(): array
    {
        $ids = [];
        foreach (Operation::query()->where('funding_basis', 'paid_invoice')->whereIn('state', ['rejected', 'disposed'])->select(['id', 'cost'])->lazyById(200) as $operation) {
            if (Labels::refund($operation) !== null) { $ids[] = (int) $operation->id; }
        }
        return $ids ?: [0];
    }

    /** A paid request that is on hold and was never sent to Enom. The service makes the final check. */
    private static function refundable(Operation $record): bool
    {
        if ($record->state !== 'held' || $record->funding_basis !== 'paid_invoice'
            || !Staff::can(Staff::MANAGE, Staff::INVOICES)) { return false; }
        $service = app(\Paymenter\Extensions\Servers\Enom\Services\Refunds::class);
        try { return (bool) $service->canRefundHeld($record, Staff::user()); } catch (Throwable) { return false; }
    }

    private static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund as credit')
            ->icon('heroicon-o-receipt-refund')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Operation $record) => self::refundable($record))
            ->schema(fn (Operation $record) => [
                Text::make('Closes this request without sending it to Enom and adds '
                    .(Labels::money($record->amount, $record->currency) ?? 'the amount paid').' to '
                    .($record->domain?->client?->name ?? 'the client').'\'s account credit. The invoice stays paid.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('For example: the client no longer wants this domain')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->modalIcon('heroicon-o-receipt-refund')
            ->modalIconColor('warning')
            ->modalHeading('Refund as credit')
            ->modalSubmitActionLabel('Refund')
            ->modalWidth('lg')
            ->action(function (Operation $record, array $data) {
                $staff = Staff::require(Staff::INVOICES);
                $service = app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class);
                $reason = trim(preg_replace('/\s+/', ' ', (string) ($data['reason'] ?? '')));
                Staff::attempt(function () use ($service, $staff, $record, $reason) {
                    $result = $service->refundHeld($staff, (int) $record->id, $reason);
                    $amount = is_array($result) ? Labels::money($result['amount'] ?? null, $result['currency'] ?? $record->currency) : null;
                    $amount ??= Labels::money($record->amount, $record->currency);
                    return $amount ? 'Refunded '.$amount.' as credit.' : 'Refunded as credit.';
                }, 'Refunded as credit.');
            });
    }

    private static function invoiceUrl(int $id): ?string
    {
        try { return InvoiceResource::getUrl('edit', ['record' => $id]); } catch (Throwable) { return null; }
    }

    /** A completed registration or renewal whose final Enom charge has not been read yet. Needs the costs permission. */
    private static function chargeCheckable(Operation $record): bool
    {
        if ($record->state !== 'succeeded' || !in_array($record->action, ['register', 'renew'], true) || !Staff::can(Staff::COSTS)) { return false; }
        try {
            $cost = $record->cost ?? [];
            $attempts = $cost['actual_attempts'] ?? 0;
            return isset($cost['execution']) && ($cost['actual']['state'] ?? null) !== 'confirmed'
                && is_int($attempts) && $attempts < DomainOperations::COST_POLL_LIMIT
                && !in_array($cost['actual']['reason'] ?? null, ['renewal_item_contract_unaccepted', 'cost_poll_limit_reached'], true);
        } catch (Throwable) { return false; }
    }

    private static function checkAction(): Action
    {
        return Action::make('check')
            ->label(fn (Operation $record) => $record->state === 'succeeded' ? 'Check charge' : 'Check with Enom')
            ->icon('heroicon-o-magnifying-glass')
            ->size('sm')
            ->visible(fn (Operation $record) => in_array($record->state, ['uncertain', 'pending'], true)
                || ($record->state === 'running' && !$record->lease_expires_at?->isFuture())
                || self::chargeCheckable($record))
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-magnifying-glass')
            ->modalHeading(fn (Operation $record) => $record->state === 'succeeded' ? 'Check the final charge?' : 'Check this request with Enom?')
            ->modalDescription(fn (Operation $record) => $record->state === 'succeeded'
                ? 'Reads what Enom charged for this request.'
                : 'Reads the result from Enom. Nothing is sent again.')
            ->modalSubmitActionLabel('Check')
            ->action(function (Operation $record) {
                $charge = Operation::query()->whereKey($record->id)->value('state') === 'succeeded';
                Staff::require(...($charge ? [Staff::COSTS] : []));
                $result = null;
                Staff::attempt(function () use ($record, &$result, $charge) {
                    $result = app(DomainOperations::class)->reconcile((int) $record->id);
                    return $charge ? 'Charge checked.' : 'Checked with Enom: '.strtolower(Labels::state($result->state)).'.';
                }, 'Checked with Enom.', 'Enom could not be reached. Nothing was sent; try again later.');
            });
    }

    private static function resumeContactsAction(): Action
    {
        return Action::make('resumeContacts')
            ->label('Use updated account details')
            ->icon('heroicon-o-play')
            ->size('sm')
            ->visible(fn (Operation $record) => Staff::user() && \Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup::canResumeContacts($record, Staff::user()))
            ->schema([
                Text::make('Uses the domain owner’s current account contacts for this unsent order, then checks payment and transfer requirements before resuming.'),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2),
            ])
            ->modalHeading('Use updated account details')
            ->modalSubmitActionLabel('Use updated details')
            ->modalWidth('lg')
            ->action(function (Operation $record, array $data) {
                $staff = Staff::require();
                $reason = trim(preg_replace('/\s+/', ' ', (string) ($data['reason'] ?? '')));
                Staff::attempt(fn () => \Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup::resumeContacts($staff, (int) $record->id, $reason), 'Account details updated. The order will resume when its requirements are met.');
            });
    }

    private static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon('heroicon-o-play')
            ->size('sm')
            ->visible(fn (Operation $record) => $record->state === 'held' && $record->action === 'renew'
                && Staff::user() && app(DomainOperations::class)->canReadmitHeld($record, Staff::user()))
            ->schema([
                Text::make('This renewal was never sent to Enom and is already paid for. Resuming sends it once.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('For example: client confirmed they still want the renewal')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->modalHeading('Resume unsent request')
            ->modalSubmitActionLabel('Resume')
            ->modalWidth('lg')
            ->action(function (Operation $record, array $data) {
                $funded = $record->funding_basis === 'complimentary';
                $staff = Staff::require(...($funded ? [Staff::FUND] : []));
                $reason = trim(preg_replace('/\s+/', ' ', (string) ($data['reason'] ?? '')));
                Staff::attempt(fn () => app(DomainOperations::class)->readmitHeld((int) $record->id, $staff, $reason), 'Request resumed. It will be sent on the next run.');
            });
    }

    /** Uncertain or pending renewals that went to Enom: staff can read the result before closing them. */
    private static function reviewable(Operation $record): bool
    {
        if ($record->action !== 'renew' || !in_array($record->state, ['uncertain', 'pending'], true)) { return false; }
        try { return app(DomainOperations::class)->canReviewProviderFact($record); } catch (Throwable) { return false; }
    }

    /** Latest fact if it is recent enough to record an outcome (the service also enforces 15 minutes). */
    private static function freshFact(Operation $record): ?array
    {
        try { $fact = app(DomainOperations::class)->providerReviewData($record)['fact'] ?? []; } catch (Throwable) { return null; }
        try { $at = CarbonImmutable::parse($fact['observed_at'] ?? ''); } catch (Throwable) { return null; }
        return is_string($fact['digest'] ?? null) && !$at->isFuture() && $at->gte(now()->subMinutes(14)) ? $fact : null;
    }

    private static function readResultAction(): Action
    {
        return Action::make('readResult')
            ->label('Read result')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Operation $record) => self::reviewable($record))
            ->requiresConfirmation()
            ->modalHeading('Read the renewal result from Enom?')
            ->modalDescription('Reads the domain\'s current expiry and order at Enom so you can record the outcome. Nothing is sent again.')
            ->modalSubmitActionLabel('Read result')
            ->action(function (Operation $record) {
                $staff = Staff::require();
                Staff::attempt(fn () => app(DomainOperations::class)->observeProviderFact((int) $record->id, $staff),
                    'Result read. Record the outcome within 15 minutes.', 'Enom could not be reached. Try again later.');
            });
    }

    private static function recordOutcomeAction(): Action
    {
        return Action::make('recordOutcome')
            ->label('Record outcome')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->size('sm')
            ->visible(function (Operation $record): bool {
                if (!self::reviewable($record) || !($fact = self::freshFact($record))) { return false; }
                return ($fact['known_order'] ?? false) !== true || ($fact['order_completed'] ?? false) === true;
            })
            ->schema(function (Operation $record) {
                $fact = self::freshFact($record) ?? [];
                $known = ($fact['known_order'] ?? false) === true;
                $completed = ($fact['order_completed'] ?? false) === true;
                $expires = isset($fact['expires_at']) ? CarbonImmutable::parse($fact['expires_at'])->format('M j, Y') : 'unknown';
                $summary = 'Enom shows the domain expiring '.$expires.'. '.match (true) {
                    $known && $completed => 'The renewal order is complete.',
                    $known => 'The renewal order is not complete, so it cannot be closed as renewed. Check with Enom later.',
                    default => 'No order was recorded for this request, so the expiry cannot be matched to it.',
                };
                $options = match (true) {
                    $known && $completed => ['provider_completed' => 'Renewed at Enom'],
                    !$known => ['unattributed_provider_fact' => 'Can\'t match this request'],
                    default => [],
                };
                return [
                    Text::make($summary),
                    Radio::make('outcome')->label('Outcome')->options($options)->required()->default(array_key_first($options)),
                    Textarea::make('reason')->label('Reason')->required()->minLength(10)->maxLength(500)->rows(2)
                        ->placeholder('What you checked, for example the Enom order and the client\'s payment'),
                    Text::make('Closing pauses changes and renewal billing for this domain. Check the charge and the client\'s payment yourself.'),
                ];
            })
            ->modalHeading('Record outcome')
            ->modalSubmitActionLabel('Close request')
            ->modalWidth('lg')
            ->action(function (Operation $record, array $data) {
                $staff = Staff::require();
                $fact = self::freshFact($record);
                if (!$fact) { \Filament\Notifications\Notification::make()->title('The result is older than 15 minutes. Read it again first.')->danger()->send(); return; }
                $reason = trim(preg_replace('/\s+/', ' ', (string) ($data['reason'] ?? '')));
                Staff::attempt(fn () => app(DomainOperations::class)->disposeProviderFact((int) $record->id, $staff, (string) $fact['digest'],
                    (string) ($data['outcome'] ?? ''), 'manual_review_required', $reason), 'Request closed.');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperations::route('/'),
        ];
    }
}

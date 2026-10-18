<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\UserResource;
use App\Models\Invoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Enom\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Throwable;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    public function getTitle(): string|Htmlable
    {
        return (string) $this->getRecord()->domain;
    }

    private function facts(?Domain $domain = null): array
    {
        return DomainFacts::for($domain ?? $this->getRecord());
    }

    private function refreshFacts(Domain $domain): void
    {
        DomainFacts::forget((int) $domain->id);
        Labels::forgetClient((int) $domain->user_id);
        $this->record = $domain->fresh() ?? $domain;
        $this->record->load(['client', 'service.cancellation']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Enom')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $staff = Staff::require();
                    $domain = $this->getRecord();
                    if (Staff::attempt(fn () => app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class)->refresh($staff, (int) $domain->id), 'Updated from Enom.')) {
                        $this->refreshFacts($domain);
                    }
                }),
            $this->renewNowAction(),
            Action::make('viewAsClient')
                ->label('View as client')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => Staff::can('admin.users.impersonate') && $this->getRecord()->user_id
                    && (int) $this->getRecord()->user_id !== (int) Staff::user()?->id)
                ->requiresConfirmation()
                ->modalHeading('View as client')
                ->modalDescription(fn () => 'You will be signed in as '.($this->facts()['client']?->name ?? 'the client').' until you stop impersonating.')
                ->modalSubmitActionLabel('Continue')
                ->action(function () {
                    abort_unless(Staff::can('admin.users.impersonate'), 403);
                    session()->put('impersonating', (int) $this->getRecord()->user_id);
                    $this->redirect('/domains/'.(int) $this->getRecord()->id);
                }),
        ];
    }

    private function renewNowAction(): Action
    {
        return Action::make('renewNow')
            ->label('Renew now')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn () => in_array($this->getRecord()->status, ['active', 'expired', 'grace'], true)
                && !$this->zeroCustom($this->getRecord())
                && ($this->facts()['pays']['mode'] !== 'complimentary' || Staff::can(Staff::FUND)))
            ->disabled(fn () => $this->facts()['renewal']['years'] === [])
            ->tooltip(fn () => $this->facts()['renewal']['years'] === [] ? 'Already registered for the 10-year maximum.' : null)
            ->fillForm(['years' => 1])
            ->schema(fn () => [
                Select::make('years')
                    ->label('Add')
                    ->options(collect($this->facts()['renewal']['years'])->mapWithKeys(fn (int $year) => [$year => Labels::years($year)])->all())
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->visible(count($this->facts()['renewal']['years']) > 1),
                Text::make(fn (Get $get) => $this->renewSummary((int) ($get('years') ?: 1))),
            ])
            ->modalIcon('heroicon-o-calendar-days')
            ->modalHeading(fn () => 'Renew '.$this->getRecord()->domain)
            ->modalSubmitActionLabel('Renew')
            ->modalWidth('md')
            ->action(function (array $data) {
                $domain = $this->getRecord();
                $complimentary = $this->facts()['pays']['mode'] === 'complimentary';
                $staff = Staff::require(...($complimentary ? [Staff::FUND] : []));
                $service = app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class);
                $years = (int) ($data['years'] ?? 1);
                if (!in_array($years, $this->facts()['renewal']['years'], true)) {
                    Notification::make()->title('Choose a term of up to 10 years from today.')->danger()->send();
                    return;
                }
                $result = null;
                $ok = Staff::attempt(function () use ($service, $staff, $domain, $years, &$result) {
                    $result = $this->facts()['renewal']['multi'] ? $service->renewNow($staff, (int) $domain->id, $years) : $service->renewNow($staff, (int) $domain->id);
                    return $result instanceof Invoice ? 'Renewal invoice created.' : 'Renewal queued.';
                }, 'Renewal requested.');
                if ($ok && $result instanceof Invoice) {
                    Notification::make()->title('Invoice '.($result->number ?: '#'.$result->id).' is ready')
                        ->actions([Action::make('open')->label('Open invoice')->url(InvoiceResource::getUrl('edit', ['record' => $result->id]))])
                        ->send();
                }
                if ($ok) { $this->refreshFacts($domain); }
            });
    }

    private function renewSummary(int $years): string
    {
        $facts = $this->facts();
        $expires = Labels::day($this->getRecord()->provider_expires_at);
        $until = $expires ? ' New expiry: '.$expires->addYears($years)->format('M j, Y').'.' : '';
        if ($facts['pays']['mode'] === 'complimentary') {
            return 'Queues a renewal for '.Labels::years($years).' now. Enom charges your account; the client pays nothing.'.$until;
        }
        $perYear = $facts['renewal']['price_per_year'] ?? $facts['renewal_price'];
        $total = is_numeric($perYear) ? Labels::money(round((float) $perYear * $years, 2), $facts['currency']) : null;
        return 'Creates the client\'s renewal invoice'.($total ? ' for '.$total : '')
            .($total && $years > 1 ? ' ('.Labels::money($perYear, $facts['currency']).' a year)' : '')
            .'. The domain renews after it is paid.'.$until;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Callout::make('Needs attention')
                ->danger()
                ->visible(fn () => $this->facts()['attention']->isNotEmpty() || $this->facts()['paused'] || $this->getRecord()->status === 'needs_review')
                ->description(fn () => $this->attentionText()),
            Section::make('Overview')
                ->columns(['default' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('provider_expires_at')
                        ->label('Expires')
                        ->date('M j, Y')
                        ->placeholder('Not registered yet')
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color(fn (Domain $record) => Labels::expiryColor($record->provider_expires_at))
                        ->belowContent(fn (Domain $record) => Labels::expiryHint($record->provider_expires_at)),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => Labels::domainStatus($state))
                        ->color(fn (?string $state) => Labels::domainStatusColor($state)),
                    TextEntry::make('registered')
                        ->label(fn (Domain $record) => $this->facts($record)['registered'] ? 'Registered' : 'Added')
                        ->state(fn (Domain $record) => $this->facts($record)['registered'] ?? $record->created_at)
                        ->date('M j, Y')
                        ->placeholder('—'),
                    TextEntry::make('environment')
                        ->label('Enom account')
                        ->state(fn (Domain $record) => $this->facts($record)['environment'] === 'test' ? 'Test' : 'Live')
                        ->badge()
                        ->color(fn (string $state) => $state === 'Test' ? 'warning' : 'success'),
                    TextEntry::make('client_name')
                        ->label('Client')
                        ->state(fn (Domain $record) => $this->facts($record)['client']?->name)
                        ->belowContent(fn (Domain $record) => $this->facts($record)['client']?->email)
                        ->url(fn (Domain $record) => $record->user_id && Staff::can('admin.users.update') ? self::safeUrl(fn () => UserResource::getUrl('edit', ['record' => $record->user_id])) : null)
                        ->color('primary')
                        ->placeholder('No client'),
                    TextEntry::make('service_id')
                        ->label('Service')
                        ->formatStateUsing(fn ($state) => 'Service #'.$state)
                        ->url(fn (Domain $record) => $record->service_id && Staff::can('admin.services.update') ? self::safeUrl(fn () => ServiceResource::getUrl('edit', ['record' => $record->service_id])) : null)
                        ->color('primary')
                        ->placeholder('None'),
                    TextEntry::make('last_checked')
                        ->label('Last checked')
                        ->state(fn (Domain $record) => $this->facts($record)['last_checked'])
                        ->since()
                        ->dateTimeTooltip()
                        ->placeholder('Never'),
                    TextEntry::make('tld')
                        ->label('Ending')
                        ->formatStateUsing(fn ($state) => '.'.$state),
                ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                $this->billingSection(),
                $this->registrarSection(),
            ]),
            Section::make('Invoices')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('invoices')
                    ->hiddenLabel()
                    ->state(fn (Domain $record) => array_map(fn (Invoice $invoice) => [
                        'number' => $invoice->number ?: '#'.$invoice->id,
                        'url' => self::safeUrl(fn () => InvoiceResource::getUrl('edit', ['record' => $invoice->id])),
                        'status' => $invoice->status,
                        'total' => self::invoiceTotal($invoice),
                        'due' => $invoice->due_at,
                    ], $this->facts($record)['invoices']))
                    ->table([
                        TableColumn::make('Invoice'),
                        TableColumn::make('Status'),
                        TableColumn::make('Total'),
                        TableColumn::make('Due'),
                    ])
                    ->schema([
                        TextEntry::make('number')->hiddenLabel()->url(fn (Get $get) => $get('url'))->color('primary'),
                        TextEntry::make('status')->hiddenLabel()->badge()
                            ->formatStateUsing(fn (?string $state) => match ($state) { 'pending' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled', default => ucfirst((string) $state) })
                            ->color(fn (?string $state) => match ($state) { 'paid' => 'success', 'pending' => 'warning', default => 'gray' }),
                        TextEntry::make('total')->hiddenLabel()->placeholder('—'),
                        TextEntry::make('due')->hiddenLabel()->date('M j, Y')->placeholder('—'),
                    ])
                    ->placeholder('No invoices yet')
                    ->columnSpanFull(),
                ]),
        ]);
    }

    private function attentionText(): string
    {
        $facts = $this->facts();
        $lines = [];
        foreach ($facts['attention'] as $operation) {
            $said = Labels::providerMessage($operation);
            $lines[] = Labels::action($operation->action).' #'.$operation->id.': '.strtolower(Labels::state($operation->state)).'. '.Labels::stateHint($operation->state)
                .($said ? ' Enom said: '.$said.(str_ends_with($said, '.') ? '' : '.') : '');
        }
        if (!$lines && $facts['paused']) { $lines[] = 'Changes and renewal billing are paused after a staff review.'; }
        if (!$lines) { $lines[] = 'Enom reported a problem with this domain. Refresh from Enom to check again.'; }
        return implode(' ', array_slice($lines, 0, 2)).' See Activity below.';
    }

    private function billingSection(): Section
    {
        return Section::make('Billing')
            ->afterHeader([
                $this->changeWhoPaysAction(),
                ActionGroup::make([
                    $this->renewalToggleAction(),
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->color('gray')->dropdownWidth(\Filament\Support\Enums\Width::ExtraSmall),
            ])
            ->columns(2)
            ->schema([
                TextEntry::make('who_pays')
                    ->label('Who pays')
                    ->state(fn (Domain $record) => $this->facts($record)['pays']['label'])
                    ->badge()
                    ->color(fn (Domain $record) => $this->zeroCustom($record) ? 'warning' : Labels::whoPaysColor($this->facts($record)['pays']['mode']))
                    ->belowContent(fn (Domain $record) => $this->zeroCustom($record) ? 'A $0 price is held for review. Choose who pays.' : ($this->facts($record)['pays']['source'] === 'client'
                        ? 'Standard renewal price'
                        : 'Set for this domain')),
                TextEntry::make('renewal_price')
                    ->label('Renewal price')
                    ->state(function (Domain $record) {
                        $facts = $this->facts($record);
                        if ($facts['pays']['mode'] === 'complimentary') { return 'Free'; }
                        if ($this->zeroCustom($record)) { return null; }
                        $price = Labels::money($facts['renewal_price'], $facts['currency']);
                        return $price ? $price.' a year' : null;
                    })
                    ->placeholder(fn (Domain $record) => $this->zeroCustom($record) ? 'Not set' : 'No price for this TLD'),
                TextEntry::make('renews')
                    ->label('Renewal')
                    ->state(fn (Domain $record) => !$this->registered($record) ? null : ($this->facts($record)['renews'] ? 'On' : 'Off'))
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (string $state) => $state === 'On' ? 'success' : 'gray')
                    ->belowContent(fn (Domain $record) => $this->facts($record)['renews'] ? null : 'Expires at the end of its term'),
                TextEntry::make('next_invoice')
                    ->label('Next invoice')
                    ->state(fn (Domain $record) => $this->registered($record) ? $this->facts($record)['next_invoice'] : null)
                    ->date('M j, Y')
                    ->placeholder(fn (Domain $record) => $this->facts($record)['pays']['mode'] === 'complimentary' ? 'None, complimentary' : 'None scheduled'),
            ]);
    }

    /** Renewal settings only mean something once the domain exists at Enom. */
    private function registered(Domain $record): bool
    {
        return in_array($record->status, ['active', 'expired', 'grace', 'redemption', 'needs_review'], true);
    }

    private function zeroCustom(Domain $record): bool
    {
        return false;
    }

    private static function invoiceTotal(Invoice $invoice): ?string
    {
        try { return (string) $invoice->formattedTotal; } catch (Throwable) { return null; }
    }

    private function registrarSection(): Section
    {
        return Section::make('Registrar')
            ->afterHeader([
                $this->nameserversAction(),
                ActionGroup::make([
                    ActionGroup::make([
                        $this->lockAction(),
                        $this->transferCodeAction(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        $this->addGlueAction(),
                        $this->editGlueAction(),
                        $this->deleteGlueAction(),
                    ])->dropdown(false),
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->color('gray'),
            ])
            ->columns(2)
            ->schema([
                TextEntry::make('nameservers')
                    ->label('Nameservers')
                    ->state(fn (Domain $record) => $this->facts($record)['snapshot']['nameservers'] ?: null)
                    ->listWithLineBreaks()
                    ->copyable()
                    ->placeholder('Not read yet')
                    ->belowContent(fn (Domain $record) => $this->usesDefaultNameservers($record) ? 'Server default' : null),
                TextEntry::make('glue')
                    ->label('Child nameservers')
                    ->state(fn (Domain $record) => array_map(fn (array $glue) => '<span style="overflow-wrap: anywhere">'.e($glue['host']).'</span><br><span class="text-gray-500 dark:text-gray-400">'.e($glue['ip']).'</span>',
                        $this->facts($record)['glue']) ?: null)
                    ->html()
                    ->listWithLineBreaks()
                    ->placeholder('None')
                    ->visible(fn (Domain $record) => $this->registered($record)),
                TextEntry::make('lock')
                    ->label('Transfer lock')
                    ->state(fn (Domain $record) => match ($this->facts($record)['snapshot']['locked']) { true => 'Locked', false => 'Unlocked', default => null })
                    ->badge()
                    ->color(fn (?string $state) => $state === 'Locked' ? 'success' : 'warning')
                    ->placeholder('Not read yet'),
                TextEntry::make('privacy')
                    ->label('ID Protect')
                    ->state(function (Domain $record) {
                        $privacy = $this->facts($record)['snapshot']['privacy'];
                        return match (true) {
                            $privacy['enabled'] === true => 'On',
                            $privacy['exists'] === true => 'Off',
                            $privacy['exists'] === false => 'Not purchased',
                            default => null,
                        };
                    })
                    ->placeholder('Not read yet'),
                TextEntry::make('registrant')
                    ->label(fn (Domain $record) => $this->facts($record)['contacts_same'] ? 'Contact (all roles)' : 'Registrant')
                    ->state(function (Domain $record) {
                        $contact = $this->facts($record)['snapshot']['contacts']['registrant'] ?? [];
                        $name = trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? ''));
                        return array_values(array_filter([$name, $contact['organization_name'] ?? null, $contact['email_address'] ?? null])) ?: null;
                    })
                    ->listWithLineBreaks()
                    ->placeholder('Not read yet')
                    ->belowContent(fn (Domain $record) => !$this->facts($record)['contacts_same'] && !empty($this->facts($record)['snapshot']['contacts']['registrant'])
                        ? 'Other roles use different details' : null),
                TextEntry::make('registry')
                    ->label('Registry details')
                    ->state(fn (Domain $record) => $this->facts($record)['registry'] ?: null)
                    ->listWithLineBreaks()
                    ->visible(fn (Domain $record) => $this->facts($record)['registry'] !== [])
                    ->belowContent('Sent with the order'),
            ]);
    }

    private function usesDefaultNameservers(Domain $record): bool
    {
        $facts = $this->facts($record);
        $current = $facts['snapshot']['nameservers'];
        $defaults = $facts['default_nameservers'];
        if (!$current || !$defaults) { return false; }
        $normalize = fn (array $hosts) => collect($hosts)->map(fn ($host) => rtrim(strtolower((string) $host), '.'))->sort()->values()->all();
        return $normalize($current) === $normalize($defaults);
    }

    /** Why a registrar change can't be queued right now, or null when it can. */
    private function changeBlocker(Domain $record, string $capability): ?string
    {
        $facts = $this->facts($record);
        return match (true) {
            $record->status !== 'active' => 'Available once the domain is active.',
            $facts['paused'] => 'Changes are paused after a staff review.',
            $facts['unresolved'] => 'Wait for the open registrar request to finish.',
            // Child nameservers only need an active domain (design 0.4 §3).
            $capability !== 'glue' && !isset($facts['capabilities'][$capability]) => 'Refresh from Enom first.',
            default => null,
        };
    }

    private function queueChange(Domain $record, string $action, array $payload, string $success): void
    {
        $staff = Staff::require();
        $service = app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class);
        if (Staff::attempt(fn () => $service->requestChange($staff, (int) $record->id, $action, $payload + ['confirmed' => true]) ? $success : null, $success)) {
            $this->refreshFacts($record);
        }
    }

    private function nameserversAction(): Action
    {
        return Action::make('nameservers')
            ->label('Edit nameservers')
            ->icon('heroicon-o-server-stack')
            ->color('gray')
            ->size('sm')
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'nameservers') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'nameservers'))
            ->fillForm(fn (Domain $record) => ['nameservers' => implode("\n", $this->facts($record)['snapshot']['nameservers'])])
            ->schema(fn (Domain $record) => [
                Textarea::make('nameservers')
                    ->label('Nameservers')
                    ->helperText('One per line, 2 to 12.')
                    ->rows(5)
                    ->required()
                    ->hintAction(empty($this->facts($record)['default_nameservers']) ? null : Action::make('useDefault')
                        ->label('Use server default')
                        ->action(fn (Set $set) => $set('nameservers', implode("\n", $this->facts($record)['default_nameservers'])))),
            ])
            ->modalHeading('Edit nameservers')
            ->modalSubmitActionLabel('Save nameservers')
            ->action(fn (Domain $record, array $data) => $this->queueChange($record, 'nameservers',
                ['nameservers' => RuntimeGuard::nameserverList((string) ($data['nameservers'] ?? ''))], 'Nameserver change queued.'));
    }

    private function lockAction(): Action
    {
        return Action::make('lock')
            ->label(fn (Domain $record) => $this->facts($record)['snapshot']['locked'] === false ? 'Lock transfers' : 'Unlock transfers')
            ->icon(fn (Domain $record) => $this->facts($record)['snapshot']['locked'] === false ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'lock') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'lock'))
            ->requiresConfirmation()
            ->modalHeading(fn (Domain $record) => $this->facts($record)['snapshot']['locked'] === false ? 'Lock transfers?' : 'Unlock transfers?')
            ->modalDescription(fn (Domain $record) => $this->facts($record)['snapshot']['locked'] === false
                ? 'The domain cannot be moved to another registrar while it is locked.'
                : 'Unlock only when the client is moving this domain to another registrar.')
            ->action(function (Domain $record) {
                $lock = $this->facts($record)['snapshot']['locked'] === false;
                $this->queueChange($record, 'lock', ['locked' => $lock], $lock ? 'Lock queued.' : 'Unlock queued.');
            });
    }

    private function transferCodeAction(): Action
    {
        return Action::make('transferCode')
            ->label('Email transfer code')
            ->icon('heroicon-o-envelope')
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'epp_sync') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'epp_sync'))
            ->requiresConfirmation()
            ->modalHeading('Email the transfer code?')
            ->modalDescription(function (Domain $record) {
                $email = DomainManager::maskEmail($this->facts($record)['snapshot']['contacts']['registrant']['email_address'] ?? null);
                return 'Enom emails the code to the registrant'.($email ? ' at '.$email : '').'.';
            })
            ->modalSubmitActionLabel('Send code')
            ->action(fn (Domain $record) => $this->queueChange($record, 'epp_email', [], 'Transfer code request queued.'));
    }

    /** Child nameserver hosts on this domain, for the edit and delete pickers. */
    private function glueOptions(Domain $record): array
    {
        return collect($this->facts($record)['glue'])->mapWithKeys(fn (array $glue) => [$glue['host'] => $glue['host'].'  ('.$glue['ip'].')'])->all();
    }

    /** "ns1" from "ns1", "NS1." or "ns1.example.com". */
    private static function glueLabel(string $host, string $domain): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $suffix = '.'.strtolower($domain);
        return str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : $host;
    }

    private function glueIp(Domain $record, ?string $host): ?string
    {
        return collect($this->facts($record)['glue'])->firstWhere('host', $host)['ip'] ?? null;
    }

    private function addGlueAction(): Action
    {
        return Action::make('addGlue')
            ->label('Add child nameserver')
            ->icon('heroicon-o-plus')
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'glue') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'glue'))
            ->schema(fn (Domain $record) => [
                Text::make('A nameserver under this domain, such as ns1.'.$record->domain.'. The registry publishes its address so other domains can use it.'),
                TextInput::make('name')
                    ->label('Host name')
                    ->placeholder('ns1')
                    ->suffix('.'.$record->domain)
                    ->required()
                    ->maxLength(200)
                    ->regex('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/')
                    ->dehydrateStateUsing(fn (?string $state) => self::glueLabel((string) $state, $record->domain))
                    ->notIn(fn () => collect($this->facts($record)['glue'])->map(fn ($glue) => self::glueLabel($glue['host'], $record->domain))->all())
                    ->validationMessages([
                        'regex' => 'Use letters, numbers and hyphens, for example ns1.',
                        'not_in' => 'This child nameserver already exists. Edit it instead.',
                    ]),
                TextInput::make('ip')
                    ->label('IP address')
                    ->placeholder('203.0.113.10')
                    ->helperText('One public IPv4 or IPv6 address.')
                    ->required()
                    ->ip(),
            ])
            ->modalHeading('Add child nameserver')
            ->modalSubmitActionLabel('Add')
            ->modalWidth('lg')
            ->action(fn (Domain $record, array $data) => $this->queueChange($record, 'glue_add', [
                'host' => self::glueLabel((string) ($data['name'] ?? ''), $record->domain).'.'.$record->domain,
                'ip' => trim((string) ($data['ip'] ?? '')),
            ], 'Child nameserver queued.'));
    }

    private function editGlueAction(): Action
    {
        return Action::make('editGlue')
            ->label('Edit child nameserver')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Domain $record) => $this->facts($record)['glue'] !== [])
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'glue') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'glue'))
            ->fillForm(fn (Domain $record) => count($this->facts($record)['glue']) === 1
                ? ['host' => $this->facts($record)['glue'][0]['host'], 'ip' => $this->facts($record)['glue'][0]['ip']] : [])
            ->schema(fn (Domain $record) => [
                Select::make('host')
                    ->label('Child nameserver')
                    ->options(fn () => collect($this->facts($record)['glue'])->pluck('host', 'host')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('ip', $this->glueIp($record, $state))),
                TextInput::make('ip')
                    ->label('New IP address')
                    ->helperText('One public IPv4 or IPv6 address.')
                    ->required()
                    ->ip()
                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                        if (trim((string) $value) === $this->glueIp($record, $get('host'))) { $fail('Enter a different address.'); }
                    }),
            ])
            ->modalHeading('Edit child nameserver')
            ->modalSubmitActionLabel('Save address')
            ->modalWidth('lg')
            ->action(fn (Domain $record, array $data) => $this->queueChange($record, 'glue_update', [
                'host' => (string) ($data['host'] ?? ''),
                'ip' => trim((string) ($data['ip'] ?? '')),
            ], 'Address change queued.'));
    }

    private function deleteGlueAction(): Action
    {
        return Action::make('deleteGlue')
            ->label('Delete child nameserver')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Domain $record) => $this->facts($record)['glue'] !== [])
            ->disabled(fn (Domain $record) => $this->changeBlocker($record, 'glue') !== null)
            ->tooltip(fn (Domain $record) => $this->changeBlocker($record, 'glue'))
            ->fillForm(fn (Domain $record) => count($this->facts($record)['glue']) === 1 ? ['host' => $this->facts($record)['glue'][0]['host']] : [])
            ->schema(fn (Domain $record) => [
                Select::make('host')
                    ->label('Child nameserver')
                    ->options(fn () => $this->glueOptions($record))
                    ->required(),
                Text::make('Domains that still use it as a nameserver stop resolving through it. Enom refuses the deletion while the registry has it in use.'),
            ])
            ->modalIcon('heroicon-o-trash')
            ->modalIconColor('danger')
            ->modalHeading('Delete child nameserver')
            ->modalSubmitActionLabel('Delete')
            ->modalWidth('lg')
            ->action(fn (Domain $record, array $data) => $this->queueChange($record, 'glue_delete', [
                'host' => (string) ($data['host'] ?? ''),
            ], 'Deletion queued.'));
    }

    private function changeWhoPaysAction(): Action
    {
        return Action::make('whoPays')
            ->label('Change who pays')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->size('sm')
            ->visible(fn (Domain $record) => Staff::can(Staff::MANAGE) && ($record->price_mode !== 'complimentary' || Staff::can(Staff::FUND)))
            ->fillForm(fn (Domain $record) => [
                'mode' => in_array($record->price_mode, ['standard', 'complimentary'], true) ? $record->price_mode : 'standard',
            ])
            ->schema(fn (Domain $record) => [
                Radio::make('mode')
                    ->label('Who pays for renewals')
                    ->required()
                    ->live()
                    ->options([
                        'standard' => 'Standard price',
                        'complimentary' => 'Complimentary',
                    ])
                    ->descriptions([
                        'standard' => ($price = $this->standardPrice($record)) ? $price.' a year, from TLDs & pricing.' : 'From TLDs & pricing.',
                        'complimentary' => Staff::can(Staff::FUND) ? 'The client pays nothing. Enom charges your account.' : 'Needs the "Fund domain renewals" permission.',
                    ])
                    ->disableOptionWhen(fn (string $value) => $value === 'complimentary' && !Staff::can(Staff::FUND)),
            ])
            ->modalHeading('Who pays for this domain')
            ->modalDescription('Applies from the next renewal. Invoices already issued keep their amount.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('lg')
            ->action(function (Domain $record, array $data) {
                $mode = (string) ($data['mode'] ?? '');
                $needsFund = $mode === 'complimentary' || $record->price_mode === 'complimentary';
                $staff = Staff::require(...($needsFund ? [Staff::FUND] : []));
                $service = app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class);
                if (Staff::attempt(fn () => $service->setPricing($staff, (int) $record->id, $mode), 'Saved. The next renewal uses this.')) {
                    $this->refreshFacts($record);
                }
            });
    }

    private function standardPrice(Domain $record): ?string
    {
        $tld = Tld::query()->where('server_id', $record->server_id)->where('tld', $record->tld)->first();
        return $tld?->price('renew') ? Labels::money($tld->price('renew'), $record->service?->currency_code) : null;
    }

    private function renewalToggleAction(): Action
    {
        return Action::make('renewal')
            ->label(fn (Domain $record) => $this->facts($record)['renews'] ? 'Turn renewal off' : 'Turn renewal on')
            ->icon(fn (Domain $record) => $this->facts($record)['renews'] ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (Domain $record) => $this->facts($record)['renews'] ? 'danger' : 'gray')
            ->visible(fn (Domain $record) => $record->service_id !== null && $this->registered($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Domain $record) => $this->facts($record)['renews'] ? 'Turn renewal off?' : 'Turn renewal on?')
            ->modalDescription(fn (Domain $record) => $this->facts($record)['renews']
                ? $record->domain.' will expire'.($record->provider_expires_at ? ' on '.$record->provider_expires_at->format('M j, Y') : '').'. Unpaid renewal invoices are cancelled.'
                : 'The domain renews before it expires, as normal.')
            ->action(function (Domain $record) {
                $staff = Staff::require();
                $on = !$this->facts($record)['renews'];
                if (Staff::attempt(fn () => app(\Paymenter\Extensions\Servers\Enom\Services\StaffDomains::class)->setRenewal($staff, (int) $record->id, $on), $on ? 'Renewal turned on.' : 'Renewal turned off.')) {
                    $this->refreshFacts($record);
                }
            });
    }

    private static function safeUrl(callable $build): ?string
    {
        try { return $build(); } catch (Throwable) { return null; }
    }
}


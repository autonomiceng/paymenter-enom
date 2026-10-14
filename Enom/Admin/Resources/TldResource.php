<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Throwable;

class TldResource extends Resource
{
    protected static ?string $model = Tld::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-price-tag-3-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static ?string $navigationLabel = 'TLDs & pricing';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'TLD';

    protected static ?string $pluralModelLabel = 'TLDs';

    protected static ?string $slug = 'tlds';

    protected static bool $isGloballySearchable = false;

    public const MODE_LABELS = ['markup_percent' => 'Markup, percent', 'markup_amount' => 'Markup, amount', 'fixed' => 'Fixed prices'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('server:id,name');
    }

    public static function table(Table $table): Table
    {
        $costs = Staff::can(Staff::COSTS);
        $servers = Server::query()->where('extension', 'Enom')->count();

        return $table
            ->columns([
                TextColumn::make('tld')
                    ->label('TLD')
                    ->formatStateUsing(fn (string $state) => '.'.$state)
                    ->description(fn (Tld $record) => self::modeSummary($record))
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label('For sale')
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->falseColor('gray')
                    ->tooltip(fn (Tld $record) => $record->enabled ? 'For sale. Click to stop selling.' : 'Not for sale. Click to sell.')
                    ->alignCenter()
                    ->sortable()
                    ->action(Action::make('toggleSale')
                        ->action(fn (Tld $record) => self::setEnabled($record, !$record->enabled))),
                TextColumn::make('term')
                    ->label('Term')
                    ->state(fn (Tld $record) => self::termRange($record))
                    ->description(fn (Tld $record) => ($needs = self::registryNeeds($record)) ? 'Needs '.self::shortList($needs) : null)
                    ->tooltip(fn (Tld $record) => ($needs = self::registryNeeds($record)) ? 'Orders ask for: '.implode(', ', $needs).'.' : null)
                    ->wrap()
                    ->extraAttributes(['style' => 'max-width: 13rem'])
                    ->toggleable(),
                self::priceColumn('register', 'Register', $costs),
                self::priceColumn('renew', 'Renew', $costs),
                self::priceColumn('transfer', 'Transfer', $costs),
                TextColumn::make('margin')
                    ->label('Margin')
                    ->tooltip('Renewal price minus Enom cost')
                    ->state(fn (Tld $record) => self::belowCost($record) ? 'Below cost' : self::renewalMargin($record))
                    ->badge(fn (Tld $record) => self::belowCost($record))
                    ->color(fn (Tld $record) => self::belowCost($record) ? 'danger' : null)
                    ->placeholder('—')
                    ->alignEnd()
                    ->visible($costs),
                TextColumn::make('server.name')
                    ->label('Enom server')
                    ->visible($servers > 1),
                TextColumn::make('costs_synced_at')
                    ->label('Costs synced')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label('For sale')
                    ->trueLabel('For sale')
                    ->falseLabel('Not for sale'),
                Filter::make('below_cost')
                    ->label('Priced below cost')
                    ->toggle()
                    ->visible($costs)
                    ->query(fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                        ->whereColumn('register_price', '<', 'cost_register')
                        ->orWhereColumn('renew_price', '<', 'cost_renew')
                        ->orWhereColumn('transfer_price', '<', 'cost_transfer'))),
                Filter::make('registry_details')
                    ->label('Needs registry details')
                    ->toggle()
                    ->visible(fn () => self::hasRegistryColumn())
                    ->query(fn (Builder $query) => $query->where('registry_fields', 'like', '%"required":true%')),
                SelectFilter::make('server_id')
                    ->label('Enom server')
                    ->relationship('server', 'name')
                    ->visible($servers > 1),
            ])
            ->recordActions([
                EditAction::make()->visible($costs)
                    ->label('Set price')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (Tld $record) => 'Price for .'.$record->tld)
                    ->modalWidth('lg')
                    ->schema(fn (Tld $record) => self::priceForm($record))
                    ->using(function (Tld $record, array $data) {
                        Staff::require(Staff::COSTS);
                        self::applyPricing($record, $data);
                        return $record;
                    })
                    ->successNotificationTitle('Price saved'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Put up for sale')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->modalDescription('Each TLD is checked with Enom first. TLDs that need extra registrant details are skipped.')
                        ->action(fn (Collection $records) => self::bulkEnable($records))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disable')
                        ->label('Stop selling')
                        ->icon('heroicon-o-x-circle')
                        ->requiresConfirmation()
                        ->modalDescription('New orders stop. Existing domains still renew at their renewal price.')
                        ->action(function (Collection $records) {
                            Staff::require();
                            $records->each(fn (Tld $tld) => $tld->update(['enabled' => false]));
                            \Filament\Notifications\Notification::make()->title($records->count().' TLDs taken off sale.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markup')->visible($costs)
                        ->label('Set markup')
                        ->icon('heroicon-o-arrow-trending-up')
                        ->schema([
                            Radio::make('pricing_mode')->label('Markup type')->required()->live()->inline()
                                ->options(['markup_percent' => 'Percent of cost', 'markup_amount' => 'Amount over cost'])->default('markup_percent'),
                            TextInput::make('markup')->label('Markup')->required()->numeric()->minValue(0)->maxValue(100000)
                                ->suffix(fn (Get $get) => $get('pricing_mode') === 'markup_amount' ? null : '%')
                                ->prefix(fn (Get $get) => $get('pricing_mode') === 'markup_amount' ? '$' : null),
                        ])
                        ->modalDescription('Prices are recalculated from the last synced Enom cost.')
                        ->action(function (Collection $records, array $data) {
                            Staff::require(Staff::COSTS);
                            $records->each(fn (Tld $tld) => self::applyPricing($tld, $data));
                            \Filament\Notifications\Notification::make()->title('Markup set for '.$records->count().' TLDs.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('fixed')->visible($costs)
                        ->label('Set fixed prices')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            Grid::make(3)->schema(self::fixedInputs()),
                        ])
                        ->action(function (Collection $records, array $data) {
                            Staff::require(Staff::COSTS);
                            $records->each(fn (Tld $tld) => self::applyPricing($tld, ['pricing_mode' => 'fixed'] + $data));
                            \Filament\Notifications\Notification::make()->title('Prices set for '.$records->count().' TLDs.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('sort_order')->orderBy('tld'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->persistFiltersInSession()
            ->emptyStateHeading('No TLDs yet')
            ->emptyStateDescription('Import the list of TLDs from Enom to set prices and start selling.');
    }

    private static function priceColumn(string $action, string $label, bool $costs): TextColumn
    {
        return TextColumn::make($action.'_price')
            ->label($label)
            ->formatStateUsing(fn ($state) => Labels::money($state))
            ->description(fn (Tld $record) => $costs && $record->cost($action) !== null ? 'Cost '.Labels::money($record->cost($action)) : null)
            ->color(fn (Tld $record) => self::below($record, $action) ? 'danger' : null)
            ->placeholder('Not set')
            ->alignEnd()
            ->sortable();
    }

    /** "1–10 yrs", or "2 yrs" when the registry allows one term only. */
    public static function termRange(Tld $record): string
    {
        $terms = self::terms($record);
        $min = $terms[0];
        $max = $terms[count($terms) - 1];
        return ($min === $max ? $min : $min.'–'.$max).' yr'.($max === 1 ? '' : 's');
    }

    /** @return list<int> */
    private static function terms(Tld $record): array
    {
        if (method_exists($record, 'terms')) {
            try { $terms = $record->terms(); if ($terms) { return array_values($terms); } } catch (Throwable) {}
        }
        $min = max(1, min(10, (int) ($record->getAttribute('min_years') ?: 1)));
        return range($min, max($min, min(10, (int) ($record->getAttribute('max_years') ?: 10))));
    }

    /**
     * The registry fields an order for this ending asks for (for example .us nexus), from the stored schema.
     * @return list<array{name: string, label: string, required: bool, parent: ?string, options: array}>
     */
    public static function registryFields(Tld $record): array
    {
        try {
            $fields = method_exists(Catalogue::class, 'attributesFor')
                ? app(Catalogue::class)->attributesFor($record)
                : $record->getAttribute('registry_fields');
        } catch (Throwable) { return []; }
        if (is_string($fields)) { $fields = json_decode($fields, true); }
        if (!is_array($fields)) { return []; }
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null)) { continue; }
            $clean[] = [
                'name' => $field['name'],
                'label' => is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $field['name'],
                'required' => ($field['required'] ?? false) === true,
                'parent' => is_string($field['parent'] ?? null) ? $field['parent'] : null,
                'options' => is_array($field['options'] ?? null) ? $field['options'] : [],
            ];
        }
        return $clean;
    }

    /** Labels of the required top-level registry fields, in sentence case. @return list<string> */
    public static function registryNeeds(Tld $record): array
    {
        $needs = [];
        foreach (self::registryFields($record) as $field) {
            if ($field['required'] && $field['parent'] === null) { $needs[] = self::sentence($field['label']); }
        }
        return $needs;
    }

    /** Lowercase capitalised words but keep acronyms: "Nexus Category" becomes "Nexus category". */
    private static function sentence(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label));
        $lower = preg_replace_callback('/\b([A-Z])([a-z]+)\b/', fn ($m) => strtolower($m[0]), $label);
        return ucfirst((string) $lower);
    }

    private static function shortList(array $items): string
    {
        $items = array_map(fn ($item) => lcfirst(self::sentence($item)), $items);
        return count($items) > 2 ? $items[0].', '.$items[1].' and '.(count($items) - 2).' more' : implode(', ', $items);
    }

    private static function orderRules(Tld $record): string
    {
        $terms = self::terms($record);
        $min = $terms[0];
        $max = $terms[count($terms) - 1];
        $line = $min === $max ? 'Registers for '.$min.' year'.($min === 1 ? '' : 's').' only.'
            : 'Registers for '.$min.' to '.$max.' years.';
        $needs = self::registryNeeds($record);
        return $line.($needs ? ' The order form asks for: '.implode(', ', $needs).'.' : '');
    }

    private static bool $registryColumn;

    private static function hasRegistryColumn(): bool
    {
        try { return self::$registryColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('enom_tlds', 'registry_fields'); }
        catch (Throwable) { return false; }
    }

    private static function modeSummary(Tld $record): string
    {
        $markup = is_numeric($record->markup) ? (float) $record->markup : 0.0;
        // Markup plus retail price reveals cost, so its size needs the costs permission.
        if (!Staff::can(Staff::COSTS)) { return $record->pricing_mode === 'fixed' ? 'Fixed' : 'Markup'; }
        return match ($record->pricing_mode) {
            'markup_percent' => rtrim(rtrim(number_format($markup, 2, '.', ''), '0'), '.').'% markup',
            'markup_amount' => Labels::money($markup).' markup',
            'fixed' => 'Fixed',
            default => '—',
        };
    }

    private static function below(Tld $record, string $action): bool
    {
        if (!Staff::can(Staff::COSTS)) { return false; }
        $price = $record->price($action);
        $cost = $record->cost($action);
        return $price !== null && $cost !== null && (float) $price < (float) $cost;
    }

    private static function belowCost(Tld $record): bool
    {
        foreach (Tld::ACTIONS as $action) {
            if (self::below($record, $action)) { return true; }
        }
        return false;
    }

    private static function renewalMargin(Tld $record): ?string
    {
        $price = $record->price('renew');
        $cost = $record->cost('renew');
        if ($price === null || $cost === null) { return null; }
        return Labels::money(round((float) $price - (float) $cost, 2));
    }

    private static function setEnabled(Tld $record, bool $state): bool
    {
        Staff::require();
        if (!$state) {
            $record->update(['enabled' => false]);
            \Filament\Notifications\Notification::make()->title('.'.$record->tld.' is no longer for sale.')->body('Existing domains still renew.')->success()->send();
            return false;
        }
        $ok = Staff::attempt(fn () => app(Catalogue::class)->enable($record), '.'.$record->tld.' is now for sale.', 'Could not check .'.$record->tld.' with Enom. Try again.');
        return $ok ? true : (bool) $record->fresh()?->enabled;
    }

    private static function bulkEnable(Collection $records): void
    {
        Staff::require();
        $catalogue = app(Catalogue::class);
        $enabled = 0;
        $problems = [];
        foreach ($records as $tld) {
            if ($tld->enabled) { $enabled++; continue; }
            try { $catalogue->enable($tld); $enabled++; }
            catch (\InvalidArgumentException|\RuntimeException $exception) { $problems[] = $exception->getMessage(); }
            catch (Throwable $exception) { report($exception); $problems[] = 'Could not check .'.$tld->tld.' with Enom.'; }
        }
        $notification = \Filament\Notifications\Notification::make()->title($enabled.' of '.$records->count().' TLDs for sale.');
        if ($problems) {
            $notification->body(implode(' ', array_slice($problems, 0, 3)).(count($problems) > 3 ? ' And '.(count($problems) - 3).' more.' : ''))->warning()->persistent();
        } else {
            $notification->success();
        }
        $notification->send();
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function fixedInputs(bool $conditional = false): array
    {
        $inputs = [];
        foreach (['register' => 'Register', 'renew' => 'Renew', 'transfer' => 'Transfer'] as $action => $label) {
            $input = TextInput::make($action.'_price')->label($label)->prefix('$')->numeric()->minValue(0.01)->maxValue(100000)->live(onBlur: true);
            if ($conditional) {
                $input->required(fn (Get $get) => $get('pricing_mode') === 'fixed')->visible(fn (Get $get) => $get('pricing_mode') === 'fixed');
            } else {
                $input->required();
            }
            $inputs[] = $input;
        }
        return $inputs;
    }

    private static function priceForm(Tld $record): array
    {
        return [
            Radio::make('pricing_mode')
                ->label('How to price')
                ->options(self::MODE_LABELS)
                ->descriptions([
                    'markup_percent' => 'Enom cost plus a percentage. Follows cost changes.',
                    'markup_amount' => 'Enom cost plus a fixed amount. Follows cost changes.',
                    'fixed' => 'Your own prices. Cost changes don\'t affect them.',
                ])
                ->required()
                ->live(),
            TextInput::make('markup')
                ->label('Markup')
                ->numeric()
                ->minValue(0)
                ->maxValue(100000)
                ->live(onBlur: true)
                ->suffix(fn (Get $get) => $get('pricing_mode') === 'markup_percent' ? '%' : null)
                ->prefix(fn (Get $get) => $get('pricing_mode') === 'markup_amount' ? '$' : null)
                ->required(fn (Get $get) => $get('pricing_mode') !== 'fixed')
                ->visible(fn (Get $get) => $get('pricing_mode') !== 'fixed'),
            Grid::make(3)->schema(self::fixedInputs(conditional: true)),
            Text::make(fn (Get $get) => self::preview($record, $get))->visible(fn (Get $get) => $get('pricing_mode') !== 'fixed' && Staff::can(Staff::COSTS)),
            Text::make(fn (Get $get) => self::costLine($record))->visible(fn () => Staff::can(Staff::COSTS))->color('gray'),
            Text::make(self::orderRules($record))->color('gray'),
        ];
    }

    private static function preview(Tld $record, Get $get): string
    {
        $mode = (string) $get('pricing_mode');
        $markup = $get('markup');
        if (!is_numeric($markup) || (float) $markup < 0) { return 'Enter a markup to see prices.'; }
        $markup = number_format((float) $markup, 2, '.', '');
        $parts = [];
        foreach (['register' => 'Register', 'renew' => 'renew', 'transfer' => 'transfer'] as $action => $label) {
            $cost = $record->cost($action);
            $parts[] = $label.' '.($cost === null ? 'not available' : Labels::money(Catalogue::retail($cost, $mode, $markup)));
        }
        return 'Prices: '.implode(', ', $parts).'.';
    }

    private static function costLine(Tld $record): string
    {
        $parts = [];
        foreach (['register' => 'register', 'renew' => 'renew', 'transfer' => 'transfer'] as $action => $label) {
            $parts[] = $label.' '.(Labels::money($record->cost($action)) ?? 'unknown');
        }
        return 'Enom cost: '.implode(', ', $parts).'.';
    }

    /** Save a pricing choice. Markup modes recompute from cost; fixed keeps the entered prices. */
    public static function applyPricing(Tld $tld, array $data): void
    {
        $mode = $data['pricing_mode'] ?? $tld->pricing_mode;
        if (!in_array($mode, Tld::MODES, true)) { throw new \InvalidArgumentException('Choose how to price this TLD.'); }
        $tld->pricing_mode = $mode;
        if ($mode === 'fixed') {
            foreach (Tld::ACTIONS as $action) {
                $value = $data[$action.'_price'] ?? null;
                if (!is_numeric($value) || (float) $value <= 0) { throw new \InvalidArgumentException('Enter all three prices.'); }
                $tld->{$action.'_price'} = number_format((float) $value, 2, '.', '');
            }
        } else {
            $tld->markup = number_format((float) ($data['markup'] ?? 0), 2, '.', '');
            Catalogue::applyMarkup($tld);
        }
        // An ending on sale must keep all three prices; otherwise it comes off sale.
        if ($tld->enabled && (!$tld->price('register') || !$tld->price('renew') || !$tld->price('transfer'))) { $tld->enabled = false; }
        $tld->save();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTlds::route('/'),
        ];
    }
}

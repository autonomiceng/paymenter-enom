<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\CatalogueRuntime;
use Paymenter\Extensions\Servers\Enom\Services\PricingRule;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Paymenter\Extensions\Servers\Enom\Services\TransferOrders;
use Throwable;

use Filament\Schemas\Components\Grid;

use Filament\Tables\Filters\SelectFilter;

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

    public const MODE_LABELS = ['markup_percent' => 'Cost + percent', 'markup_amount' => 'Cost + amount', 'fixed' => 'Fixed price'];
    public const ROUND_LABELS = ['none' => "Don't round", 'whole' => 'Next whole dollar (.00)', 'half' => 'Next .50', 'ending_99' => 'Next .99'];

    public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->with('server:id,name'); }

    public static function table(Table $table): Table
    {
        $costs = Staff::can(Staff::COSTS);
        return $table->columns([
            TextColumn::make('tld')->label('TLD')->formatStateUsing(fn (string $state) => '.'.$state)->searchable()->sortable()
                ->description(fn (Tld $record) => $record->use_default_rule ? 'Default rule' : 'Own rule'.($costs ? ': '.(self::MODE_LABELS[$record->pricing_mode] ?? '') : '')),
            ...array_map(fn (string $action) => IconColumn::make($action.'_enabled')->label(ucfirst($action))->boolean()
                ->tooltip(fn (Tld $record) => $record->sale_holds[$action] ?? ($record->{$action.'_enabled'} ? 'Click to turn off.' : 'Click to turn on.'))
                ->action(Action::make('toggle'.ucfirst($action))
                    ->requiresConfirmation(fn (Tld $record) => $action === 'transfer' && !$record->transfer_enabled && !app(TransferOrders::class)->allowed($record))
                    ->modalHeading('Enable transfers for this ending?')
                    ->modalDescription('Confirm that you tested a transfer for this ending. It must add one year using a standard transfer code. This adds the ending to the server transfer allowlist. Unsupported endings remain unavailable.')
                    ->schema(fn (Tld $record) => $action === 'transfer' && !$record->transfer_enabled && !app(TransferOrders::class)->allowed($record)
                        ? [Checkbox::make('tested_transfer')->label('I tested a transfer for this ending on the Enom test system')->accepted()->required()] : [])
                    ->action(fn (Tld $record, array $data) => Staff::attempt(
                        fn () => self::setAction($record, $action, !$record->{$action.'_enabled'}, (bool) ($data['tested_transfer'] ?? false)), 'Sale settings updated.'))), Tld::ACTIONS),
            TextColumn::make('term')->label('Terms on sale')->state(fn (Tld $record) => self::termRange($record))
                ->description(fn (Tld $record) => ($needs = self::registryNeeds($record)) ? 'Needs '.self::shortList($needs) : null)->wrap(),
            ...array_map(fn (string $action) => TextColumn::make($action.'_retail')->label(ucfirst($action).' price')
                ->state(fn (Tld $record) => Labels::money($record->price($action)))->placeholder('Not offered')->alignEnd()
                ->description(fn (Tld $record) => $costs && $record->cost($action) !== null ? 'Cost '.Labels::money($record->cost($action)) : null), Tld::ACTIONS),
            TextColumn::make('holds')->label('Needs attention')->state(fn (Tld $record) => implode(' ', array_map(
                fn ($action, $reason) => (['register' => 'Registration', 'renew' => 'Renewal', 'transfer' => 'Transfer'][$action] ?? ucfirst($action)).': '.$reason,
                array_keys($record->sale_holds ?? []), $record->sale_holds ?? [])))->wrap(),
            TextColumn::make('margin')->label('Renewal margin')->visible($costs)
                ->tooltip('Renewal price minus Enom renewal cost, per year.')
                ->state(fn (Tld $record) => $record->price('renew') !== null && $record->cost('renew') !== null
                    ? Labels::money(((PricingRule::units($record->price('renew'), 2) * 100) - PricingRule::units($record->cost('renew'))) / 10000) : null)->placeholder('—'),
            TextColumn::make('server.name')->label('Enom server')->visible(Server::where('extension', 'Enom')->count() > 1),
            TextColumn::make('costs_synced_at')->label('Costs synced')->since()->dateTimeTooltip()->placeholder('Never'),
        ])->filters([
            TernaryFilter::make('enabled')->label('Listed'),
            Filter::make('below_cost')->label('Priced below cost')->visible($costs)->query(fn (Builder $query) => $query->where('sale_holds', 'like', '%Price is below cost.%')),
        ])->recordActions([
            EditAction::make()->label('Pricing')->visible($costs)->modalHeading(fn (Tld $record) => 'Pricing for .'.$record->tld)->modalWidth('4xl')
                ->fillForm(function (Tld $record) {
                    Staff::require(Staff::COSTS);
                    return ['use_default_rule' => $record->use_default_rule ? '1' : '0'] + self::ruleData(app(Catalogue::class)->rule($record));
                })
                ->schema(fn (Tld $record) => self::pricingForm($record))->using(function (Tld $record, array $data) {
                    self::applyPricing($record, $data);
                    return $record;
                })->successNotificationTitle('Pricing saved'),
        ])->toolbarActions([
            BulkActionGroup::make([
                BulkAction::make('disable')->label('Stop selling')->requiresConfirmation()->action(function (Collection $records) {
                    Staff::require();
                    foreach ($records as $record) { $record->enabled = false; app(Catalogue::class)->publish($record); }
                })->deselectRecordsAfterCompletion(),
                BulkAction::make('default')->label('Use default rule')->visible($costs)->requiresConfirmation()->action(function (Collection $records) {
                    Staff::require(Staff::COSTS);
                    foreach ($records as $record) { $record->use_default_rule = true; app(Catalogue::class)->publish($record); }
                })->deselectRecordsAfterCompletion(),
            ]),
        ])->defaultSort(fn (Builder $query) => $query->orderBy('sort_order')->orderBy('tld'))->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->emptyStateHeading('No TLDs yet')->emptyStateDescription('Import TLDs from Enom, sync costs and choose the actions to offer.');
    }

    public static function setAction(Tld $record, string $action, bool $enabled, bool $confirmedTransfer = false): void
    {
        Staff::require();
        if (!in_array($action, Tld::ACTIONS, true)) { throw new \InvalidArgumentException('Unknown sale action.'); }
        if ($enabled && $action === 'transfer' && !app(TransferOrders::class)->allowed($record)) {
            if (!$confirmedTransfer) { throw new \InvalidArgumentException('Confirm a tested transfer before enabling this ending.'); }
            DB::transaction(function () use ($record) {
                $value = RuntimeGuard::setting((int) $record->server_id, 'transfer_allowlist', 'com,net,org');
                if (!is_string($value)) { throw new \InvalidArgumentException('The transfer allowlist is invalid.'); }
                $record->server->settings()->updateOrCreate(['key' => 'transfer_allowlist'],
                    ['value' => trim($value, ', ').','.$record->tld, 'type' => 'string', 'encrypted' => false]);
                if (!app(TransferOrders::class)->allowed($record)) { throw new \InvalidArgumentException('Transfers are not supported for this ending.'); }
            });
            app(Catalogue::class)->syncCosts((int) $record->server_id);
            $record->refresh();
        }
        $record->{$action.'_enabled'} = $enabled;
        if ($enabled && !$record->enabled) { app(Catalogue::class)->enable($record); }
        else { app(Catalogue::class)->publish($record); }
        if ($enabled && $action !== 'renew' && !$record->orderable($action)) { throw new \InvalidArgumentException($record->sale_holds[$action] ?? 'No sale price is available.'); }
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

    public static function termRange(Tld $record): string
    {
        $terms = $record->terms();
        return $terms ? implode(', ', $terms).' year'.($terms === [1] ? '' : 's') : 'Not offered';
    }

    public static function ruleData(array $rule): array
    {
        return ['pricing_mode' => $rule['mode'], 'markup' => $rule['markup'], 'round_up' => $rule['round_up'],
            'fixed_register' => $rule['fixed']['register'], 'fixed_renew' => $rule['fixed']['renew'], 'fixed_transfer' => $rule['fixed']['transfer']];
    }

    public static function ruleFromData(array $data): array
    {
        return PricingRule::validate(['mode' => $data['pricing_mode'] ?? 'markup_percent', 'markup' => (string) ($data['markup'] ?? '0'),
            'round_up' => $data['round_up'] ?? 'none', 'fixed' => ['register' => $data['fixed_register'] ?? null,
                'renew' => $data['fixed_renew'] ?? null, 'transfer' => $data['fixed_transfer'] ?? null]]);
    }

    public static function pricingForm(?Tld $record = null, ?int $serverId = null): array
    {
        $inherited = fn (Get $get) => $record && filter_var($get('use_default_rule'), FILTER_VALIDATE_BOOLEAN);
        $fields = [];
        if ($record) {
            $fields[] = Radio::make('use_default_rule')->label('Pricing for .'.$record->tld)->options([1 => 'Use the default rule', 0 => 'Set a rule for .'.$record->tld])->required()->live();
        }
        $fields[] = Radio::make('pricing_mode')->label('How to price')->options(self::MODE_LABELS)->required(fn (Get $get) => !$inherited($get))->live()->visible(fn (Get $get) => !$inherited($get));
        $fields[] = TextInput::make('markup')->label('Markup')->numeric()->minValue(0)->maxValue(100000)->live(onBlur: true)
            ->suffix(fn (Get $get) => $get('pricing_mode') === 'markup_percent' ? '%' : null)->prefix(fn (Get $get) => $get('pricing_mode') === 'markup_amount' ? '$' : null)
            ->required(fn (Get $get) => !$inherited($get) && $get('pricing_mode') !== 'fixed')->visible(fn (Get $get) => !$inherited($get) && $get('pricing_mode') !== 'fixed');
        $fields[] = Select::make('round_up')->label('Round up to')->options(self::ROUND_LABELS)->required(fn (Get $get) => !$inherited($get))->live()->visible(fn (Get $get) => !$inherited($get));
        foreach (['register' => 'Registration, per year', 'renew' => 'Renewal, per year', 'transfer' => 'Transfer (adds 1 year)'] as $action => $label) {
            $fields[] = TextInput::make('fixed_'.$action)->label($label)->numeric()->minValue(0.01)->maxValue(99999999)->prefix('$')->live(onBlur: true)
                ->required(fn (Get $get) => !$inherited($get) && $get('pricing_mode') === 'fixed')->visible(fn (Get $get) => !$inherited($get) && $get('pricing_mode') === 'fixed');
        }
        if (!$record && $serverId) {
            $fields[] = Select::make('preview_tld')->label('Preview with')->options(Tld::where('server_id', $serverId)->pluck('tld', 'id')->all())
                ->default(Tld::where('server_id', $serverId)->orderBy('sort_order')->value('id'))->live();
        }
        $fields[] = Text::make(function (Get $get) use ($record, $serverId) {
            $ending = $record ?? Tld::where('server_id', $serverId)->find($get('preview_tld'));
            if (!$ending) { return 'Choose an ending to preview prices.'; }
            try {
                $rule = $record && filter_var($get('use_default_rule'), FILTER_VALIDATE_BOOLEAN)
                    ? CatalogueRuntime::defaultRule((int) $record->server_id) : self::ruleFromData([
                        'pricing_mode' => $get('pricing_mode'), 'markup' => $get('markup'), 'round_up' => $get('round_up'),
                        'fixed_register' => $get('fixed_register'), 'fixed_renew' => $get('fixed_renew'), 'fixed_transfer' => $get('fixed_transfer')]);
                return self::preview($ending, $rule);
            } catch (\InvalidArgumentException) { return 'Enter valid prices to see the preview.'; }
        })->visible(fn () => Staff::can(Staff::COSTS));
        $fields[] = Text::make('Rounding applies to the per-year price before it is multiplied by years. Registration terms priced below cost are taken off sale. A renewal priced below cost alerts staff, but existing domains still renew at the set price. An ending without a renewal price can’t be ordered. Saving updates the generated Paymenter products and plans.')->color('gray');
        return $fields;
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

    public static function preview(Tld $tld, array $rule): HtmlString
    {
        Staff::require(Staff::COSTS);
        $html = '<div class="overflow-x-auto"><table><caption>Price preview for '.e('.'.$tld->tld).'</caption><thead><tr>';
        foreach (['Action', 'Term', 'Enom cost', 'Your price', 'Per year', 'Margin'] as $heading) { $html .= '<th scope="col" class="px-2 py-1">'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach (Tld::ACTIONS as $action) {
            $terms = $action === 'register' ? range(max(1, (int) $tld->min_years), min(10, max((int) $tld->min_years, (int) $tld->max_years))) : [1];
            foreach ($terms as $years) {
                $cost = $tld->cost($action, $years);
                $cells = [ucfirst($action), $years.' year'.($years === 1 ? '' : 's')];
                if ($cost === null) {
                    $retained = $action === 'renew' ? $tld->price('renew') : null;
                    $cells = [...$cells, $action === 'renew' ? 'No current Enom price' : 'No Enom price — not offered',
                        $retained ? '$'.$retained.' retained' : '—', $retained ? '$'.$retained : '—', '—'];
                }
                else {
                    $price = PricingRule::price($cost, $rule, $action);
                    $margin = PricingRule::units($price, 2) * 100 - PricingRule::units($cost);
                    $cells = [...$cells, '$'.PricingRule::decimal(PricingRule::units($cost) * $years, 4), '$'.PricingRule::total($price, $years), '$'.$price,
                        PricingRule::covers($price, $cost) ? '$'.number_format($margin * $years / 10000, 2, '.', '') : 'Below cost'];
                }
                $html .= '<tr>';
                foreach ($cells as $cell) { $html .= '<td class="px-2 py-1">'.e($cell).'</td>'; }
                $html .= '</tr>';
            }
        }
        return new HtmlString($html.'</tbody></table></div>');
    }

    private static function costLine(Tld $record): string
    {
        $parts = [];
        foreach (['register' => 'register', 'renew' => 'renew', 'transfer' => 'transfer'] as $action => $label) {
            $parts[] = $label.' '.(Labels::money($record->cost($action)) ?? 'unknown');
        }
        return 'Enom cost: '.implode(', ', $parts).'.';
    }

    public static function applyPricing(Tld $tld, array $data): void
    {
        Staff::require(Staff::COSTS);
        $inherit = filter_var($data['use_default_rule'] ?? $tld->use_default_rule, FILTER_VALIDATE_BOOLEAN);
        if (!$inherit) {
            $rule = self::ruleFromData($data);
            $tld->fill(self::ruleData($rule));
        }
        $tld->use_default_rule = $inherit;
        app(Catalogue::class)->publish($tld);
    }

    /** Runs a synchronous cost sync and tells staff what changed. Takes about a second per ending. */
    public static function syncCosts(int $serverId): void
    {
        Staff::attempt(function () use ($serverId) {
            [$title, $body, $warn] = Catalogue::syncMessage(app(Catalogue::class)->sync($serverId));
            $notification = \Filament\Notifications\Notification::make()->title($title)->body($body);
            return $warn ? $notification->warning()->persistent() : $notification->success();
        }, 'Costs synced.', 'Couldn’t sync costs. TLDs read before the error keep their new costs. Check the Enom setup page, then try again.');
    }

    public const SYNC_DESCRIPTION = 'Reads Enom’s current costs for every term and recalculates rule-based prices. Fixed prices stay as they are. Prices below cost are taken off sale. This can take a few minutes; keep this page open.';

    public static function defaultRuleAction(Server $server): Action
    {
        return Action::make('defaultRule'.$server->id)->label('Default pricing rule')->color('gray')->visible(fn () => Staff::can(Staff::COSTS))
            ->modalHeading('Default pricing rule: '.$server->name)->modalWidth('4xl')->modalSubmitActionLabel('Save')
            ->fillForm(fn () => self::ruleData(CatalogueRuntime::defaultRule((int) $server->id)))
            ->schema(fn () => self::pricingForm(serverId: (int) $server->id))->action(function (array $data) use ($server) {
                Staff::require(Staff::COSTS);
                Staff::attempt(fn () => 'Default rule applied to '.CatalogueRuntime::saveDefaultRule((int) $server->id, self::ruleFromData($data)).' TLDs.', 'Default rule saved.');
            });
    }
    /**
     * The registry fields an order for this ending asks for (for example .us nexus), from the stored schema.
     * @return list<array{name: string, label: string, required: bool, parent: ?string, options: array}>
     */
    private static function terms(Tld $record): array
    {
        if (method_exists($record, 'terms')) {
            try { $terms = $record->terms(); if ($terms) { return array_values($terms); } } catch (Throwable) {}
        }
        $min = max(1, min(10, (int) ($record->getAttribute('min_years') ?: 1)));
        return range($min, max($min, min(10, (int) ($record->getAttribute('max_years') ?: 10))));
    }

    public static function registryFields(Tld $record): array
    {
        try {
            $fields = app(Catalogue::class)->attributesFor($record);
        } catch (Throwable) { return []; }
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

    public static function getPages(): array { return ['index' => ListTlds::route('/')]; }
}

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
use Filament\Tables\Columns\ColumnGroup;
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
                ->description(fn (Tld $record) => $record->use_default_rule ? 'Default rule' : 'Own rule')
                ->tooltip(fn (Tld $record) => $costs && !$record->use_default_rule ? 'Own rule: '.(self::MODE_LABELS[$record->pricing_mode] ?? '') : null),
            ColumnGroup::make('On sale', array_map(fn (string $action) => TextColumn::make($action.'_sale')->label(ucfirst($action))->badge()
                ->state(fn (Tld $record) => self::saleState($record, $action))
                ->formatStateUsing(fn (string $state) => ['on' => 'On', 'off' => 'Off', 'none' => 'Not offered'][$state])
                ->color(fn (string $state) => ['on' => 'success', 'off' => 'gray', 'none' => 'warning'][$state])
                // Registration terms and registry needs sit under the Register switch.
                ->description(fn (Tld $record) => $action === 'register' && $record->terms()
                    ? self::termRange($record).(($needs = self::registryNeeds($record)) ? ', needs '.self::shortList($needs) : '') : null)
                ->tooltip(fn (Tld $record) => $record->sale_holds[$action] ?? ($record->{$action.'_enabled'} ? 'Click to turn off.' : 'Click to turn on.'))
                ->action(Action::make('toggle'.ucfirst($action))
                    ->requiresConfirmation(fn (Tld $record) => $action === 'transfer' && !$record->transfer_enabled && !app(TransferOrders::class)->allowed($record))
                    ->modalHeading('Enable transfers for this ending?')
                    ->modalDescription('Confirm that you tested a transfer for this ending. It must add one year using a standard transfer code. This adds the ending to the server transfer allowlist. Unsupported endings remain unavailable.')
                    ->schema(fn (Tld $record) => $action === 'transfer' && !$record->transfer_enabled && !app(TransferOrders::class)->allowed($record)
                        ? [Checkbox::make('tested_transfer')->label('I tested a transfer for this ending on the Enom test system')->accepted()->required()] : [])
                    ->action(fn (Tld $record, array $data) => Staff::attempt(
                        fn () => self::setAction($record, $action, !$record->{$action.'_enabled'}, (bool) ($data['tested_transfer'] ?? false)), 'Sale settings updated.'))), Tld::ACTIONS)),
            ColumnGroup::make('Price', array_map(fn (string $action) => TextColumn::make($action.'_retail')->label(ucfirst($action))
                ->state(fn (Tld $record) => Labels::money($record->price($action)))->placeholder('—')->alignEnd()
                ->description(fn (Tld $record) => $costs && $record->cost($action) !== null ? 'Cost '.Labels::money($record->cost($action)) : null), Tld::ACTIONS)),
            TextColumn::make('holds')->label('Needs attention')->badge()->listWithLineBreaks()
                ->state(fn (Tld $record) => array_values(array_map(fn (string $action) => ucfirst($action).': '.self::holdLabel($record->sale_holds[$action]), array_keys(self::attention($record)))))
                ->color(fn (string $state) => str_contains($state, 'below cost') ? 'danger' : 'warning')
                ->tooltip(fn (Tld $record) => implode(' ', array_map(fn (string $action, string $reason) => ucfirst($action).': '.$reason, array_keys($holds = self::attention($record)), $holds)) ?: null)
                ->placeholder('—'),
            TextColumn::make('margin')->label('Margin')->visible($costs)->alignEnd()
                ->tooltip('Renewal price minus Enom renewal cost, per year.')
                ->state(fn (Tld $record) => $record->price('renew') !== null && $record->cost('renew') !== null
                    ? Labels::money(((PricingRule::units($record->price('renew'), 2) * 100) - PricingRule::units($record->cost('renew'))) / 10000) : null)->placeholder('—'),
            TextColumn::make('server.name')->label('Enom server')->visible(Server::where('extension', 'Enom')->count() > 1),
            TextColumn::make('costs_synced_at')->label('Costs synced')->since()->dateTimeTooltip()->placeholder('Never')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            TernaryFilter::make('enabled')->label('Listed'),
            Filter::make('below_cost')->label('Priced below cost')->visible($costs)->query(fn (Builder $query) => $query->where('sale_holds', 'like', '%Price is below cost.%')),
        ])->recordActions([
            EditAction::make()->label('Pricing')->iconButton()->tooltip('Pricing')->visible($costs)->modalHeading(fn (Tld $record) => 'Pricing for .'.$record->tld)->modalWidth('4xl')
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

    public static function termRange(Tld $record): string
    {
        $terms = $record->terms();
        if (!$terms) { return 'Not offered'; }
        $run = count($terms) > 1 && end($terms) - reset($terms) === count($terms) - 1;
        return ($run ? reset($terms).'–'.end($terms) : implode(', ', $terms)).' year'.($terms === [1] ? '' : 's');
    }

    /** 'on', 'off', or 'none' when the action is on but nothing can be sold (no term or price). Unlisted endings show the switch only. */
    public static function saleState(Tld $record, string $action): string
    {
        if (!$record->{$action.'_enabled'}) { return 'off'; }
        if (!$record->enabled) { return 'on'; }
        return match ($action) {
            'register' => $record->terms() !== [],
            'renew' => $record->price('renew') !== null,
            default => $record->price('transfer') !== null,
        } ? 'on' : 'none';
    }

    /**
     * Holds staff must act on. Unlisted endings and actions staff switched off are left out; a price below cost
     * stays because the guard, not staff, turned the action off.
     * @return array<string, string>
     */
    public static function attention(Tld $record): array
    {
        if (!$record->enabled) { return []; }
        return array_filter($record->sale_holds ?? [], fn ($reason, string $action) => is_string($reason)
            && ($record->{$action.'_enabled'} || str_starts_with($reason, 'Price is below cost.')), ARRAY_FILTER_USE_BOTH);
    }

    /** Short badge text for a hold; the full reason is in the tooltip. */
    public static function holdLabel(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'Price is below cost.') => 'below cost',
            str_starts_with($reason, 'Not on the transfer list.') => 'not on list',
            str_starts_with($reason, 'An annual renewal price') => 'no renewal price',
            str_starts_with($reason, 'No current price') || str_starts_with($reason, 'No positive price') => 'no price',
            default => 'unsupported',
        };
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

    public static function preview(Tld $tld, array $rule): HtmlString
    {
        Staff::require(Staff::COSTS);
        // Text columns start-aligned, money end-aligned, so headings sit over their figures.
        $align = fn (int $column) => 'class="px-2 py-1" style="white-space: nowrap; '.($column < 2 ? 'text-align: start' : 'text-align: end; font-variant-numeric: tabular-nums').'"';
        $html = '<div class="overflow-x-auto"><table><caption class="px-2 py-1" style="text-align: start">Price preview for '.e('.'.$tld->tld).'</caption><thead><tr>';
        foreach (['Action', 'Term', 'Enom cost', 'Your price', 'Per year', 'Margin'] as $column => $heading) { $html .= '<th scope="col" '.$align($column).'>'.$heading.'</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach (Tld::ACTIONS as $action) {
            $terms = $action === 'register' ? range(max(1, (int) $tld->min_years), min(10, max((int) $tld->min_years, (int) $tld->max_years))) : [1];
            foreach ($terms as $years) {
                $cost = $tld->cost($action, $years);
                $cells = [ucfirst($action), $years.' year'.($years === 1 ? '' : 's')];
                if ($cost === null) {
                    $retained = $action === 'renew' ? $tld->price('renew') : null;
                    $cells = [...$cells, $action === 'renew' ? 'No current Enom price' : 'No Enom price — not offered',
                        $retained ? Labels::money($retained).' retained' : '—', $retained ? Labels::money($retained) : '—', '—'];
                }
                else {
                    $price = PricingRule::price($cost, $rule, $action);
                    $margin = PricingRule::units($price, 2) * 100 - PricingRule::units($cost);
                    $cells = [...$cells, Labels::money(PricingRule::decimal(PricingRule::units($cost) * $years, 4)), Labels::money(PricingRule::total($price, $years)),
                        Labels::money($price), PricingRule::covers($price, $cost) ? Labels::money(PricingRule::decimal($margin * $years, 4)) : 'Below cost'];
                }
                $html .= '<tr>';
                foreach (array_values($cells) as $column => $cell) { $html .= '<td '.$align($column).'>'.e($cell).'</td>'; }
                $html .= '</tr>';
            }
        }
        return new HtmlString($html.'</tbody></table></div>');
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

    /** Runs a synchronous cost sync and tells staff what changed. Takes about 7 seconds per ending on Enom TEST. */
    public static function syncCosts(int $serverId): void
    {
        Staff::attempt(function () use ($serverId) {
            $summary = app(Catalogue::class)->sync($serverId);
            [$title, $body, $warn] = Catalogue::syncMessage($summary);
            $notification = \Filament\Notifications\Notification::make()->title($title)->body($body);
            // One ending below cost opens its pricing; several open the below-cost filter.
            $belowCost = array_values(array_unique([...$summary['held'], ...$summary['renewals_below_cost']]));
            $held = count($belowCost) === 1 ? Tld::where('server_id', $serverId)->where('tld', ltrim($belowCost[0], '.'))->first() : null;
            $review = $held ? Catalogue::pricingUrl($held) : ($belowCost ? self::getUrl('index', ['tableFilters' => ['below_cost' => ['isActive' => true]]]) : null);
            if ($review) { $notification->actions([Action::make('review')->label('Review pricing')->url($review)]); }
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

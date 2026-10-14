<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Pages;

use App\Admin\Resources\NotificationTemplateResource;
use App\Admin\Resources\ServerResource;
use App\Models\NotificationTemplate;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\Enom\Admin\Pages\Widgets\DomainCounts;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Throwable;

/** Connection, environment and setup progress for each Enom server. */
class EnomSetup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-settings-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-settings-3-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static ?string $navigationLabel = 'Enom setup';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Enom setup';

    protected static ?string $slug = 'enom-setup';

    /** Connection test results from this visit, keyed by server id. */
    public array $tests = [];

    public static function canAccess(): bool
    {
        return Staff::can(Staff::MANAGE);
    }

    protected function getHeaderWidgets(): array
    {
        return [DomainCounts::class];
    }

    /** @return \Illuminate\Support\Collection<int, Server> */
    private function servers()
    {
        return Server::query()->where('extension', 'Enom')->orderBy('id')->get();
    }

    public function content(Schema $schema): Schema
    {
        abort_unless(static::canAccess(), 403);
        $servers = $this->servers();
        if ($servers->isEmpty()) {
            return $schema->components([
                Section::make('Connect your Enom account')
                    ->description('Add an Enom server with your account ID and API token to start selling domains.')
                    ->afterHeader([
                        Action::make('addServer')->label('Add Enom server')->icon('heroicon-o-plus')
                            ->url(fn () => self::safeUrl(fn () => ServerResource::getUrl('create')))
                            ->visible(fn () => Staff::can('admin.servers.create')),
                    ]),
            ]);
        }
        return $schema->components([
            ...$servers->map(fn (Server $server) => $this->serverSection($server))->all(),
            $this->remindersSection($servers),
        ]);
    }

    public const REMINDER_TEMPLATES = [
        'enom_domain_expiring' => 'Domain expiring',
        'enom_domain_expired' => 'Domain expired',
        'enom_domain_order_refunded' => 'Order refunded',
    ];

    /** Reminder schedule from the server settings, links to the native email templates and recent sends. */
    private function remindersSection($servers): Section
    {
        $schedule = fn (string $key, string $default) => $servers->map(function (Server $server) use ($key, $default, $servers) {
            $days = self::days((string) RuntimeGuard::setting((int) $server->id, $key, $default));
            return ($servers->count() > 1 ? $server->name.': ' : '').($days ? self::dayList($days).' day'.($days === [1] ? '' : 's') : 'None');
        })->all();
        $stats = self::reminderCount();
        $single = $servers->count() === 1 ? self::serverUrl((int) $servers->first()->id) : null;

        return Section::make('Renewal reminders')
            ->description('Emails clients before and after a domain expires.')
            ->afterHeader([
                Action::make('reminderSchedule')
                    ->label('Change schedule')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->url($single)
                    ->visible($single !== null),
            ])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => $stats === null ? 3 : 4])->schema([
                    TextEntry::make('reminderBefore')->label('Before expiry')
                        ->state($schedule('reminder_days', '60,30,7,1'))
                        ->listWithLineBreaks()
                        ->belowContent('Complimentary and paid-up domains get the 30-day one only'),
                    TextEntry::make('reminderAfter')->label('After expiry')
                        ->state($schedule('expired_reminder_days', '3'))
                        ->listWithLineBreaks(),
                    TextEntry::make('reminderSent')->label('Sent in the last 30 days')
                        ->state($stats)
                        ->numeric()
                        ->visible($stats !== null),
                    TextEntry::make('reminderTemplates')->label('Email templates')
                        ->state(self::templateLinks())
                        ->html()
                        ->listWithLineBreaks(),
                ]),
            ]);
    }

    /** @return list<int> */
    private static function days(string $value): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $value) ?: []), fn (int $day) => $day > 0 && $day <= 365)));
        rsort($days);
        return $days;
    }

    private static function dayList(array $days): string
    {
        return count($days) > 1 ? implode(', ', array_slice($days, 0, -1)).' and '.end($days) : (string) $days[0];
    }

    private static function reminderCount(): ?int
    {
        $service = Staff::service('reminderStats');
        if (!$service) { return null; }
        try { $stats = $service->reminderStats(); } catch (Throwable $exception) { report($exception); return null; }
        $count = is_array($stats) ? ($stats['sent_30_days'] ?? $stats['sent_last_30_days'] ?? $stats['last_30_days'] ?? $stats['sent'] ?? $stats['count'] ?? null) : $stats;
        return is_numeric($count) ? (int) $count : null;
    }

    /** @return list<string> HTML links to the native editor, or plain names without the permission. */
    private static function templateLinks(): array
    {
        $templates = NotificationTemplate::query()->whereIn('key', array_keys(self::REMINDER_TEMPLATES))->get(['id', 'key', 'enabled'])->keyBy('key');
        $edit = Staff::can('admin.notification_templates.update');
        $links = [];
        foreach (self::REMINDER_TEMPLATES as $key => $name) {
            $template = $templates->get($key);
            $url = $template && $edit ? self::safeUrl(fn () => NotificationTemplateResource::getUrl('edit', ['record' => $template->id])) : null;
            $suffix = !$template ? ' <span class="text-gray-500 dark:text-gray-400">(not installed)</span>'
                : (!$template->enabled ? ' <span class="text-gray-500 dark:text-gray-400">(off)</span>' : '');
            $links[] = ($url ? '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'.e($name).'</a>' : e($name)).$suffix;
        }
        return $links;
    }

    private function serverSection(Server $server): Section
    {
        $id = (int) $server->id;
        $status = RuntimeGuard::status($id);
        $tlds = Tld::query()->where('server_id', $id)->count();
        $forSale = Tld::query()->where('server_id', $id)->where('enabled', true)->count();
        $nameservers = RuntimeGuard::defaultNameservers($id);
        $live = $status['environment'] === 'live';

        $steps = [
            ['Add the server and credentials', $status['credentials'], self::serverUrl($id), 'Account ID and API token in the server settings.'],
            ['Test the connection', fn () => isset($this->tests[$id]) ? (bool) $this->tests[$id]['ok'] : null, null, fn () => isset($this->tests[$id]) ? null : 'Use Test connection above.'],
            ['Import TLDs and set prices', $tlds > 0, TldResource::getUrl('index'), $tlds > 0 ? $tlds.' TLDs imported.' : null],
            ['Put TLDs up for sale', $forSale > 0, TldResource::getUrl('index', ['filters' => ['enabled' => ['value' => '1']]]), $forSale > 0 ? $forSale.' for sale.' : null],
            ['Set default nameservers', $nameservers !== [], self::serverUrl($id), $nameservers ? null : 'Optional. Without them, Enom\'s nameservers are used.'],
            ['Turn on processing', $status['processing'], self::serverUrl($id), $status['processing'] ? null : '"Process orders and changes" in the server settings.'],
        ];

        return Section::make($server->name)
            ->description($server->enabled ? null : 'This server is turned off in Paymenter.')
            ->afterHeader([
                Action::make('test'.$id)
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->disabled(!$status['credentials'])
                    ->action(fn () => $this->testConnection($id)),
                Action::make('confirmLive'.$id)
                    ->label('Confirm live mode')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible($live && !$status['live_confirmed'] && Staff::can(Staff::MANAGE, 'admin.servers.update'))
                    ->requiresConfirmation()
                    ->modalHeading('Confirm live mode for this site?')
                    ->modalDescription('Live requests will run from '.RuntimeGuard::siteUrl().'. Real domains are registered and your Enom balance is charged. A copy of this database on another site cannot send live requests.')
                    ->modalSubmitActionLabel('Confirm live mode')
                    ->action(function () use ($id) {
                        Staff::require('admin.servers.update');
                        Staff::attempt(fn () => RuntimeGuard::confirmLive($id), 'Live mode confirmed for this site.');
                    }),
                Action::make('edit'.$id)
                    ->label('Server settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->url(self::serverUrl($id))
                    ->visible(self::serverUrl($id) !== null),
            ])
            ->schema([
                Grid::make(['default' => 2, 'lg' => 5])->schema([
                    TextEntry::make('env'.$id)->label('Environment')
                        ->state($live ? 'Live' : 'Test')
                        ->badge()->color($live ? 'success' : 'warning')
                        ->belowContent($live ? 'reseller.enom.com' : 'resellertest.enom.com'),
                    TextEntry::make('connection'.$id)->label('Connection')
                        ->state(fn () => ($t = $this->tests[$id] ?? null) ? ($t['ok'] ? 'Connected' : 'Failed') : ($status['credentials'] ? 'Not tested' : 'No credentials'))
                        ->badge()->color(fn () => ($t = $this->tests[$id] ?? null) ? ($t['ok'] ? 'success' : 'danger') : 'gray')
                        ->belowContent(fn () => ($t = $this->tests[$id] ?? null) && !$t['ok'] ? $t['message'] : null),
                    TextEntry::make('balance'.$id)->label('Enom balance')
                        ->state(fn () => isset($this->tests[$id]['balance']) ? Labels::money($this->tests[$id]['balance']) : null)
                        ->placeholder('Test to see it'),
                    TextEntry::make('processing'.$id)->label('Processing')
                        ->state($status['processing'] ? 'On' : 'Off')
                        ->badge()->color($status['processing'] ? 'success' : 'gray')
                        ->belowContent($status['processing'] ? null : 'Orders and changes wait'),
                    TextEntry::make('binding'.$id)->label('Account')
                        ->state($status['bound'] ? 'Linked to domains' : 'Not linked yet')
                        ->belowContent($status['bound'] ? 'Can\'t switch accounts' : 'Links on the first order'),
                ]),
                TextEntry::make('problem'.$id)->hiddenLabel()
                    ->state($status['problem'])
                    ->color('danger')
                    ->icon('heroicon-o-exclamation-circle')
                    ->iconColor('danger')
                    ->visible($status['problem'] !== null && $status['credentials']),
                TextEntry::make('live'.$id)->hiddenLabel()
                    ->state('Live mode is confirmed for '.($status['live_confirmed_for'] ?? RuntimeGuard::siteUrl()).'.')
                    ->icon('heroicon-o-shield-check')->iconColor('success')
                    ->visible($live && $status['live_confirmed']),
                Section::make('Setup steps')
                    ->compact()
                    ->secondary()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema(array_map(
                            fn (array $step, int $index) => TextEntry::make('step'.$id.'_'.$index)
                                ->hiddenLabel()
                                ->state(($index + 1).'. '.$step[0])
                                ->icon(fn () => match (value($step[1])) { true => 'heroicon-o-check-circle', false => 'heroicon-o-minus-circle', default => 'heroicon-o-ellipsis-horizontal-circle' })
                                ->iconColor(fn () => match (value($step[1])) { true => 'success', false => 'warning', default => 'gray' })
                                ->url($step[2])
                                ->belowContent(fn () => value($step[3])),
                            $steps, array_keys($steps))),
                    ]),
            ]);
    }

    public function testConnection(int $serverId): void
    {
        Staff::require();
        $service = Staff::service('testConnection');
        if (!$service) { Notification::make()->title('Connection testing is not available yet.')->danger()->send(); return; }
        try {
            $result = $service->testConnection($serverId);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'message' => 'Could not reach Enom.'];
        }
        $this->tests[$serverId] = ['ok' => (bool) ($result['ok'] ?? false), 'message' => (string) ($result['message'] ?? ''),
            'balance' => $result['balance'] ?? null];
        Notification::make()->title($this->tests[$serverId]['ok'] ? 'Connected to Enom.' : 'Connection failed')
            ->body($this->tests[$serverId]['ok'] ? null : $this->tests[$serverId]['message'])
            ->{$this->tests[$serverId]['ok'] ? 'success' : 'danger'}()->send();
    }

    private static function serverUrl(int $id): ?string
    {
        return Staff::can('admin.servers.update') ? self::safeUrl(fn () => ServerResource::getUrl('edit', ['record' => $id])) : null;
    }

    private static function safeUrl(callable $build): ?string
    {
        try { return $build(); } catch (Throwable) { return null; }
    }
}

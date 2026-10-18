<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;

class ListTlds extends ListRecords
{
    protected static string $resource = TldResource::class;

    /** @return array<int, string> */
    private static function servers(): array
    {
        return Server::query()->where('extension', 'Enom')->orderBy('id')->pluck('name', 'id')->all();
    }

    private static function serverField(): array
    {
        $servers = self::servers();
        return count($servers) > 1
            ? [Select::make('server_id')->label('Enom server')->options($servers)->required()->default(array_key_first($servers))]
            : [];
    }

    private static function serverId(array $data): ?int
    {
        $servers = self::servers();
        $id = (int) ($data['server_id'] ?? array_key_first($servers));
        return isset($servers[$id]) ? $id : null;
    }

    public function getTitle(): string
    {
        return 'TLDs & pricing';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...Server::where('extension', 'Enom')->orderBy('id')->get()->map(fn (Server $server) => TldResource::defaultRuleAction($server))->all(),
            Action::make('sync')
                ->label('Sync costs now')->visible(fn () => Staff::can(Staff::COSTS))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->disabled(fn () => self::servers() === [])
                ->schema(self::serverField())
                ->requiresConfirmation(count(self::servers()) <= 1)
                ->modalHeading('Sync costs from Enom')
                ->modalDescription(TldResource::SYNC_DESCRIPTION)
                ->modalSubmitActionLabel('Sync costs')
                ->action(function (array $data) {
                    Staff::require(Staff::COSTS);
                    $server = self::serverId($data);
                    if ($server === null) { \Filament\Notifications\Notification::make()->title('Choose a server.')->danger()->send(); return; }
                    TldResource::syncCosts($server);
                }),
            Action::make('import')
                ->label('Import TLDs from Enom')->visible(fn () => Staff::can(Staff::COSTS))
                ->icon('heroicon-o-arrow-down-tray')
                ->disabled(fn () => self::servers() === [])
                ->schema(self::serverField())
                ->requiresConfirmation(count(self::servers()) <= 1)
                ->modalHeading('Import TLDs from Enom')
                ->modalDescription('Adds every TLD your account offers, not for sale, using the default pricing rule. Existing rules are kept.')
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data) {
                    Staff::require(Staff::COSTS);
                    $server = self::serverId($data);
                    if ($server === null) { \Filament\Notifications\Notification::make()->title('Choose a server.')->danger()->send(); return; }
                    Staff::attempt(function () use ($server) {
                        $result = app(Catalogue::class)->import($server);
                        return $result['added'] > 0
                            ? 'Added '.$result['added'].' TLDs. Costs synced for '.$result['costs'].'.'
                            : 'No new TLDs. Costs synced for '.$result['costs'].'.';
                    }, 'Imported.');
                }),
        ];
    }
}

<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Schedule;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use RuntimeException;
use Throwable;

final class CatalogueRuntime
{
    public static function boot(): void
    {
        Schedule::call(function () {
            foreach (Server::where('extension', 'Enom')->where('enabled', true)->pluck('id') as $id) {
                try { app(Catalogue::class)->syncCosts((int) $id); } catch (Throwable $exception) { report($exception); }
            }
        })->name('enom-catalogue-costs')->dailyAt('00:00')->withoutOverlapping(120)->onOneServer();
    }

    /** Native server inputs cannot edit a nested rule; the setup page provides its form. */
    public static function config(): array
    {
        return [['name' => 'enom_default_pricing_note', 'type' => 'placeholder', 'label' => 'Default pricing rule',
            'description' => 'Set the default mode, markup, fixed prices and rounding in Domains → Enom setup. Generated domain products and plans are managed by the extension.']];
    }

    public static function defaultRule(int $serverId): array
    {
        $rule = RuntimeGuard::setting($serverId, 'pricing_default_rule', PricingRule::DEFAULT);
        if (!is_array($rule)) { throw new RuntimeException('The default pricing rule is invalid.'); }
        return PricingRule::validate($rule);
    }

    /** @return array{published: int, failed: list<string>} */
    public static function saveDefaultRule(int $serverId, array $rule): array
    {
        $rule = PricingRule::validate($rule);
        $server = Server::where('extension', 'Enom')->findOrFail($serverId);
        $server->settings()->updateOrCreate(['key' => 'pricing_default_rule'], ['type' => 'array', 'encrypted' => false, 'value' => $rule]);
        $summary = ['published' => 0, 'failed' => []];
        // Publication owns its product-first transaction for each ending.
        foreach (Tld::where('server_id', $serverId)->where('use_default_rule', true)->orderBy('id')->get() as $tld) {
            try {
                app(Catalogue::class)->publish($tld);
                $summary['published']++;
            } catch (Throwable $exception) {
                report($exception);
                $summary['failed'][] = '.'.$tld->tld;
            }
        }
        return $summary;
    }
}

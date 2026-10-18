<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Throwable;

/**
 * Renewal reminder emails. Each domain gets at most one reminder per offset per expiry date.
 * Templates are native Paymenter notification templates, so staff can edit them.
 */
class Reminders
{
    public const BEFORE_DEFAULT = '60,30,7,1';

    public const AFTER_DEFAULT = '3';

    /** Create the extension's notification templates if they're missing. Never overwrites staff edits. */
    public static function ensureTemplates(): void
    {
        // Write only columns this Paymenter version has; in_app_url isn't mass-assignable on the native model.
        $columns = array_flip(Schema::getColumnListing('notification_templates'));
        foreach (self::templates() as $key => $template) {
            if (!NotificationTemplate::where('key', $key)->exists()) {
                (new NotificationTemplate)->forceFill(array_intersect_key(['key' => $key] + $template, $columns))->save();
            }
        }
    }

    /** Send what's due today. Returns the number of reminders sent. */
    public function sweep(): int
    {
        self::ensureTemplates();
        // Look as far ahead and back as any server's settings reach.
        [$ahead, $behind] = [60, 37];
        foreach (\App\Models\Server::where('extension', 'Enom')->pluck('id') as $serverId) {
            $ahead = max($ahead, ...self::offsets(RuntimeGuard::setting((int) $serverId, 'reminder_days', self::BEFORE_DEFAULT)) ?: [0]);
            $behind = max($behind, ...self::offsets(RuntimeGuard::setting((int) $serverId, 'expired_reminder_days', self::AFTER_DEFAULT)) ?: [0]);
        }
        $ahead += 1;
        $behind += 3;
        $sent = 0;
        Domain::query()->whereIn('status', ['active', 'expired', 'grace'])->whereNotNull('provider_expires_at')
            ->where('provider_expires_at', '<=', now()->addDays($ahead)->toDateString())
            ->where('provider_expires_at', '>=', now()->subDays($behind)->toDateString())
            ->orderBy('id')->chunkById(100, function ($domains) use (&$sent) {
                foreach ($domains as $domain) {
                    try { $sent += $this->remind($domain) ? 1 : 0; } catch (Throwable $e) { report($e); }
                }
            });
        return $sent;
    }

    /** Send the reminder due for this domain today, if any. */
    public function remind(Domain $domain): bool
    {
        // Claim the reminder under lock, judging the domain as it is now, not as it was when the sweep read it.
        $claim = DB::transaction(function () use ($domain) {
            $locked = Domain::lockForUpdate()->find($domain->id);
            $service = $locked?->service;
            if (!$locked || !$service || (int) $service->user_id !== (int) $locked->user_id || !$locked->provider_expires_at
                || $service->status !== \App\Models\Service::STATUS_ACTIVE || !in_array($locked->status, ['active', 'expired', 'grace'], true)) { return null; }
            $expiry = CarbonImmutable::parse($locked->provider_expires_at->toDateString());
            $daysLeft = (int) CarbonImmutable::today()->diffInDays($expiry, false);
            $offset = $this->dueOffset($locked, $daysLeft);
            if ($offset === null) { return null; }
            $renewOn = !$service->cancellation()->exists();
            $complimentary = Pricing::isComplimentary($locked);
            $invoice = $this->openRenewalInvoice($locked);
            // A renewal already paid for (or queued at our cost) for this expiry needs no reminder.
            if ($renewOn && !$invoice && Operation::where('domain_id', $locked->id)->where('action', 'renew')
                ->whereDate('base_expires_at', $expiry->toDateString())->whereIn('state', ['queued', 'running', 'pending', 'succeeded'])->exists()) { return null; }
            // Domains that need nothing from the client hear from us once, a month out.
            if ($daysLeft >= 0 && $renewOn && !$invoice && $offset !== 30) { return null; }
            // When the next invoice will arrive, as a phrase: "on October 23, 2026" or "before it expires".
            $invoiceOn = null;
            if ($renewOn && !$invoice && !$complimentary) {
                $invoiceOn = 'before it expires';
                if ($locked->billing_managed) {
                    try {
                        $issue = CarbonImmutable::parse(app(DomainBilling::class)->calendar($locked)['issue']);
                        if ($issue->isFuture()) { $invoiceOn = 'on '.$issue->format('F j, Y'); }
                    } catch (Throwable) {}
                }
            }
            $sent = $locked->reminders ?? [];
            $sent[$expiry->toDateString()][] = $offset;
            $locked->update(['reminders' => array_slice($sent, -3, null, true)]);
            return ['domain' => $locked, 'expiry' => $expiry, 'offset' => $offset, 'days_left' => $daysLeft, 'user_id' => (int) $locked->user_id,
                'data' => ['domain' => $locked->domain, 'expires' => $expiry->format('F j, Y'), 'days' => abs($daysLeft),
                    'renews_automatically' => $renewOn, 'complimentary' => $complimentary, 'invoice_on' => $invoiceOn,
                    'invoice' => $invoice, 'invoice_url' => $invoice ? route('invoices.show', $invoice) : null,
                    'due' => $invoice?->due_at ? CarbonImmutable::parse($invoice->due_at)->format('F j, Y') : null,
                    'domain_url' => route('enom.domains.show', $locked->id)]];
        });
        if (!$claim) { return false; }
        try {
            NotificationHelper::sendNotification($claim['days_left'] < 0 ? 'enom_domain_expired' : 'enom_domain_expiring', $claim['data'], User::findOrFail($claim['user_id']));
        } catch (Throwable $error) {
            // Release the claim so the next run (within the catch-up window) tries again.
            DB::transaction(function () use ($claim) {
                $locked = Domain::lockForUpdate()->find($claim['domain']->id);
                $sent = $locked?->reminders ?? [];
                $key = $claim['expiry']->toDateString();
                $sent[$key] = array_values(array_diff($sent[$key] ?? [], [$claim['offset']]));
                $locked?->update(['reminders' => $sent]);
            });
            throw $error;
        }
        return true;
    }

    /**
     * The configured offset due now, or null. Negative day counts are after expiry. A reminder missed
     * on its day (a failed run) is still sent within two days, unless a later one is already due.
     */
    private function dueOffset(Domain $domain, int $daysLeft): ?int
    {
        $sent = $domain->reminders[$domain->provider_expires_at?->toDateString()] ?? [];
        $before = self::offsets(RuntimeGuard::setting((int) $domain->server_id, 'reminder_days', self::BEFORE_DEFAULT));
        $after = array_map(fn ($day) => -$day, self::offsets(RuntimeGuard::setting((int) $domain->server_id, 'expired_reminder_days', self::AFTER_DEFAULT)));
        $candidates = array_filter(array_merge($before, $after), fn ($offset) => $offset >= $daysLeft && $offset - $daysLeft <= 2 && !in_array($offset, $sent, true));
        if (!$candidates) { return null; }
        return min($candidates); // The closest one; older missed reminders are superseded.
    }

    /** @return list<int> */
    public static function offsets(mixed $value): array
    {
        $days = [];
        foreach (preg_split('/[\s,]+/', is_string($value) ? $value : (string) $value) ?: [] as $part) {
            if (ctype_digit($part) && (int) $part <= 365 && !in_array((int) $part, $days, true)) { $days[] = (int) $part; }
        }
        return $days;
    }

    private function openRenewalInvoice(Domain $domain): ?Invoice
    {
        $operation = Operation::where('domain_id', $domain->id)->where('action', 'renew')->where('state', 'awaiting_payment')
            ->whereNotNull('invoice_id')->latest('id')->first();
        $invoice = $operation ? Invoice::find($operation->invoice_id) : null;
        return $invoice && $invoice->status === Invoice::STATUS_PENDING && (int) $invoice->user_id === (int) $domain->user_id ? $invoice : null;
    }

    /** Reminders recorded in the last $days days, for the setup page. */
    public static function stats(int $days = 30): int
    {
        $count = 0;
        $from = now()->subDays($days)->toDateString();
        foreach (Domain::query()->whereNotNull('reminders')->get(['id', 'reminders']) as $domain) {
            foreach ($domain->reminders ?? [] as $expiry => $offsets) {
                foreach ((array) $offsets as $offset) {
                    $sentOn = CarbonImmutable::parse($expiry)->subDays((int) $offset)->toDateString();
                    if ($sentOn >= $from && $sentOn <= now()->toDateString()) { $count++; }
                }
            }
        }
        return $count;
    }

    private static function templates(): array
    {
        $button = fn (string $url, string $label) => "<div class=\"action\">\n\t<a class=\"button button-blue\" href=\"{{ {$url} }}\">{$label}</a>\n</div>";
        return TransferNotifications::templates() + [
            'enom_domain_expiring' => [
                'name' => 'Domain expiring soon', 'subject' => '{{ $domain }} expires on {{ $expires }}', 'enabled' => true,
                'mail_enabled' => 'choice_on', 'in_app_enabled' => 'choice_on',
                'in_app_title' => '{{ $domain }} expires in {{ $days }} {{ $days === 1 ? \'day\' : \'days\' }}',
                'in_app_body' => '@if($invoice)Pay the renewal invoice by {{ $due }}.@elseif($invoice_on)Your renewal invoice will arrive {{ $invoice_on }}.@elseif($renews_automatically)It will renew automatically.@else Renewal is off. Renew it to keep it.@endif',
                'in_app_url' => '{{ $domain_url }}',
                'edit_preference_message' => 'Remind me before my domains expire',
                'body' => "# {{ \$domain }} expires on {{ \$expires }}\n\n@if(\$invoice)\nYour renewal invoice is ready. Pay it by **{{ \$due }}** and we'll renew the domain for you.\n\n".$button('$invoice_url', 'Pay invoice')."\n@elseif(\$invoice_on)\nWe'll email your renewal invoice {{ \$invoice_on }}. Pay it by the due date and we'll renew the domain for you.\n@elseif(\$renews_automatically)\n@if(\$complimentary)\nThis domain is complimentary. It will renew automatically at no cost; there's nothing you need to do.\n@else\nIt will renew automatically. There's nothing you need to do.\n@endif\n@else\nRenewal is turned off, so the domain will expire unless you renew it. Websites and email that use it will stop working.\n\n".$button('$domain_url', 'Renew domain')."\n@endif\n",
            ],
            'enom_domain_expired' => [
                'name' => 'Domain expired', 'subject' => '{{ $domain }} has expired', 'enabled' => true,
                'mail_enabled' => 'choice_on', 'in_app_enabled' => 'choice_on',
                'in_app_title' => '{{ $domain }} has expired', 'in_app_body' => 'Renew it soon to keep it.', 'in_app_url' => '{{ $domain_url }}',
                'edit_preference_message' => 'Tell me when a domain expires',
                'body' => "# {{ \$domain }} expired on {{ \$expires }}\n\nWebsites and email that use this domain may stop working. You can usually still renew it for a short time after it expires.\n\n@if(\$invoice)\n".$button('$invoice_url', 'Pay renewal invoice')."\n@else\n".$button('$domain_url', 'Renew domain')."\n@endif\n",
            ],
            'enom_domain_order_refunded' => [
                'name' => 'Domain order refunded', 'subject' => 'We refunded your {{ $action }} of {{ $domain }}', 'enabled' => true,
                'mail_enabled' => 'force', 'in_app_enabled' => 'choice_on',
                'in_app_title' => '{{ $amount }} {{ $currency }} added to your credit', 'in_app_body' => 'The {{ $action }} of {{ $domain }} could not be completed.',
                'in_app_url' => '{{ $domain_url }}',
                'edit_preference_message' => 'Tell me about domain refunds',
                'body' => "# Your {{ \$action }} of {{ \$domain }} could not be completed\n\nThe registry did not accept it, so we added **{{ \$amount }} {{ \$currency }}** to your account credit. It is used automatically on your next invoice.\n\n".$button('$domain_url', 'View domain')."\n",
            ],
        ];
    }
}

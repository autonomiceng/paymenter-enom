<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Helpers\NotificationHelper;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

/** Native queued mail and encrypted once-only markers share the payment transaction. */
final class TransferNotifications
{
    public function sendDue(Operation $operation): void
    {
        Reminders::ensureTemplates();
        $ownerId = Domain::whereKey($operation->domain_id)->value('user_id');
        DB::transaction(function () use ($operation, $ownerId) {
            $user = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $current->setRelation('domain', $domain);
            $deadline = TransferOrders::deadline($current);
            if ($current->action !== 'transfer' || !in_array($current->state, ['awaiting_payment', 'awaiting_code', 'held', 'queued'], true)
                || $domain->status !== 'transfer_pending' || !in_array($domain->service?->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
                || !TransferOrders::unsent($current) || !app(TransferOrders::class)->needsCode($current)
                || !app(DomainOperations::class)->funded($current) || !$deadline || !$deadline->isFuture()) { return; }
            $policy = $current->policy ?? [];
            $due = ['paid' => true, 'reminder' => $deadline->subDays(7)->lessThanOrEqualTo(now())];
            foreach ($due as $kind => $ready) {
                $marker = 'transfer_code_'.$kind.'_notified_at';
                if (!$ready || isset($policy[$marker]) || config('settings.mail_disable')) { continue; }
                $template = NotificationTemplate::where('key', 'enom_transfer_code_'.$kind)->first();
                $preference = $template ? $user->notificationsPreferences()->where('notification_template_id', $template->id)->first() : null;
                if (!$template?->enabled || !$template->isEnabledForPreference($preference, 'mail')) { continue; }
                // Only public display data reaches templates or queued mail; never the request payload.
                NotificationHelper::sendNotification('enom_transfer_code_'.$kind, [
                    'domain' => $domain->domain, 'deadline' => $deadline->format('M j, Y'),
                    'amount' => '$'.number_format((float) $current->amount, 2, '.', ','),
                    'domain_url' => route('enom.domains.show', $domain->id).'#transfer-code',
                ], $user);
                $policy[$marker] = now()->utc()->toIso8601String();
            }
            $current->update(['policy' => $policy]);
        });
    }

    public static function templates(): array
    {
        $deadline = "Enter it by {{ \$deadline }}: {{ \$domain_url }}\n\nIf we don't have it by then, we'll cancel the transfer and add {{ \$amount }} to your account credit.";
        $common = ['enabled' => true, 'mail_enabled' => 'force', 'in_app_enabled' => 'never',
            'in_app_title' => 'Enter the transfer code for {{ $domain }}', 'in_app_body' => 'Enter it by {{ $deadline }}.',
            'in_app_url' => '{{ $domain_url }}', 'edit_preference_message' => 'Transfer code reminders'];
        return [
            'enom_transfer_code_paid' => $common + ['name' => 'Paid transfer needs a code',
                'subject' => 'Enter the transfer code for {{ $domain }}',
                'body' => "Thanks for your payment. To move {{ \$domain }} to us, enter its transfer code (also called an auth code). "
                    ."Your current registrar gives you this code. The transfer starts as soon as we have it.\n\n".$deadline],
            'enom_transfer_code_reminder' => $common + ['name' => 'Transfer code deadline reminder',
                'subject' => 'Reminder: transfer code for {{ $domain }}',
                'body' => "We still need the transfer code for {{ \$domain }}. ".$deadline],
        ];
    }
}

<?php
// 0.4 renewal reminder emails: native templates created once (staff edits kept), due offsets with a two-day catch-up and
// after-expiry reminders, once per offset per expiry cycle, no reminder when the cycle is already paid or queued,
// state-dependent wording, revalidation under lock, a failed send releasing its claim, the sweep window derived from
// server settings, notification preferences and the setup-page count. Disposable native app; no provider calls.
$enomTestLabel = 'reminders';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\Reminders;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

// The harness boots the extension after the app, so its named routes need the lookup Paymenter's boot order provides.
app('router')->getRoutes()->refreshNameLookups();
function setReminderTestDate(string $day): void { Carbon\Carbon::setTestNow($day.' 09:00:00'); CarbonImmutable::setTestNow(Carbon\Carbon::now()); }
setReminderTestDate('2026-10-14');
$operations = new DomainOperations(null, fn () => throw new RuntimeException('Reminders must not contact Enom.'));
$app->instance(DomainOperations::class, $operations);
$reminders = new Reminders;
$owner = User::create(['email' => 'remind-owner@example.invalid', 'password' => 'synthetic']);
$other = User::create(['email' => 'remind-other@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Reminder Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$category = App\Models\Category::create(['name' => 'Domains', 'slug' => 'domains']);
$product = App\Models\Product::create(['name' => 'Domain name', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Yearly', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$counter = 0;
/** A domain expiring $days days from today. $renew false adds a native cancellation (renewal off). */
function expiring(int $days, bool $renew = false, array $attributes = [], ?User $user = null): Domain {
    global $counter, $owner, $server, $product, $plan;
    $counter++;
    $user ??= $owner;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '14.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
    if (!$renew) { ServiceCancellation::create(['service_id' => $service->id, 'reason' => 'Renewal off', 'type' => 'end_of_period']); }
    return Domain::create($attributes + ['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'remind'.$counter.'.com', 'sld' => 'remind'.$counter,
        'tld' => 'com', 'status' => 'active', 'provider_expires_at' => now()->addDays($days)->toDateString(), 'billing_managed' => false, 'capabilities' => []]);
}
function latestNote(): ?Notification { global $owner; return Notification::where('user_id', $owner->id)->latest('id')->first(); }
function noteCount(): int { return Notification::count(); }
function sent(Domain $domain): array { return $domain->fresh()->reminders ?? []; }

// ---------------------------------------------------------------- templates: created on install, once, staff edits kept
$keys = ['enom_domain_expiring', 'enom_domain_expired', 'enom_domain_order_refunded'];
verify(NotificationTemplate::whereIn('key', $keys)->count() === 3, 'Install did not create the three notification templates.');
$expiringTemplate = NotificationTemplate::where('key', 'enom_domain_expiring')->sole();
verify($expiringTemplate->in_app_url === '{{ $domain_url }}' && $expiringTemplate->enabled && $expiringTemplate->mail_enabled === App\Enums\NotificationEnabledStatus::ChoiceOn,
    'Template in_app_url (not mass-assignable natively) or defaults were not stored.');
verify(!Schema::hasColumn('notification_templates', 'name'), 'Fixture assumption: Paymenter 1.5.9 has no template name column (ensureTemplates drops it).');
$expiringTemplate->update(['subject' => 'Staff wording for {{ $domain }}']);
Reminders::ensureTemplates();
(new Paymenter\Extensions\Servers\Enom\Enom)->installed();
verify(NotificationTemplate::whereIn('key', $keys)->count() === 3 && NotificationTemplate::where('key', 'enom_domain_expiring')->value('subject') === 'Staff wording for {{ $domain }}',
    'Templates were duplicated or a staff edit was overwritten.');
NotificationTemplate::where('key', 'enom_domain_expired')->delete();
$reminders->sweep();
verify(NotificationTemplate::where('key', 'enom_domain_expired')->count() === 1, 'A deleted template was not recreated by the sweep.');

// ---------------------------------------------------------------- offsets: on the day, two-day catch-up, never twice
verify(Reminders::offsets('60, 30,7 ,1') === [60, 30, 7, 1] && Reminders::offsets('5,5,x,400,-2,0') === [5, 0] && Reminders::offsets(null) === [], 'Offset parsing differs.');
$off = expiring(60);
$before = noteCount();
verify($reminders->remind($off) && noteCount() === $before + 1, 'Sixty-day reminder (renewal off) was not sent.');
$note = latestNote();
verify($note->title === $off->domain.' expires in 60 days' && str_contains($note->body, 'Renewal is off') && $note->url === route('enom.domains.show', $off->id), 'Renewal-off wording differs: '.$note->title.' / '.$note->body);
verify(sent($off) === [$off->provider_expires_at->toDateString() => [60]], 'Sent offset not recorded against the expiry.');
verify(!$reminders->remind($off->fresh()) && noteCount() === $before + 1, 'Same reminder sent twice on its day.');
setReminderTestDate('2026-10-15');
verify(!$reminders->remind($off->fresh()), 'Already-sent reminder repeated during catch-up.');
// A missed reminder is caught up within two days, not three.
setReminderTestDate('2026-10-14');
$missedOne = expiring(59); $missedTwo = expiring(58); $missedThree = expiring(57);
verify($reminders->remind($missedOne) && $reminders->remind($missedTwo) && !$reminders->remind($missedThree), 'Catch-up window differs from two days.');
verify(sent($missedTwo)[$missedTwo->provider_expires_at->toDateString()] === [60], 'Catch-up recorded the wrong offset.');
// The closest due offset wins: a domain at 6 days with nothing sent gets the 7-day reminder only.
$closest = expiring(6);
verify($reminders->remind($closest) && sent($closest)[$closest->provider_expires_at->toDateString()] === [7] && !$reminders->remind($closest->fresh()), 'Closest due offset not chosen once.');
verify(!$reminders->remind(expiring(45)), 'A reminder was sent between offsets.');
// After expiry: the expired template on day 3 after.
$expired = expiring(-3, false, ['status' => 'expired']);
verify($reminders->remind($expired) && latestNote()->title === $expired->domain.' has expired', 'After-expiry reminder not sent with the expired template.');
verify(!$reminders->remind(expiring(-6, false, ['status' => 'expired'])), 'A reminder was sent outside the after-expiry catch-up.');
// A new expiry is a new cycle; earlier cycles are kept (last three).
$cycle = expiring(30);
$reminders->remind($cycle);
$cycle->update(['provider_expires_at' => now()->addDays(30 + 365)->toDateString()]);
setReminderTestDate(CarbonImmutable::parse($cycle->provider_expires_at)->subDays(30)->toDateString());
verify($reminders->remind($cycle->fresh()) && count(sent($cycle)) === 2, 'The next expiry cycle did not get its own reminder.');
setReminderTestDate('2026-10-14');

// ---------------------------------------------------------------- state-dependent content
// Renewal on with nothing to pay: only the 30-day reminder; complimentary wording says so.
$auto = expiring(60, true);
verify(!$reminders->remind($auto), 'Renewal-on domain got a 60-day reminder.');
$autoThirty = expiring(30, true, ['price_mode' => 'complimentary']);
verify($reminders->remind($autoThirty) && str_contains(latestNote()->body, 'It will renew automatically'), 'Complimentary 30-day reminder wording differs: '.latestNote()->body);
// Managed paid domain without an invoice yet: tells the client when the invoice will arrive.
$managed = expiring(30, true, ['billing_managed' => true, 'renewal_policy' => ['enabled' => true]]);
verify($reminders->remind($managed) && str_contains(latestNote()->body, 'Your renewal invoice will arrive on '.now()->addDays(9)->format('F j, Y')), 'Invoice-arrival wording differs: '.latestNote()->body);
// An open renewal invoice: pay it by its due date (and the 7-day reminder goes out, unlike for automatic renewals).
$billed = expiring(7, true);
$invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_PENDING, 'due_at' => now()->addDays(3)]);
Operation::create(['domain_id' => $billed->id, 'service_id' => $billed->service_id, 'invoice_id' => $invoice->id, 'operation_key' => 'remind-open', 'action' => 'renew', 'term' => 1,
    'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment', 'amount' => '14.00', 'currency' => 'USD', 'base_expires_at' => $billed->provider_expires_at, 'payload' => []]);
verify($reminders->remind($billed) && str_contains(latestNote()->body, 'Pay the renewal invoice by '.now()->addDays(3)->format('F j, Y')), 'Open-invoice wording differs: '.latestNote()->body);
// A renewal already paid or queued for this expiry needs no reminder; a rejected one does not count.
foreach (['queued', 'running', 'pending', 'succeeded'] as $state) {
    $covered = expiring(30, true);
    Operation::create(['domain_id' => $covered->id, 'service_id' => $covered->service_id, 'operation_key' => 'remind-'.$state, 'action' => 'renew', 'term' => 1, 'funding_basis' => 'complimentary',
        'state' => $state, 'amount' => '0.00', 'currency' => 'USD', 'base_expires_at' => $covered->provider_expires_at, 'payload' => []]);
    verify(!$reminders->remind($covered) && sent($covered) === [], 'Reminder sent although the cycle renewal is '.$state.'.');
}
$failedRenewal = expiring(30, true);
Operation::create(['domain_id' => $failedRenewal->id, 'service_id' => $failedRenewal->service_id, 'operation_key' => 'remind-rejected', 'action' => 'renew', 'term' => 1, 'funding_basis' => 'paid_invoice',
    'state' => 'rejected', 'amount' => '14.00', 'currency' => 'USD', 'base_expires_at' => $failedRenewal->provider_expires_at, 'payload' => []]);
verify($reminders->remind($failedRenewal), 'A rejected renewal suppressed the reminder.');

// ---------------------------------------------------------------- revalidated under lock: only active, owned services
foreach (['suspended service' => fn (Domain $d) => Service::whereKey($d->service_id)->update(['status' => 'suspended']),
    'cancelled domain' => fn (Domain $d) => $d->update(['status' => 'cancelled']),
    'foreign service' => fn (Domain $d) => Service::whereKey($d->service_id)->update(['user_id' => $other->id]),
    'no expiry' => fn (Domain $d) => $d->update(['provider_expires_at' => null])] as $case => $change) {
    $stale = expiring(60);
    $snapshot = $stale->fresh(); // As the sweep read it.
    $change($stale);
    $before = noteCount();
    verify(!$reminders->remind($snapshot) && noteCount() === $before && sent($stale) === [], 'Reminder sent for a '.$case.'.');
}

// ---------------------------------------------------------------- a failed send releases its claim for the next run
NotificationTemplate::where('key', 'enom_domain_expiring')->update(['in_app_title' => '{{ $missing->property }}', 'mail_enabled' => 'never']);
$failing = expiring(30);
refuses(fn () => $reminders->remind($failing), '', 'A failed send was reported as sent.', [Throwable::class]);
verify(sent($failing)[$failing->provider_expires_at->toDateString()] === [], 'Failed send kept its claim.');
NotificationTemplate::where('key', 'enom_domain_expiring')->update(['in_app_title' => '{{ $domain }} expires in {{ $days }} {{ $days === 1 ? \'day\' : \'days\' }}', 'mail_enabled' => 'choice_on']);
setReminderTestDate('2026-10-15');
verify($reminders->remind($failing->fresh()) && sent($failing)[$failing->provider_expires_at->toDateString()] === [30], 'Released reminder was not sent on the next run.');
setReminderTestDate('2026-10-14');
// Client preferences are respected (in-app off: no notification, but the reminder counts as sent).
$prefers = User::create(['email' => 'quiet@example.invalid', 'password' => 'synthetic']);
NotificationPreference::create(['user_id' => $prefers->id, 'notification_template_id' => NotificationTemplate::where('key', 'enom_domain_expiring')->value('id'), 'mail_enabled' => false, 'in_app_enabled' => false]);
$quiet = expiring(60, false, [], $prefers);
verify($reminders->remind($quiet) && Notification::where('user_id', $prefers->id)->doesntExist(), 'A client who turned reminders off was notified.');

// ---------------------------------------------------------------- sweep: window from settings, every eligible domain once
Domain::query()->update(['reminders' => null]);
Notification::query()->delete();
$far = expiring(90);
$longAgo = expiring(-45, false, ['status' => 'expired']);
$sentDefault = $reminders->sweep();
verify(sent($far) === [] && sent($longAgo) === [], 'Default sweep window reached a 90-day or 45-days-expired domain.');
verify($sentDefault > 0 && $reminders->sweep() === 0, 'Sweep sent nothing, or repeated reminders on a second run.');
$server->settings()->create(['key' => 'reminder_days', 'value' => '90, 30']);
$server->settings()->create(['key' => 'expired_reminder_days', 'value' => '45']);
$server->unsetRelation('settings');
verify($reminders->sweep() >= 2 && sent($far) === [$far->provider_expires_at->toDateString() => [90]] && sent($longAgo) === [$longAgo->provider_expires_at->toDateString() => [-45]],
    'Configured offsets beyond the default window were not swept.');
// The setup page count: reminders whose send day falls in the last 30 days.
$statsBefore = Reminders::stats();
verify($statsBefore > 0 && app(StaffDomains::class)->reminderStats() === $statsBefore, 'Reminder count unavailable to staff.');
setReminderTestDate('2027-02-01');
verify(Reminders::stats() < $statsBefore, 'Reminder count did not age out.');

echo 'Reminders: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; native templates and notifications; SQLite in memory.'.PHP_EOL;
$successful = true;

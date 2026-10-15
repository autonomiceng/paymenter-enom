@php [$badgeLabel, $badgeTone] = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::badge($status ?? null, $progress ?? null); @endphp
<span class="enom-badge enom-tone-{{ $badgeTone }}">{{ $badgeLabel }}</span>

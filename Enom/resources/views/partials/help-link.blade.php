{{-- Plain link, works without JavaScript. $route is enom.help or enom.admin.help; customer pages never link to staff help. --}}
<a class="enom-link underline text-sm" href="{{ route($route ?? 'enom.help', $topic ?? null) }}">{{ $label ?? 'Help' }}</a>

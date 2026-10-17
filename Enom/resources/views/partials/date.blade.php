{{-- A calendar date (expiry, due date). Shown as stored: no time-zone conversion. --}}
@php
    $calendar = null;
    if ($date instanceof \DateTimeInterface) { $calendar = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $date->format('Y-m-d')); }
    elseif (is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}/', $date)) {
        $inputDate = substr($date, 0, 10);
        $calendar = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $inputDate);
        if ($calendar->format('Y-m-d') !== $inputDate) { $calendar = null; }
    }
@endphp
@if ($calendar)<time datetime="{{ $calendar->format('Y-m-d') }}">{{ $calendar->format('M j, Y') }}</time>@else{{ $fallback ?? 'Not set' }}@endif

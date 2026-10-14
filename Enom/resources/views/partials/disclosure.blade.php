{{-- Native disclosure: Enter and Space open it, and it works without JavaScript. Use with @component and a slot. --}}
<details class="border-t border-neutral pt-2 {{ $class ?? '' }}" @if (!empty($open)) open @endif>
    <summary class="text-sm font-semibold">{{ $summary ?? 'More info' }}</summary>
    <div class="mt-2 space-y-2 text-sm">{{ $slot }}</div>
</details>

{{-- Flash and error notices for client pages. Field errors are shown beside their fields; this lists the rest. --}}
@php
    $fieldKeys = $fieldKeys ?? [];
    $general = collect($errors->getMessages())->reject(fn ($messages, $key) => collect($fieldKeys)->contains(fn ($prefix) => $key === $prefix || str_starts_with($key, $prefix.'.')))->flatten()->unique();
    $hasFieldErrors = collect($errors->keys())->contains(fn ($key) => collect($fieldKeys)->contains(fn ($prefix) => $key === $prefix || str_starts_with($key, $prefix.'.')));
@endphp
@if (session('enom_status'))
    <div class="enom-notice enom-notice--ok" role="status"><x-ri-checkbox-circle-fill aria-hidden="true" /><div>{{ session('enom_status') }}</div></div>
@endif
@if (session('enom_notice'))
    <div class="enom-notice" role="status"><x-ri-information-fill aria-hidden="true" /><div>{{ session('enom_notice') }}</div></div>
@endif
@php $summary = ($summary ?? true) && $hasFieldErrors; @endphp
@if ($general->isNotEmpty() || $summary)
    <div class="enom-notice enom-notice--bad" role="alert"><x-ri-error-warning-fill aria-hidden="true" />
        <div>
            @foreach ($general as $message)<p>{{ $message }}</p>@endforeach
            @if ($summary)<p>{{ $general->isEmpty() ? 'Check the highlighted fields below.' : 'Also check the highlighted fields below.' }}</p>@endif
        </div>
    </div>
@endif

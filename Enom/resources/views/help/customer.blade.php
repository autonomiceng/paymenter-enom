@component('layouts.app', ['title' => $topic ? $topics[$topic]['title'] : 'Domain help', 'sidebar' => true])
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    @if ($topic)
        <a class="enom-link underline" href="{{ route('enom.help') }}">All domain help</a>
    @else
        <a class="enom-link underline" href="{{ route('enom.domains.index') }}">Back to domains</a>
    @endif
    <h1 class="text-2xl font-semibold">{{ $topic ? $topics[$topic]['title'] : 'Domain help' }}</h1>
    @if ($topic)
        <article class="border border-neutral p-5 rounded-lg">
            <div class="enom-prose text-sm">
                @include('enom::help.customer.'.$topic)
            </div>
        </article>
        <nav aria-label="Other help topics" class="space-y-2">
            <h2 class="font-semibold">Other topics</h2>
            <div class="enom-nav text-sm">
                @foreach ($topics as $key => $entry)
                    @continue($key === $topic)
                    <a class="enom-link underline" href="{{ route('enom.help', $key) }}">{{ $entry['title'] }}</a>
                @endforeach
            </div>
        </nav>
    @else
        <p class="enom-prose">Answers about ordering, paying and managing your domains. Each page describes what this portal does today.</p>
        <ul class="space-y-3">
            @foreach ($topics as $key => $entry)
                <li class="border border-neutral p-4 rounded-lg">
                    <a class="enom-link underline font-semibold" href="{{ route('enom.help', $key) }}">{{ $entry['title'] }}</a>
                    <p class="text-sm">{{ $entry['summary'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@include('enom::partials.copy-script')
@endcomponent

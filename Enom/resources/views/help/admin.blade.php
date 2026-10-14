@component('layouts.app', ['title' => $topic ? $topics[$topic]['title'] : 'Enom staff help', 'sidebar' => true])
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    @if ($topic)
        <a class="enom-link underline" href="{{ route('enom.admin.help') }}">All staff help</a>
    @else
        <a class="enom-link underline" href="{{ route('enom.admin.index') }}">Back to Enom administration</a>
    @endif
    <h1 class="text-2xl font-semibold">{{ $topic ? $topics[$topic]['title'] : 'Enom staff help' }}</h1>
    @if ($topic)
        <article class="border border-neutral p-5 rounded-lg">
            <div class="enom-prose text-sm">
                @include('enom::help.admin.'.$topic)
            </div>
        </article>
        <nav aria-label="Other staff help topics" class="space-y-2">
            <h2 class="font-semibold">Other topics</h2>
            <div class="enom-nav text-sm">
                @foreach ($topics as $key => $entry)
                    @continue($key === $topic)
                    <a class="enom-link underline" href="{{ route('enom.admin.help', $key) }}">{{ $entry['title'] }}</a>
                @endforeach
            </div>
        </nav>
    @else
        <p class="enom-prose">How the Enom extension behaves today, for staff with Enom access. Nothing on these pages changes settings or requests.</p>
        <ul class="space-y-3">
            @foreach ($topics as $key => $entry)
                <li class="border border-neutral p-4 rounded-lg">
                    <a class="enom-link underline font-semibold" href="{{ route('enom.admin.help', $key) }}">{{ $entry['title'] }}</a>
                    <p class="text-sm">{{ $entry['summary'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@include('enom::partials.copy-script')
@endcomponent

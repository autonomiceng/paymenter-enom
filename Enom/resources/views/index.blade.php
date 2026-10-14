@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $controller = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class;
    $params = array_filter(['q' => $search, 'status' => $filter, 'sort' => $sort === 'expiry' ? null : $sort]);
    $url = fn (array $changes) => route('enom.domains.index', array_filter([...$params, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $sortLink = fn (string $column) => $url(['sort' => $sort === $column ? '-'.$column : ($column === 'expiry' ? null : $column), 'page' => null]);
    $sortIcon = fn (string $column) => $sort === $column ? 'ri-arrow-up-s-line' : ($sort === '-'.$column ? 'ri-arrow-down-s-line' : null);
    $total = $filterCounts[''] ?? 0;
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $testEnvironment])
    <div class="enom-head">
        <div>
            <h1 class="enom-title">Domains</h1>
            @if ($total > 0)<p class="enom-lede">{{ $total }} {{ $total === 1 ? 'domain' : 'domains' }} in your account.</p>@endif
        </div>
        <div class="enom-row">
            <a class="enom-btn enom-btn--secondary" href="{{ route('enom.domains.transfer') }}"><x-ri-arrow-left-right-line class="enom-i" aria-hidden="true" />Transfer a domain</a>
            <a class="enom-btn enom-btn--primary" href="{{ route('enom.domains.search') }}"><x-ri-add-line class="enom-i" aria-hidden="true" />Register a domain</a>
        </div>
    </div>
    @include('enom::partials.notices')

    @if ($total === 0)
        <section class="enom-panel enom-empty" aria-labelledby="enom-empty-heading">
            <span class="enom-empty__mark"><x-ri-global-line aria-hidden="true" /></span>
            <h2 id="enom-empty-heading" class="enom-h2" style="font-size:1.25rem">Find your domain name</h2>
            <p>Search for a new name, or move a domain you already own from another registrar.</p>
            <form method="GET" action="{{ route('enom.domains.search') }}" class="enom-searchbar" role="search" style="max-width:34rem;margin-inline:auto">
                <label for="enom-empty-q" class="enom-sr">Domain name</label>
                <input id="enom-empty-q" name="name" type="text" placeholder="Search for a name" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required>
                <button type="submit" class="enom-btn enom-btn--primary">Search</button>
            </form>
            <p class="enom-small" style="margin:1rem auto 0">Already own a domain elsewhere? <a class="enom-a" href="{{ route('enom.domains.transfer') }}">Transfer it here</a></p>
        </section>
    @else
        <section class="enom-panel" aria-labelledby="enom-list-heading">
            <h2 id="enom-list-heading" class="enom-sr">Your domains</h2>
            <div class="enom-toolbar">
                <nav class="enom-chips" aria-label="Filter by status">
                    <a class="enom-chip" href="{{ $url(['status' => null, 'page' => null]) }}" @if ($filter === '') aria-current="true" @endif>All <small>{{ $total }}</small></a>
                    @foreach ($controller::FILTERS as $key => [$label])
                        @if (($filterCounts[$key] ?? 0) > 0 || $filter === $key)
                            <a class="enom-chip" href="{{ $url(['status' => $key, 'page' => null]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }} <small>{{ $filterCounts[$key] ?? 0 }}</small></a>
                        @endif
                    @endforeach
                </nav>
                <form method="GET" action="{{ route('enom.domains.index') }}" class="enom-filter" role="search">
                    @if ($filter !== '')<input type="hidden" name="status" value="{{ $filter }}">@endif
                    <div class="enom-search-mini">
                        <x-ri-search-line class="enom-i" aria-hidden="true" />
                        <label for="enom-q" class="enom-sr">Search your domains</label>
                        <input id="enom-q" class="enom-input" type="search" name="q" value="{{ $search }}" placeholder="Search domains" maxlength="253" autocomplete="off">
                    </div>
                    <label for="enom-sort" class="enom-sr">Sort by</label>
                    <select id="enom-sort" name="sort" class="enom-input" data-enom-autosubmit>
                        <option value="expiry" @selected($sort === 'expiry')>Expiry, soonest first</option>
                        <option value="-expiry" @selected($sort === '-expiry')>Expiry, latest first</option>
                        <option value="name" @selected($sort === 'name')>Name, A to Z</option>
                        <option value="-name" @selected($sort === '-name')>Name, Z to A</option>
                    </select>
                    <button type="submit" class="enom-btn enom-btn--secondary enom-btn--sm" data-nojs-only>Apply</button>
                </form>
            </div>
            @if ($domains->isEmpty())
                <div class="enom-empty" style="padding-block:2.5rem">
                    <p style="margin-bottom:.75rem">No domains match {{ $search !== '' ? '“'.$search.'”' : 'this filter' }}.</p>
                    <a class="enom-a" href="{{ route('enom.domains.index') }}">Show all domains</a>
                </div>
            @else
                <div class="enom-tablewrap">
                    <table class="enom-tbl enom-stackable">
                        <thead>
                            <tr>
                                <th scope="col"><a href="{{ $sortLink('name') }}" @if ($sortIcon('name')) aria-current="true" @endif>Domain @if ($sortIcon('name'))<x-dynamic-component :component="$sortIcon('name')" class="enom-i" aria-hidden="true" />@endif</a></th>
                                <th scope="col">Status</th>
                                <th scope="col"><a href="{{ $sortLink('expiry') }}" @if ($sortIcon('expiry')) aria-current="true" @endif>Expires @if ($sortIcon('expiry'))<x-dynamic-component :component="$sortIcon('expiry')" class="enom-i" aria-hidden="true" />@endif</a></th>
                                <th scope="col">Auto-renew</th>
                                <th scope="col" class="enom-end"><span class="enom-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domains as $domain)
                                @php
                                    $row = $progress[$domain->id] ?? null;
                                    $pending = in_array($domain->status, ['registration_pending', 'transfer_pending'], true);
                                    $renews = !$domain->service?->cancellation;
                                    $payInvoice = !empty($row['awaiting_payment']) && !empty($row['invoice_id']) ? $row['invoice_id'] : null;
                                @endphp
                                <tr>
                                    <td><a class="enom-domain" href="{{ route('enom.domains.show', $domain->id) }}">{{ $domain->domain }}</a></td>
                                    <td>@include('enom::partials.badge', ['status' => $domain->status, 'progress' => $row])</td>
                                    <td class="enom-num" data-label="Expires">@include('enom::partials.date', ['date' => $domain->provider_expires_at, 'fallback' => '—'])</td>
                                    <td data-label="Auto-renew">
                                        @if ($pending || $domain->status !== 'active')<span class="enom-muted">—</span>
                                        @elseif ($renews)On
                                        @else<span class="enom-muted">Off</span>@endif
                                    </td>
                                    <td class="enom-end enom-cell-wide">
                                        @if ($payInvoice)
                                            <a class="enom-btn enom-btn--primary enom-btn--sm" href="{{ route('invoices.show', $payInvoice) }}">Pay invoice</a>
                                        @else
                                            <a class="enom-btn enom-btn--secondary enom-btn--sm" href="{{ route('enom.domains.show', $domain->id) }}" aria-label="Manage {{ $domain->domain }}">Manage</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        @if ($domains->hasPages())<div>{{ $domains->links() }}</div>@endif
    @endif
    <p class="enom-small enom-muted">Questions about domains? <a class="enom-a" href="{{ route('enom.help') }}">Read domain help</a></p>
</div>
</div>
@include('enom::partials.client-script')
@endcomponent

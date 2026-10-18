@component('layouts.app', ['title' => 'Domain prices', 'sidebar' => !$guest, 'description' => 'Registration, renewal and transfer prices for every domain ending we offer.'])
@php $controller = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class; @endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $testEnvironment])
    <div>
        @unless ($guest)<a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>@endunless
        <div class="enom-head">
            <div>
                <h1 class="enom-title">Domain prices</h1>
                <p class="enom-lede">Every ending we offer, with its registration, renewal and transfer price.</p>
            </div>
        </div>
    </div>

    @if (!$rows)
        <div class="enom-notice enom-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain registration isn’t open yet. Check back soon.</div></div>
    @else
        <form method="GET" action="{{ route('enom.domains.search') }}" role="search">
            <div class="enom-searchbar">
                <label for="enom-price-name" class="enom-sr">Domain name</label>
                <input id="enom-price-name" name="name" type="text" placeholder="Search for a name" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required>
                <button type="submit" class="enom-btn enom-btn--primary"><x-ri-search-line class="enom-i" aria-hidden="true" />Search</button>
            </div>
        </form>


        <section class="enom-panel" aria-labelledby="enom-prices-heading">
            <h2 id="enom-prices-heading" class="enom-sr">Prices by ending</h2>
            <div class="enom-tablewrap">
                <table class="enom-tbl enom-stackable enom-pricelist">
                    <thead>
                        <tr>
                            <th scope="col">Ending</th>
                            <th scope="col" class="enom-end">Register, first year</th>
                            <th scope="col" class="enom-end">Renew, per year</th>
                            <th scope="col" class="enom-end">Transfer, adds 1 year</th>
                            <th scope="col" class="enom-end">Register for</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row" class="enom-domain enom-cell-wide">.{{ $row['tld'] }}</th>
                                <td class="enom-end enom-num" data-label="Register">{{ $row['register'] ?? '—' }}</td>
                                <td class="enom-end enom-num" data-label="Renew">{{ $row['renew'] ? $row['renew'].'/yr' : '—' }}</td>
                                <td class="enom-end enom-num" data-label="Transfer">{{ $row['transfer'] ?? '—' }}</td>
                                <td class="enom-end enom-nowrap" data-label="Register for">{{ $controller::termRange(range(max(1, $row['min_years']), max(max(1, $row['min_years']), $row['max_years']))) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <p class="enom-small enom-muted">Prices plus tax where applicable. Each registration term has its own total. Choose a term at checkout to see its price.</p>
    @endif
</div>
</div>
@include('enom::partials.client-script')
@endcomponent

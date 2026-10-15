<x-filament-panels::page>
    {{-- Layout helpers only. Colours, type, buttons, links and sections are native Filament. --}}
    <style>
        .enom-hub { display: grid; grid-template-columns: minmax(0, 1fr); min-width: 0; gap: 1.5rem; }
        .enom-hub-stack { display: grid; gap: 0.75rem; max-width: 72ch; }
        .enom-hub-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
        .enom-hub .enom-hub-target { min-height: 2.75rem; display: inline-flex; align-items: center; }
        .enom-hub-scroll { overflow-x: auto; }
        .enom-hub-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .enom-hub-table caption { text-align: start; padding-bottom: 0.5rem; }
        .enom-hub-table th, .enom-hub-table td { text-align: start; vertical-align: top; padding: 0.5rem 0.75rem 0.5rem 0;
            border-bottom: 1px solid color-mix(in srgb, currentColor 15%, transparent); }
        .enom-hub-table th { font-weight: 600; }
        .enom-hub-table td:first-child { overflow-wrap: anywhere; }
        .enom-hub-servers { display: grid; gap: 0.75rem; }
        .enom-hub-server { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 1rem;
            padding-bottom: 0.75rem; border-bottom: 1px solid color-mix(in srgb, currentColor 15%, transparent); }
        .enom-hub-server-name { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; font-weight: 600; overflow-wrap: anywhere; }
    </style>

    <div class="enom-hub">
        <x-filament::section>
            <x-slot name="heading">Manage Enom domains</x-slot>
            <x-slot name="description">Review requests, check registrar data and set renewal billing for each domain.</x-slot>
            <div class="enom-hub-stack">
                <p>Enom domain records in Paymenter. Domains at other registrars and domain names used only by hosting are not included.</p>
                <div class="enom-hub-actions">
                    <x-filament::button tag="a" :href="$links['admin']" class="enom-hub-target">Open Enom administration</x-filament::button>
                    <x-filament::button tag="a" :href="$links['help']" color="gray" class="enom-hub-target">Staff help</x-filament::button>
                    <x-filament::button tag="a" :href="$links['setup']" color="gray" class="enom-hub-target">Setup guide</x-filament::button>
                </div>
                <p>These pages open in the client area layout. Enom administration has a Back to administration link to the dashboard.</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">What customers can order</x-slot>
            <div class="enom-hub-stack">
                <p>Customers order on the Domains page in the client area. This release uses the Enom sandbox and offers .com names only, one year at a time, priced in USD. Premium names, other endings and international names are not available.</p>
                <p>Registration and transfer prices are on the domain product, under its Server tab. The yearly plan price on the Pricing tab is the renewal price. All of these are before tax.</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Enom server and product</x-slot>
            <x-slot name="description">Enom is a server integration, so its settings are under Extensions, then Servers. The Extensions page lists other kinds of extension and does not show Enom.</x-slot>
            <div class="enom-hub-stack" style="max-width: none;">
                @if ($servers)
                    <div class="enom-hub-servers">
                        @foreach ($servers as $server)
                            <div class="enom-hub-server">
                                <span class="enom-hub-server-name">
                                    {{ $server['name'] }}
                                    <x-filament::badge :color="$server['enabled'] ? 'success' : 'gray'">{{ $server['enabled'] ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                                </span>
                                @if ($server['edit_url'])
                                    <x-filament::link :href="$server['edit_url']" class="enom-hub-target">Edit {{ $server['name'] }}</x-filament::link>
                                @elseif (!$canEditServers)
                                    <span>Your role cannot edit servers.</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p>There is no Enom server yet. Create one under Extensions, then Servers, and follow Setup guide.</p>
                @endif

                @if ($products)
                    <div class="enom-hub-scroll">
                        <table class="enom-hub-table">
                            <caption>Products on Enom servers</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">Shop</th>
                                    <th scope="col">Renewal price</th>
                                    <th scope="col"><span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap;">Edit</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $product)
                                    <tr>
                                        <td>{{ $product['name'] }}@if (count($servers) > 1)<br>{{ $product['server'] }}@endif</td>
                                        <td>{{ $product['hidden'] ? 'Hidden' : 'Visible. Domain quotes need this product hidden.' }}</td>
                                        <td>
                                            @if (!$product['price'])
                                                No yearly USD price. Customers cannot get a quote until the yearly plan has one.
                                            @elseif ($product['price']['ready'])
                                                USD {{ $product['price']['amount'] }} per year
                                            @else
                                                USD {{ $product['price']['amount'] }} per year. Customers cannot get a quote while this price is zero or has a setup fee.
                                            @endif
                                        </td>
                                        <td>
                                            @if ($product['edit_url'])
                                                <x-filament::link :href="$product['edit_url']" class="enom-hub-target">Edit {{ $product['name'] }}</x-filament::link>
                                            @elseif (!$canEditProducts)
                                                Your role cannot edit products.
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif ($servers)
                    <p>No product uses an Enom server yet. Setup guide lists the product settings customers need before they can order.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Newest domains</x-slot>
            <x-slot name="description">
                @if ($domains)
                    {{ count($domains) < $domainCount ? 'The '.count($domains).' newest of '.$domainCount.' domains.' : 'All '.$domainCount.' '.($domainCount === 1 ? 'domain' : 'domains').'.' }} Open a domain for its registrar data and renewal billing.
                @else
                    Domains appear here after a customer places an order.
                @endif
            </x-slot>
            @if ($domains)
                <div class="enom-hub-scroll">
                    <table class="enom-hub-table">
                        <caption style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap;">Newest Enom domains</caption>
                        <thead>
                            <tr>
                                <th scope="col">Domain</th>
                                <th scope="col">Status</th>
                                <th scope="col">Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domains as $row)
                                <tr>
                                    <td><x-filament::link :href="$row['url']" class="enom-hub-target">{{ $row['domain'] }}</x-filament::link></td>
                                    <td>{{ $row['status'] }}</td>
                                    <td style="white-space: nowrap;">{{ $row['expires'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No domains yet.</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

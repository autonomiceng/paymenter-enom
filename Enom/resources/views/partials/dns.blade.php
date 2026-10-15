@php
    $dnsPaused = !empty($snapshot['writes_blocked']);
    $typeLabels = ['A' => 'A, IPv4 address', 'AAAA' => 'AAAA, IPv6 address', 'CNAME' => 'CNAME, alias', 'MX' => 'MX, mail server',
        'MXE' => 'MXE, mail server IP', 'TXT' => 'TXT, text', 'URL' => 'URL, redirect', 'FRAME' => 'FRAME, masked redirect'];
@endphp
@foreach (['hosts' => 'DNS records', 'srv' => 'SRV records'] as $kind => $label)
<details class="bg-background-secondary border border-neutral p-5 rounded-lg" @if (old('_enom_form') === 'domain:'.$domainId.':'.$kind) open @endif>
    <summary class="text-xl font-semibold">{{ $label }}</summary>
    <div class="mt-4 space-y-4">
        <p class="text-sm">Saving replaces every record in this list. Rows you remove or leave out are deleted. These records work only while the domain uses Enom nameservers.</p>
        @if ($available($kind))
            @php
                $retryDns = old('_enom_form') === 'domain:'.$domainId.':'.$kind;
                $rows = $retryDns ? old('records', []) : [...$cached[$kind], [], [], []];
                $fields = $kind === 'hosts' ? ['name' => 'Host', 'type' => 'Type', 'address' => 'Address', 'mx_pref' => 'Mail priority'] : ['service' => 'Service', 'protocol' => 'Protocol', 'priority' => 'Priority', 'weight' => 'Weight', 'port' => 'Port', 'target' => 'Target'];
            @endphp
            <form method="POST" action="{{ route('enom.domains.manage', [$domainId, $kind]) }}" class="space-y-4">
                @include('enom::partials.form-key', ['action' => $kind])
                <input type="hidden" name="preimage_digest" value="{{ $retryDns ? old('preimage_digest', '') : $snapshot[$kind.'_digest'] }}">
                <div class="overflow-x-auto">
                    <table class="enom-table w-full text-sm">
                        <caption class="sr-only">{{ $label }}. Use the blank rows to add records.</caption>
                        <thead><tr>@foreach ($fields as $field => $fieldLabel)<th scope="col" class="text-left p-2">{{ $fieldLabel }}</th>@endforeach<th scope="col" class="text-left p-2">Remove</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $index => $record)
                            <tr x-data="{recordType: @js($record['type'] ?? '')}">
                                @foreach ($fields as $field => $fieldLabel)
                                    <td class="p-1">
                                        @if ($field === 'type')
                                            <select x-model="recordType" class="w-full bg-background border border-neutral rounded-md p-2 focus:border-secondary" name="records[{{ $index }}][type]" aria-label="Record {{ $index + 1 }} type">
                                                <option value="">Choose type</option>
                                                @foreach (['A', 'AAAA', 'CNAME', 'MX', 'MXE', 'TXT', 'URL', 'FRAME'] as $type)
                                                    <option value="{{ $type }}" @selected(($record['type'] ?? '') === $type)>{{ $typeLabels[$type] }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($field === 'protocol')
                                            @php
                                                $protocolValue = strtolower($record['protocol'] ?? '');
                                                $protocolError = 'records.'.$index.'.protocol';
                                                $protocolInvalid = $retryDns && $errors->has($protocolError);
                                            @endphp
                                            <select id="srv-{{ $index }}-protocol" class="w-full bg-background border border-neutral rounded-md p-2 focus:border-secondary" name="records[{{ $index }}][protocol]" aria-label="Record {{ $index + 1 }} protocol" @if ($protocolInvalid) aria-invalid="true" aria-describedby="srv-{{ $index }}-protocol-error" @endif>
                                                <option value="" @selected($protocolValue === '')>Choose protocol</option>
                                                @if ($protocolValue !== '' && !in_array($protocolValue, ['tcp', 'udp'], true))<option value="{{ $protocolValue }}" selected disabled>Choose TCP or UDP (entered: {{ $protocolValue }})</option>@endif
                                                <option value="tcp" @selected($protocolValue === 'tcp')>TCP</option>
                                                <option value="udp" @selected($protocolValue === 'udp')>UDP</option>
                                            </select>
                                            @if ($protocolInvalid)<p id="srv-{{ $index }}-protocol-error" class="text-red-500 text-xs">{{ $errors->first($protocolError) }}</p>@endif
                                        @elseif ($field === 'mx_pref')
                                            <input x-show="recordType === 'MX'" :disabled="recordType !== 'MX'" class="w-full bg-background border border-neutral rounded-md p-2 focus:border-secondary" type="number" min="0" max="65535" name="records[{{ $index }}][mx_pref]" aria-label="Record {{ $index + 1 }} mail priority" value="{{ ($record['type'] ?? '') === 'MX' ? ($record['mx_pref'] ?? '') : '' }}" placeholder="10">
                                            <span x-show="recordType !== 'MX'" class="text-xs">MX only</span>
                                        @else
                                            <input class="w-full bg-background border border-neutral rounded-md p-2 focus:border-secondary" name="records[{{ $index }}][{{ $field }}]" aria-label="Record {{ $index + 1 }} {{ strtolower($fieldLabel) }}" value="{{ $record[$field] ?? ($field === 'name' ? ($record['host'] ?? '') : '') }}" maxlength="{{ $field === 'address' ? 2048 : 253 }}">
                                        @endif
                                    </td>
                                @endforeach
                                <td class="p-1"><label class="enom-check text-sm"><input type="checkbox" name="records[{{ $index }}][_delete]" value="1" @checked(($record['_delete'] ?? '') === '1') aria-label="Remove record {{ $index + 1 }}"><span aria-hidden="true">Remove</span></label>@if ($kind === 'srv' && isset($record['host_id']))<input type="hidden" name="records[{{ $index }}][host_id]" value="{{ $record['host_id'] }}">@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($kind === 'hosts')
                    <p class="text-sm">Use the blank rows to add records, and leave unused rows empty. Use @ for the domain itself. CNAME and MX targets need a final dot, as in the examples below. Mail priority applies only to MX records. TXT values are saved exactly as you enter them, including spaces.</p>
                @else
                    <p class="text-sm">Use the blank rows to add records, and leave unused rows empty. Enter the service name without the leading underscore, such as sip. The target needs a final dot, as in the example below.</p>
                @endif
                <p class="text-sm">The list can hold up to 50 records. At least one record must stay. TTL cannot be changed here.</p>
                @include('enom::partials.confirm', ['confirmation' => 'Replace the whole '.$label.' list with the records above.'])
                <div class="max-w-[300px]"><x-button.primary type="submit">Save {{ $label }}</x-button.primary></div>
            </form>
            @component('enom::partials.disclosure', ['summary' => $kind === 'hosts' ? 'DNS record examples' : 'SRV record example'])
                @if ($kind === 'hosts')
                    @include('enom::partials.code-example', ['caption' => 'Common DNS records, one per row in the table.', 'code' => "Host: @     Type: A      Address: 192.0.2.10\nHost: www   Type: CNAME  Address: example.com.\nHost: @     Type: MX     Address: mail.example.com.   Mail priority: 10\nHost: @     Type: TXT    Address: example-verification=placeholder"])
                @else
                    @include('enom::partials.code-example', ['caption' => 'One SRV record for a SIP service.', 'code' => "Service: sip  Protocol: TCP  Priority: 10  Weight: 5  Port: 5060  Target: sip.example.com."])
                @endif
            @endcomponent
        @else
            <p class="text-sm">{{ $dnsPaused ? 'Changes are paused while a registrar request is checked.' : $label.' are not available right now. They need Enom nameservers. Refresh status to check again.' }}</p>
        @endif
    </div>
</details>
@endforeach
<details class="bg-background-secondary border border-neutral p-5 rounded-lg" @if (old('_enom_form') === 'domain:'.$domainId.':dnssec_add') open @endif>
    <summary class="text-xl font-semibold">DNSSEC</summary>
    <div class="mt-4 space-y-4">
        <p class="text-sm">A DS record publishes your DNS provider's signing key at the registry. It does not sign the zone. Copy the values from your DNS provider. Sandbox requests do not create a working public DNSSEC chain.</p>
        @forelse ($cached['dnssec'] as $ds)
            <div class="border border-neutral p-4 rounded-lg space-y-2">
                <p class="text-sm break-all">Key tag {{ $ds['key_tag'] ?? 'not recorded' }}, algorithm {{ $ds['algorithm'] ?? 'not recorded' }}, digest type {{ $ds['digest_type'] ?? 'not recorded' }}</p>
                <p class="text-sm break-all">{{ $ds['digest'] ?? '' }}</p>
                @if ($available('dnssec_delete'))
                    <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'dnssec_delete']) }}" class="space-y-2">
                        @include('enom::partials.form-key', ['action' => 'dnssec_delete'])
                        @foreach (['algorithm', 'digest_type', 'digest', 'key_tag'] as $field)<input type="hidden" name="ds[{{ $field }}]" value="{{ $ds[$field] ?? '' }}">@endforeach
                        @include('enom::partials.confirm', ['confirmation' => 'Remove this exact DS record from the registry.'])
                        <div class="max-w-[300px]"><x-button.secondary type="submit">Remove DS record</x-button.secondary></div>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm">No DS records on record.</p>
        @endforelse
        @if ($available('dnssec_add'))
            <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'dnssec_add']) }}" class="space-y-4 border-t border-neutral pt-4">
                @include('enom::partials.form-key', ['action' => 'dnssec_add'])
                {{-- One grid cell per control, so feedback added beside a control never shifts the others. --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                    <div class="min-w-0"><x-form.input name="ds[key_tag]" :value="old('_enom_form') === 'domain:'.$domainId.':dnssec_add' ? old('ds.key_tag', '') : ''" label="Key tag" type="number" min="0" max="65535" required /></div>
                    <div class="min-w-0"><x-form.select name="ds[algorithm]" label="Algorithm" required>@foreach ([8 => 'RSA/SHA-256', 10 => 'RSA/SHA-512', 13 => 'ECDSA P-256/SHA-256', 14 => 'ECDSA P-384/SHA-384', 3 => 'DSA/SHA-1', 5 => 'RSA/SHA-1', 7 => 'RSA/SHA-1 NSEC3', 12 => 'GOST'] as $number => $algorithmLabel)<option value="{{ $number }}" @selected(old('_enom_form') === 'domain:'.$domainId.':dnssec_add' && old('ds.algorithm') == $number)>{{ $number }}: {{ $algorithmLabel }}</option>@endforeach</x-form.select></div>
                    <div class="min-w-0"><x-form.select name="ds[digest_type]" label="Digest type" required><option value="2" @selected(old('_enom_form') !== 'domain:'.$domainId.':dnssec_add' || old('ds.digest_type') == 2)>2: SHA-256</option><option value="1" @selected(old('_enom_form') === 'domain:'.$domainId.':dnssec_add' && old('ds.digest_type') == 1)>1: SHA-1</option></x-form.select></div>
                    <div class="min-w-0"><x-form.input name="ds[digest]" :value="old('_enom_form') === 'domain:'.$domainId.':dnssec_add' ? old('ds.digest', '') : ''" label="Hexadecimal digest" required maxlength="64" autocomplete="off" /></div>
                </div>
                <p class="text-sm">A SHA-256 digest is 64 hexadecimal characters. A SHA-1 digest is 40.</p>
                @include('enom::partials.confirm', ['confirmation' => 'Publish this DS record. It matches the signing key from my DNS provider.'])
                <div class="max-w-[300px]"><x-button.primary type="submit">Add DS record</x-button.primary></div>
            </form>
        @else
            <p class="text-sm">{{ $dnsPaused ? 'Changes are paused while a registrar request is checked.' : 'DNSSEC changes are not available for this domain.' }}</p>
        @endif
    </div>
</details>

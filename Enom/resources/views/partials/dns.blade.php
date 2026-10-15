{{-- Enom DNS tools. Shown only when the server's "Show Enom DNS tools" setting is on. Saving replaces the whole list (registrar API contract). --}}
@php
    $typeLabels = ['A' => 'A (IPv4)', 'AAAA' => 'AAAA (IPv6)', 'CNAME' => 'CNAME (alias)', 'MX' => 'MX (mail)', 'MXE' => 'MXE (mail IP)', 'TXT' => 'TXT (text)', 'URL' => 'URL (redirect)', 'FRAME' => 'FRAME (masked redirect)'];
    $dnsRetry = fn (string $kind) => old('_enom_form') === 'domain:'.$domainId.':'.$kind;
@endphp
@foreach (['hosts' => ['DNS records', 'Used only while the domain uses the registrar’s nameservers. Use @ for the domain itself. Alias and mail targets end with a dot.'],
           'srv' => ['SRV records', 'Enter the service without the leading underscore, such as sip. The target ends with a dot.']] as $kind => [$label, $help])
    <section class="enom-section" aria-labelledby="enom-{{ $kind }}-heading">
        <div class="enom-section__head"><div><h2 id="enom-{{ $kind }}-heading" class="enom-h2">{{ $label }}</h2><p>{{ $help }}</p></div></div>
        @if ($can($kind))
            @php
                $retry = $dnsRetry($kind);
                $rows = $retry ? old('records', []) : [...$cached[$kind], [], []];
                $fields = $kind === 'hosts' ? ['name' => 'Host', 'type' => 'Type', 'address' => 'Value', 'mx_pref' => 'Priority'] : ['service' => 'Service', 'protocol' => 'Protocol', 'priority' => 'Priority', 'weight' => 'Weight', 'port' => 'Port', 'target' => 'Target'];
            @endphp
            <form method="POST" action="{{ route('enom.domains.manage', [$domainId, $kind]) }}">
                @include('enom::partials.form-key', ['action' => $kind])
                <input type="hidden" name="preimage_digest" value="{{ $retry ? old('preimage_digest', '') : $snapshot[$kind.'_digest'] }}">
                <div class="enom-tablewrap">
                    <table class="enom-tbl" style="min-width:40rem">
                        <caption class="enom-sr">{{ $label }}. Blank rows add records.</caption>
                        <thead><tr>@foreach ($fields as $fieldLabel)<th scope="col">{{ $fieldLabel }}</th>@endforeach<th scope="col">Delete</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $index => $record)
                            <tr x-data="{recordType: @js($record['type'] ?? '')}">
                                @foreach ($fields as $field => $fieldLabel)
                                    <td style="padding:.375rem .5rem">
                                        @if ($field === 'type')
                                            <select x-model="recordType" class="enom-input" name="records[{{ $index }}][type]" aria-label="Record {{ $index + 1 }} type">
                                                <option value="">Type</option>
                                                @foreach ($typeLabels as $type => $typeLabel)<option value="{{ $type }}" @selected(($record['type'] ?? '') === $type)>{{ $typeLabel }}</option>@endforeach
                                            </select>
                                        @elseif ($field === 'protocol')
                                            @php $protocol = strtolower($record['protocol'] ?? ''); @endphp
                                            <select class="enom-input" name="records[{{ $index }}][protocol]" aria-label="Record {{ $index + 1 }} protocol" @if ($retry && $errors->has('records.'.$index.'.protocol')) aria-invalid="true" @endif>
                                                <option value="" @selected($protocol === '')>Protocol</option>
                                                <option value="tcp" @selected($protocol === 'tcp')>TCP</option>
                                                <option value="udp" @selected($protocol === 'udp')>UDP</option>
                                            </select>
                                        @elseif ($field === 'mx_pref')
                                            <input x-show="recordType === 'MX'" :disabled="recordType !== 'MX'" class="enom-input" type="number" min="0" max="65535" name="records[{{ $index }}][mx_pref]" aria-label="Record {{ $index + 1 }} mail priority" value="{{ ($record['type'] ?? '') === 'MX' ? ($record['mx_pref'] ?? '') : '' }}" placeholder="10" style="width:5.5rem">
                                        @else
                                            <input class="enom-input" name="records[{{ $index }}][{{ $field }}]" aria-label="Record {{ $index + 1 }} {{ strtolower($fieldLabel) }}" value="{{ $record[$field] ?? ($field === 'name' ? ($record['host'] ?? '') : '') }}" maxlength="{{ $field === 'address' ? 2048 : 253 }}" spellcheck="false">
                                        @endif
                                    </td>
                                @endforeach
                                <td style="padding:.375rem .5rem;text-align:center">
                                    <input type="checkbox" name="records[{{ $index }}][_delete]" value="1" @checked(($record['_delete'] ?? '') === '1') aria-label="Delete record {{ $index + 1 }}" style="width:1.125rem;height:1.125rem;accent-color:var(--e-accent)">
                                    @if ($kind === 'srv' && isset($record['host_id']))<input type="hidden" name="records[{{ $index }}][host_id]" value="{{ $record['host_id'] }}">@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="enom-row" style="margin-top:1rem">
                    <button type="submit" class="enom-btn enom-btn--primary">Save {{ strtolower($label) }}</button>
                    <span class="enom-small enom-muted">Up to 50 records. Fill a blank row to add one.</span>
                </div>
            </form>
        @else
            <p class="enom-small enom-muted">{{ $paused ? 'Editing is paused.' : $label.' are available only while the domain uses the registrar’s nameservers.' }}</p>
        @endif
    </section>
@endforeach
<section class="enom-section" aria-labelledby="enom-dnssec-heading">
    <div class="enom-section__head"><div><h2 id="enom-dnssec-heading" class="enom-h2">DNSSEC</h2><p>Add the DS record your DNS provider gives you.</p></div></div>
    <div class="enom-stack">
        @forelse ($cached['dnssec'] as $ds)
            <div class="enom-row" style="justify-content:space-between;border:1px solid var(--e-line);border-radius:.5rem;padding:.75rem 1rem">
                <div class="enom-small" style="min-width:0">
                    <strong>Key tag {{ $ds['key_tag'] ?? '—' }}</strong> · algorithm {{ $ds['algorithm'] ?? '—' }} · digest type {{ $ds['digest_type'] ?? '—' }}
                    <div class="enom-muted" style="word-break:break-all">{{ $ds['digest'] ?? '' }}</div>
                </div>
                @if ($can('dnssec_delete'))
                    <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'dnssec_delete']) }}"
                        data-confirm-title="Remove this DS record?" data-confirm-ok="Remove" data-confirm-tone="danger" data-confirm-body="If DNSSEC is still on at your DNS provider, removing the wrong record can make the domain unreachable.">
                        @include('enom::partials.form-key', ['action' => 'dnssec_delete'])
                        @foreach (['algorithm', 'digest_type', 'digest', 'key_tag'] as $field)<input type="hidden" name="ds[{{ $field }}]" value="{{ $ds[$field] ?? '' }}">@endforeach
                        <button type="submit" class="enom-btn enom-btn--secondary enom-btn--sm">Remove</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="enom-small enom-muted">No DS records.</p>
        @endforelse
        @if ($can('dnssec_add'))
            @php $dsRetry = $dnsRetry('dnssec_add'); @endphp
            <details class="enom-more" @if ($dsRetry) open @endif>
                <summary>Add a DS record</summary>
                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'dnssec_add']) }}" data-enom-form style="margin-top:.75rem">
                    @include('enom::partials.form-key', ['action' => 'dnssec_add'])
                    <div class="enom-fields">
                        <div data-field><label class="enom-label" for="ds-key-tag">Key tag</label><input id="ds-key-tag" class="enom-input" name="ds[key_tag]" type="number" min="0" max="65535" required value="{{ $dsRetry ? old('ds.key_tag') : '' }}"></div>
                        <div data-field><label class="enom-label" for="ds-algorithm">Algorithm</label>
                            <select id="ds-algorithm" class="enom-input" name="ds[algorithm]" required>
                                @foreach ([13 => 'ECDSA P-256/SHA-256', 8 => 'RSA/SHA-256', 14 => 'ECDSA P-384/SHA-384', 10 => 'RSA/SHA-512', 7 => 'RSA/SHA-1 NSEC3', 5 => 'RSA/SHA-1', 3 => 'DSA/SHA-1', 12 => 'GOST'] as $number => $algorithmLabel)
                                    <option value="{{ $number }}" @selected($dsRetry && old('ds.algorithm') == $number)>{{ $number }} · {{ $algorithmLabel }}</option>
                                @endforeach
                            </select></div>
                        <div data-field><label class="enom-label" for="ds-digest-type">Digest type</label>
                            <select id="ds-digest-type" class="enom-input" name="ds[digest_type]" required>
                                <option value="2" @selected(!$dsRetry || old('ds.digest_type') == 2)>2 · SHA-256</option>
                                <option value="1" @selected($dsRetry && old('ds.digest_type') == 1)>1 · SHA-1</option>
                            </select></div>
                        <div data-field><label class="enom-label" for="ds-digest">Digest</label><input id="ds-digest" class="enom-input" name="ds[digest]" required maxlength="64" autocomplete="off" spellcheck="false" value="{{ $dsRetry ? old('ds.digest') : '' }}"></div>
                    </div>
                    <div style="margin-top:1rem"><button type="submit" class="enom-btn enom-btn--primary">Add DS record</button></div>
                </form>
            </details>
        @endif
    </div>
</section>

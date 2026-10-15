{{-- Child nameservers (glue records) on the Nameservers tab. Uses the domain page's $glue, $glueErrors, $can, $retryFor, $current. --}}
@php
    $canGlue = $can('glue');
    $inUse = fn (string $host) => in_array(rtrim(strtolower($host), '.'), array_map(fn ($h) => rtrim(strtolower($h), '.'), $current), true);
    $addRetry = $retryFor('glue_add');
    $oldPrefix = $addRetry && is_string(old('host')) ? preg_replace('/\.'.preg_quote($name, '/').'\z/i', '', old('host')) : '';
@endphp
<section class="enom-section" aria-labelledby="enom-glue-heading">
    <div class="enom-section__head"><div><h2 id="enom-glue-heading" class="enom-h2">Child nameservers</h2>
        <p>Needed only when your nameservers are named under this domain, like ns1.{{ $name }}. The registry stores each one’s IP address.</p></div></div>
    @if ($glueErrors->isNotEmpty())
        <div class="enom-notice enom-notice--bad" role="alert" style="margin-bottom:1rem"><x-ri-error-warning-fill aria-hidden="true" /><div>@foreach ($glueErrors as $message)<p>{{ $message }}</p>@endforeach</div></div>
    @endif

    @if ($glue->isNotEmpty())
        <ul class="enom-glue">
            @foreach ($glue as $index => $record)
                @php
                    $host = $record['host'];
                    $ip = is_string($record['ip'] ?? null) ? $record['ip'] : '';
                    $rowRetry = ($retryFor('glue_update') || $retryFor('glue_delete')) && old('host') === $host;
                    $hostError = $rowRetry ? $errors->first('host') : null;
                    $ipError = $rowRetry ? $errors->first('ip') : null;
                @endphp
                <li>
                    @if ($canGlue)
                        <details class="enom-glue__item" @if ($rowRetry) open @endif>
                            <summary>
                                <span class="enom-glue__host">{{ $host }}</span>
                                <span class="enom-glue__ip enom-num">{{ $ip ?: 'No IP address' }}</span>
                                <span class="enom-glue__edit" aria-hidden="true"></span>
                                <span class="enom-sr">Edit {{ $host }}</span>
                            </summary>
                            <div class="enom-glue__panel">
                                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'glue_update']) }}" data-enom-form class="enom-glue__form">
                                    @include('enom::partials.form-key', ['action' => 'glue_update'])
                                    <input type="hidden" name="host" value="{{ $host }}">
                                    <div data-field>
                                        <label for="enom-glue-ip-{{ $index }}" class="enom-label">IP address<span class="enom-sr"> for {{ $host }}</span></label>
                                        <input id="enom-glue-ip-{{ $index }}" name="ip" type="text" class="enom-input enom-num" required maxlength="45" spellcheck="false" autocapitalize="none" autocomplete="off"
                                            value="{{ $rowRetry && $retryFor('glue_update') && is_string(old('ip')) ? old('ip') : $ip }}"
                                            @if ($ipError) aria-invalid="true" aria-describedby="enom-glue-ip-{{ $index }}-error" @endif>
                                        @if ($ipError)<p id="enom-glue-ip-{{ $index }}-error" class="enom-error">{{ $ipError }}</p>@endif
                                    </div>
                                    <button type="submit" class="enom-btn enom-btn--primary">Save IP address</button>
                                </form>
                                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'glue_delete']) }}" class="enom-glue__delete"
                                    data-confirm-title="Delete {{ $host }}?" data-confirm-ok="Delete" data-confirm-tone="danger"
                                    data-confirm-body="{{ $inUse($host) ? $host.' is one of this domain’s nameservers. Change the nameservers first, or your website and email may stop working.' : 'Any domain that uses '.$host.' as a nameserver will stop working until its nameservers are changed.' }}">
                                    @include('enom::partials.form-key', ['action' => 'glue_delete'])
                                    <input type="hidden" name="host" value="{{ $host }}">
                                    <button type="submit" class="enom-btn enom-btn--ghost enom-btn--danger-text"><x-ri-delete-bin-line class="enom-i" aria-hidden="true" />Delete</button>
                                </form>
                                @if ($hostError)<p class="enom-error" style="flex-basis:100%">{{ $hostError }}</p>@endif
                            </div>
                        </details>
                    @else
                        <div class="enom-glue__item"><div class="enom-glue__static"><span class="enom-glue__host">{{ $host }}</span><span class="enom-glue__ip enom-num">{{ $ip }}</span></div></div>
                    @endif
                </li>
            @endforeach
        </ul>
    @elseif (!$canGlue)
        <p class="enom-small enom-muted">None.</p>
    @endif

    @if ($canGlue)
        <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'glue_add']) }}" data-enom-form class="enom-glue__new" aria-labelledby="enom-glue-add-heading">
            @include('enom::partials.form-key', ['action' => 'glue_add'])
            <h3 id="enom-glue-add-heading" class="enom-h3">{{ $glue->isEmpty() ? 'Add a child nameserver' : 'Add another' }}</h3>
            <div class="enom-glue__fields">
                <div data-field>
                    <label for="enom-glue-host" class="enom-label">Hostname</label>
                    <div class="enom-affix">
                        <input id="enom-glue-host" name="host" type="text" class="enom-input" required maxlength="63" placeholder="ns1" spellcheck="false" autocapitalize="none" autocomplete="off"
                            pattern="[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?)*" data-pattern-message="Use letters, numbers and hyphens, for example ns1."
                            value="{{ $oldPrefix }}" aria-describedby="enom-glue-suffix{{ $addRetry && $errors->has('host') ? ' enom-glue-host-error' : '' }}" @if ($addRetry && $errors->has('host')) aria-invalid="true" @endif>
                        <span class="enom-affix__text" id="enom-glue-suffix">.{{ $name }}</span>
                    </div>
                    @if ($addRetry && $errors->has('host'))<p id="enom-glue-host-error" class="enom-error">{{ $errors->first('host') }}</p>@endif
                </div>
                <div data-field>
                    <label for="enom-glue-ip" class="enom-label">IP address</label>
                    <input id="enom-glue-ip" name="ip" type="text" class="enom-input enom-num" required maxlength="45" spellcheck="false" autocapitalize="none" autocomplete="off"
                        value="{{ $addRetry && is_string(old('ip')) ? old('ip') : '' }}" aria-describedby="enom-glue-ip-hint{{ $addRetry && $errors->has('ip') ? ' enom-glue-ip-error' : '' }}" @if ($addRetry && $errors->has('ip')) aria-invalid="true" @endif>
                    <p id="enom-glue-ip-hint" class="enom-hint">One public IPv4 or IPv6 address.</p>
                    @if ($addRetry && $errors->has('ip'))<p id="enom-glue-ip-error" class="enom-error">{{ $errors->first('ip') }}</p>@endif
                </div>
                <div class="enom-glue__submit"><button type="submit" class="enom-btn enom-btn--primary">Add child nameserver</button></div>
            </div>
        </form>
    @elseif (!$paused && $glue->isNotEmpty())
        <p class="enom-small enom-muted" style="margin-top:.75rem">Child nameservers can’t be changed right now. Refresh and try again.</p>
    @endif
</section>

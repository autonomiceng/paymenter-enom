{{-- Contact fields. $prefix: input name prefix (contact or roles[admin]); $errorPrefix: dotted error key (contact or roles.admin);
     $values: current values; $useOld: read retained input instead; $required: mark required fields; $idPrefix: unique id prefix. --}}
@php
    $required = $required ?? true;
    $useOld = $useOld ?? false;
    $values = $values ?? [];
    $errorPrefix = $errorPrefix ?? 'contact';
    $idPrefix = $idPrefix ?? 'contact';
    $requiredFields = \Paymenter\Extensions\Servers\Enom\Services\DomainManager::REQUIRED_CONTACT_FIELDS;
    $countries = config('app.countries', []);
    $value = function (string $field) use ($useOld, $values, $errorPrefix) {
        $v = !empty($useOld) ? old($errorPrefix.'.'.$field, '') : ($values[$field] ?? '');
        return is_string($v) ? $v : '';
    };
    $layout = [
        'first_name' => ['First name', 'given-name', 'text', ''], 'last_name' => ['Last name', 'family-name', 'text', ''],
        'organization_name' => ['Organization', 'organization', 'text', 'enom-span'],
        'email_address' => ['Email', 'email', 'email', ''], 'phone' => ['Phone', 'tel', 'tel', ''],
        'address1' => ['Street address', 'address-line1', 'text', 'enom-span'], 'address2' => ['Apartment, suite, etc.', 'address-line2', 'text', 'enom-span'],
        'city' => ['City', 'address-level2', 'text', ''], 'state_province' => ['State or province', 'address-level1', 'text', ''],
        'postal_code' => ['Postal code', 'postal-code', 'text', ''], 'country' => ['Country', 'country', 'select', ''],
    ];
    $extra = ['phone_ext' => ['Phone extension', 'off', 'text'], 'job_title' => ['Job title', 'organization-title', 'text'], 'fax' => ['Fax', 'off', 'tel']];
    $hasExtra = collect(array_keys($extra))->contains(fn ($f) => $value($f) !== '' || $errors->has($errorPrefix.'.'.$f));
    $section = $section ?? null; // autocomplete section token keeps browsers from mixing contacts
@endphp
<div class="enom-fields">
    @foreach ($layout as $field => [$label, $auto, $type, $span])
        @php
            $id = $idPrefix.'-'.$field;
            $key = $errorPrefix.'.'.$field;
            $isRequired = $required && in_array($field, $requiredFields, true);
            $error = $errors->first($key);
            $current = $value($field);
            $described = trim(($field === 'phone' ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
        @endphp
        <div class="{{ $span }}" data-field>
            <label for="{{ $id }}" class="enom-label">{{ $label }}@if (!in_array($field, $requiredFields, true)) <span class="enom-opt">(optional)</span>@endif</label>
            @if ($type === 'select')
                <select id="{{ $id }}" name="{{ $prefix }}[country]" class="enom-input" autocomplete="{{ $section ? $section.' ' : '' }}country" @required($isRequired)
                    @if ($error) aria-invalid="true" @endif @if ($described) aria-describedby="{{ $described }}" @endif>
                    <option value="" @selected($current === '')>Choose a country</option>
                    @foreach ($countries as $code => $countryLabel)
                        @if ($code !== '')<option value="{{ $code }}" @selected(strtoupper($current) === $code)>{{ $countryLabel }}</option>@endif
                    @endforeach
                </select>
            @else
                <input id="{{ $id }}" name="{{ $prefix }}[{{ $field }}]" type="{{ $type }}" value="{{ $current }}" maxlength="255" class="enom-input"
                    autocomplete="{{ $section ? $section.' ' : '' }}{{ $auto }}" @required($isRequired) @if ($field === 'phone') placeholder="+1 206 555 0100" @endif
                    @if ($error) aria-invalid="true" @endif @if ($described) aria-describedby="{{ $described }}" @endif>
            @endif
            @if ($field === 'phone')<p id="{{ $id }}-hint" class="enom-hint">Start with + and your country code.</p>@endif
            @if ($error)<p id="{{ $id }}-error" class="enom-error">{{ $error }}</p>@endif
        </div>
    @endforeach
    <details class="enom-more enom-span" @if ($hasExtra) open @endif>
        <summary>Extension, job title and fax</summary>
        <div class="enom-fields" style="margin-top:.75rem">
            @foreach ($extra as $field => [$label, $auto, $type])
                @php $id = $idPrefix.'-'.$field; $error = $errors->first($errorPrefix.'.'.$field); @endphp
                <div data-field>
                    <label for="{{ $id }}" class="enom-label">{{ $label }} <span class="enom-opt">(optional)</span></label>
                    <input id="{{ $id }}" name="{{ $prefix }}[{{ $field }}]" type="{{ $type }}" value="{{ $value($field) }}" maxlength="255" class="enom-input" autocomplete="{{ $auto }}"
                        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                    @if ($error)<p id="{{ $id }}-error" class="enom-error">{{ $error }}</p>@endif
                </div>
            @endforeach
        </div>
    </details>
</div>

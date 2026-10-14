@php
    $labels = ['first_name' => 'First name', 'last_name' => 'Last name', 'organization_name' => 'Organization', 'address1' => 'Street address', 'address2' => 'Address line 2', 'city' => 'City', 'state_province' => 'State or province', 'postal_code' => 'Postal code', 'country' => 'Country', 'email_address' => 'Email address', 'phone' => 'Phone', 'phone_ext' => 'Phone extension', 'job_title' => 'Job title', 'fax' => 'Fax'];
    $requiredFields = \Paymenter\Extensions\Servers\Enom\Services\DomainManager::REQUIRED_CONTACT_FIELDS;
    $retry = old('_enom_form') === ($contactForm ?? '') && (!isset($contactType) || old('contact_type') === $contactType);
    $contactValues = $retry ? old('contact', []) : $contact;
    $countries = config('app.countries', []);
@endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach ($labels as $field => $label)
        @php
            $fieldId = ($contactId ?? 'contact').'-'.$field;
            $errorKey = 'contact.'.$field;
            $value = $contactValues[$field] ?? '';
            $hasError = $retry && $errors->has($errorKey);
        @endphp
        <div class="flex flex-col w-full">
            <label for="{{ $fieldId }}" class="mb-1 text-sm">{{ $label }} @if (in_array($field, $requiredFields))<span class="text-red-500">*</span>@endif</label>
            @if ($field === 'country')
                <select id="{{ $fieldId }}" name="contact[country]" data-enom-contact="country" required autocomplete="country" class="block w-full bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5" @if ($hasError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>
                    <option value="" disabled @selected($value === '')>Choose a country</option>
                    @if ($value !== '' && !array_key_exists(strtoupper($value), $countries))<option value="{{ $value }}" data-enom-unlisted selected>Choose a country (entered: {{ $value }})</option>@endif
                    @foreach ($countries as $code => $countryLabel)
                        @if ($code !== '')
                            <option value="{{ $code }}" @selected(strtoupper($value) === $code)>{{ $countryLabel }}</option>
                        @endif
                    @endforeach
                </select>
            @else
                <input id="{{ $fieldId }}" name="contact[{{ $field }}]" data-enom-contact="{{ $field }}" value="{{ $value }}" type="{{ $field === 'email_address' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" maxlength="255" @required(in_array($field, $requiredFields)) class="block w-full bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5" placeholder="{{ $field === 'phone' ? '+1 (555) 234-5678' : $label }}" autocomplete="off" @if ($hasError) aria-invalid="true" @endif @if ($field === 'phone' || $hasError) aria-describedby="{{ $field === 'phone' ? $fieldId.'-hint ' : '' }}{{ $hasError ? $fieldId.'-error' : '' }}" @endif>
            @endif
            @if ($field === 'phone')<p id="{{ $fieldId }}-hint" class="text-xs mt-1">Include a dialing code separated from the number: +1.5552345678 or +44 20 7946 0123. For US/Canada, a ten-digit number is also accepted when its area code and exchange start from 2 to 9. Put extensions in Phone extension.</p>@endif
            <p id="{{ $fieldId }}-client-error" data-enom-client-error class="text-red-500 text-xs mt-1" hidden></p>
            @if ($hasError)<p id="{{ $fieldId }}-error" data-enom-server-error class="text-red-500 text-xs mt-1">{{ $errors->first($errorKey) }}</p>@endif
        </div>
    @endforeach
</div>

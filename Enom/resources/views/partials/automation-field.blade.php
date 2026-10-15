@php
    $fieldId = 'enom-admin-'.$domain['id'].'-'.$formAction.'-'.$field;
    $fieldInvalid = old('_enom_form') === $formContext && $errors->has($field);
    $fieldValue = old('_enom_form') === $formContext ? old($field, $value ?? '') : ($value ?? '');
    $fieldHint = $hint ?? null;
    $describedBy = trim(($fieldHint ? $fieldId.'-hint ' : '').($fieldInvalid ? $fieldId.'-error' : ''));
    $controlClass = 'block w-full text-sm bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5 focus:border-secondary';
@endphp
<fieldset class="flex flex-col w-full">
    <label for="{{ $fieldId }}" class="mb-1 text-sm">{{ $label }}</label>
    @if ($fieldHint)<p id="{{ $fieldId }}-hint" class="text-xs mb-1">{{ $fieldHint }}</p>@endif
    @if (isset($options))
        <select name="{{ $field }}" id="{{ $fieldId }}" @if ($fieldInvalid) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif class="{{ $controlClass }}">
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $fieldValue === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($field === 'reason')
        <textarea name="{{ $field }}" id="{{ $fieldId }}" required minlength="10" maxlength="500" rows="3" @if ($fieldInvalid) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif class="{{ $controlClass }}">{{ $fieldValue }}</textarea>
    @else
        <input name="{{ $field }}" id="{{ $fieldId }}" type="{{ $type ?? 'text' }}" value="{{ $fieldValue }}" required @if (isset($min)) min="{{ $min }}" @endif @if (isset($max)) max="{{ $max }}" @endif @if (isset($step)) step="{{ $step }}" @endif @if (isset($pattern)) pattern="{{ $pattern }}" @endif @if (isset($inputmode)) inputmode="{{ $inputmode }}" @endif @if ($fieldInvalid) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif class="{{ $controlClass }}">
    @endif
    <p data-enom-client-error data-enom-client-for="{{ $field }}" class="text-red-500 text-xs mt-1" hidden></p>
    @if ($fieldInvalid)<p id="{{ $fieldId }}-error" data-enom-server-error class="text-red-500 text-xs">{{ $errors->first($field) }}</p>@endif
</fieldset>

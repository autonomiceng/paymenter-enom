{{-- Registry details an ending asks for (for example .us nexus). $fields: normalized schema; $tld: ending; $useOld: retained input.
     A child field shows only when its parent's chosen option requires it. Without script every field shows, children marked "if applicable". --}}
@php
    $useOld = $useOld ?? false;
    $byParent = collect($fields)->groupBy(fn ($field) => $field['parent'] ?? '');
    $ordered = [];
    $place = function ($field) use (&$place, &$ordered, $byParent) {
        if (isset($ordered[$field['name']])) { return; }
        $ordered[$field['name']] = $field;
        foreach ($byParent->get($field['name'], []) as $child) { $place($child); }
    };
    foreach ($byParent->get('', []) as $field) { $place($field); }
    foreach ($fields as $field) { $place($field); } // children whose parent is missing
    $value = fn (string $name) => $useOld ? (is_string($v = old('registry.'.$name)) ? $v : '') : '';
@endphp
<section class="enom-section" aria-labelledby="enom-registry-heading" data-enom-registry>
    <div class="enom-section__head"><div><h2 id="enom-registry-heading" class="enom-h2">Registry details</h2><p>The .{{ $tld }} registry needs these before it registers the domain.</p></div></div>
    <div class="enom-stack">
        @foreach ($ordered as $field)
            @php
                $name = $field['name'];
                $id = 'enom-registry-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
                $error = $errors->first('registry.'.$name);
                $child = $field['parent'] !== null && isset($ordered[$field['parent']]);
                $current = $value($name);
            @endphp
            <div data-field data-registry-field="{{ $name }}" @if ($child) data-registry-parent="{{ $field['parent'] }}" @endif style="max-width:30rem">
                <label for="{{ $id }}" class="enom-label">{{ $field['label'] }}@if ($child) <span class="enom-opt" data-nojs-only>(if applicable)</span>@elseif (!$field['required']) <span class="enom-opt">(optional)</span>@endif</label>
                @if ($field['text'])
                    <input id="{{ $id }}" name="registry[{{ $name }}]" type="text" class="enom-input" maxlength="255" value="{{ $current }}" autocomplete="off"
                        @if ($field['required'] && !$child) required @elseif ($field['required']) data-required @endif
                        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                @else
                    <select id="{{ $id }}" name="registry[{{ $name }}]" class="enom-input"
                        @if ($field['required'] && !$child) required @elseif ($field['required']) data-required @endif
                        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                        <option value="" @selected($current === '')>Choose…</option>
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option['value'] }}" @selected($current === $option['value']) @if ($option['requires']) data-requires="{{ implode(' ', $option['requires']) }}" @endif>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($error)<p id="{{ $id }}-error" class="enom-error">{{ $error }}</p>@endif
            </div>
        @endforeach
    </div>
</section>

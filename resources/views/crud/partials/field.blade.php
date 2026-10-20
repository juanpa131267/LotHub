{{--
    Un campo del formulario genérico. $field viene de CrudController::fields().
    Claves: name, label, type, required, width, options, value, placeholder,
            help, rows, step, min, max, maxlength, uppercase, checkbox_label, text, accept, max_bytes.
--}}
@php
    $type = $field['type'] ?? 'text';
    $name = $field['name'] ?? null;
    $width = 'w-'.($field['width'] ?? 'full');
    $required = $field['required'] ?? false;
    $value = $name ? old($name, $field['value'] ?? null) : null;
    $invalid = $name && $errors->has($name) ? 'is-invalid' : '';
    $id = $name ? 'f_'.$name : null;
@endphp

@if ($type === 'section')
    <div class="form-section">{{ $field['label'] }}</div>
@elseif ($type === 'note')
    <div class="field {{ $width }}">
        <span class="label">{{ $field['label'] }}</span>
        <div class="field-note">{{ $field['text'] ?? '' }}</div>
    </div>
@else
    <div class="field {{ $width }}">
        @if (! in_array($type, ['checkbox']))
            <label for="{{ $id }}">{{ $field['label'] }} @if ($required)<span class="req">*</span>@endif</label>
        @else
            <span class="label">{{ $field['label'] }}</span>
        @endif

        @switch($type)
            @case('textarea')
                <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $field['rows'] ?? 4 }}" class="textarea {{ $invalid }}"
                          placeholder="{{ $field['placeholder'] ?? '' }}" @required($required)>{{ $value }}</textarea>
                @break

            @case('select')
                <select id="{{ $id }}" name="{{ $name }}" class="select {{ $invalid }}" @required($required)>
                    <option value="">— Seleccionar —</option>
                    @foreach ($field['options'] ?? [] as $optValue => $optLabel)
                        <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                    @endforeach
                </select>
                @break

            @case('checkbox')
                <input type="hidden" name="{{ $name }}" value="0">
                <label class="check" for="{{ $id }}">
                    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) $value)>
                    {{ $field['checkbox_label'] ?? $field['label'] }}
                </label>
                @break

            @case('file')
                <input type="file" id="{{ $id }}" name="{{ $name }}" class="input {{ $invalid }}" style="padding:.55rem .7rem;height:auto"
                       @isset($field['accept']) accept="{{ $field['accept'] }}" @endisset
                       @isset($field['max_bytes']) data-max-bytes="{{ $field['max_bytes'] }}" @endisset>
                @break

            @case('checkboxes')
                @php($selected = array_map('strval', (array) $value))
                <input type="hidden" name="{{ $name }}" value="">
                <div class="checks">
                    @foreach ($field['options'] ?? [] as $optValue => $optLabel)
                        <label class="check">
                            <input type="checkbox" name="{{ $name }}[]" value="{{ $optValue }}" @checked(in_array((string) $optValue, $selected, true))>
                            {{ $optLabel }}
                        </label>
                    @endforeach
                </div>
                @break

            @default
                <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" class="input {{ $invalid }}"
                       value="{{ $type === 'password' ? '' : $value }}"
                       placeholder="{{ $field['placeholder'] ?? '' }}"
                       @if ($type === 'password') autocomplete="new-password" @endif
                       @isset($field['step']) step="{{ $field['step'] }}" @endisset
                       @isset($field['min']) min="{{ $field['min'] }}" @endisset
                       @isset($field['max']) max="{{ $field['max'] }}" @endisset
                       @isset($field['maxlength']) maxlength="{{ $field['maxlength'] }}" @endisset
                       @if (! empty($field['uppercase'])) data-uppercase @endif
                       @required($required)>
        @endswitch

        @if (! empty($field['help']))
            <div class="help">{{ $field['help'] }}</div>
        @endif
        @error($name)
            <div class="error">{{ $message }}</div>
        @enderror
    </div>
@endif

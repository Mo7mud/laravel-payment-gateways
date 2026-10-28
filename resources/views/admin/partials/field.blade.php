{{-- $field: one entry of GatewaySettingsManager::fields() --}}
@php($key = $field['key'])
@php($type = $field['type'])
@php($value = $field['value'])
<div class="col-lg-6">
    <div class="form-group">
        <label for="{{ strtolower($key) }}">{{ __($field['label']) }}</label>
        @if($type === 'select')
            <select class="form-control" id="{{ strtolower($key) }}" name="env[{{ $key }}]">
                @foreach($field['options'] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" {{ (string) $value === (string) $optionValue ? 'selected' : '' }}>{{ __($optionLabel) }}</option>
                @endforeach
            </select>
        @elseif($type === 'boolean')
            <div class="custom-switches-stacked mt-2">
                <label class="custom-switch">
                    <input type="hidden" name="env[{{ $key }}]" value="0">
                    <input type="checkbox" name="env[{{ $key }}]" value="1" class="custom-switch-input" {{ filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                    <span class="custom-switch-indicator"></span>
                    <span class="custom-switch-description">{{ __('Enabled') }}</span>
                </label>
            </div>
        @else
            <input type="{{ $field['secret'] ? 'password' : ($type === 'number' ? 'number' : ($type === 'url' ? 'url' : 'text')) }}" class="form-control" id="{{ strtolower($key) }}" name="env[{{ $key }}]" value="{{ $field['secret'] ? '' : $value }}" @if($type === 'number') min="{{ $field['min'] }}" max="{{ $field['max'] }}" @endif autocomplete="{{ $field['secret'] ? 'new-password' : 'off' }}" placeholder="{{ $field['secret'] && filled($value) ? __('Leave blank to keep current value') : '' }}">
        @endif
        @if($field['secret'] && filled($value))
            <small class="text-muted">{{ __('A value is already configured. Leave this field empty to keep it unchanged.') }}</small>
        @endif
    </div>
</div>

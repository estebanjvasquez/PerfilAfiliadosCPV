@php
    $checks = $checks ?? [];
    $extra = $extra ?? [];
@endphp

<div class="text-sm space-y-0.5">
    @foreach ($checks as $label => $checked)
        <div>
            <span class="{{ $checked ? 'text-success-600' : 'text-gray-400' }}">
                {{ $checked ? '✓' : '✗' }}
            </span>
            {{ $label }}
        </div>
    @endforeach

    @if (! empty($extra))
        <div class="text-gray-500">Otros: {{ implode(', ', $extra) }}</div>
    @endif
</div>

@php
    $rows = $items ?? [];
    $columns = $columns ?? [];
    $optionsMap = $optionsMap ?? [];
    $noAplica = $noAplica ?? false;

    $resolve = function ($key, $value) use ($optionsMap) {
        if ($value === null || $value === '') {
            return '-';
        }

        if (isset($optionsMap[$key][$value])) {
            return $optionsMap[$key][$value];
        }

        return $value;
    };
@endphp

@if ($noAplica)
    <span class="text-sm text-warning-600 font-medium">No Aplica</span>
@elseif (empty($rows))
    <span class="text-sm text-gray-400">Sin registros</span>
@else
    <table class="filament-tables-repeater-summary text-sm w-full">
        <thead>
            <tr>
                @foreach ($columns as $label)
                    <th class="text-left pr-3 font-medium text-gray-500">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($columns as $key => $label)
                        <td class="pr-3 py-0.5">{{ $resolve($key, $row[$key] ?? null) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

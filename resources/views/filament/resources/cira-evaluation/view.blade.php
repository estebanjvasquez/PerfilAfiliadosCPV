<x-filament-panels::page>
    {{-- TASK-0027: one evaluation, internal panel. Escaped output only ({{ }}). --}}
    @php($na = 'no disponible')
    <x-filament::section>
        <x-slot name="heading">{{ $e->test_code }} · {{ $tests[$e->test_code]['title'] ?? '' }}</x-slot>
        <x-slot name="description">Evaluador {{ $e->invitation?->pseudonym }} · {{ $e->status === 'enviada' ? 'Enviada '.$e->submitted_at?->timezone('America/Caracas')->format('d/m/Y H:i') : 'Borrador' }}</x-slot>
        <dl class="text-sm grid gap-1" data-cira-admin-judgement>
            <div><dt class="inline font-semibold">Juicio sectorial (evaluador):</dt> <dd class="inline">{{ $ratings[$e->rating] ?? 'sin valorar' }}@if ($e->reason) · {{ $reasons[$e->reason] ?? $e->reason }}@endif</dd></div>
            <div><dt class="inline font-semibold">Sobran:</dt> <dd class="inline">{{ collect($e->extra_empresa_ids)->map(fn ($id) => $names[$id] ?? ('id '.$id))->implode(', ') ?: '—' }}</dd></div>
            <div><dt class="inline font-semibold">Faltan:</dt> <dd class="inline">{{ collect($e->missing_empresa_ids)->map(fn ($id) => $names[$id] ?? ('id '.$id))->implode(', ') ?: '—' }}@if ($e->missing_text) · {{ $e->missing_text }}@endif</dd></div>
            <div><dt class="inline font-semibold">Comentario:</dt> <dd class="inline" data-cira-admin-comment>{{ $e->comment ?? '—' }}</dd></div>
            <div><dt class="inline font-semibold">Observación:</dt> <dd class="inline">{{ $e->observation ?? '—' }}</dd></div>
            <div><dt class="inline font-semibold">Hallazgo (revisión):</dt> <dd class="inline">{{ $findings[$e->finding_category] ?? 'sin clasificar' }}@if ($e->reviewer_note) · {{ $e->reviewer_note }}@endif</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Resultado técnico (instantánea inmutable)</x-slot>
        <x-slot name="description">Lo que CIRA devolvió en el momento de la búsqueda. Cambios posteriores del orden no la alteran. Integridad: {{ $hashOk ? 'verificada (SHA-256)' : 'NO coincide con el hash guardado' }}.</x-slot>
        <dl class="text-sm grid gap-1">
            <div><dt class="inline font-semibold">Consulta:</dt> <dd class="inline">{{ $snapshot['user_input'] ?? $na }}</dd></div>
            @if (($snapshot['search_log_id'] ?? null) === null)
                <div><dd class="inline text-gray-500">{{ $snapshot['unavailable'] ?? 'Búsqueda sin registro.' }}</dd></div>
            @else
                <div><dt class="inline font-semibold">Registro:</dt> <dd class="inline">#{{ $snapshot['search_log_id'] }}@if ($e->search_log_id === null) (registro original eliminado; se conserva la instantánea)@endif · {{ $snapshot['logged_at'] ?? $na }}</dd></div>
                <div><dt class="inline font-semibold">Consulta enviada al buscador:</dt> <dd class="inline">{{ $snapshot['agent_query'] ?? $na }}</dd></div>
                <div><dt class="inline font-semibold">Versión del motor:</dt> <dd class="inline">{{ $snapshot['engine_version'] ?? $na }} · {{ $snapshot['result_count'] ?? $na }} resultados · {{ isset($snapshot['response_ms']) ? $snapshot['response_ms'].' ms' : $na }}</dd></div>
                @if (! empty($snapshot['error']))<div><dt class="inline font-semibold">Error de CIRA:</dt> <dd class="inline">{{ \Illuminate\Support\Str::limit($snapshot['error'], 300) }}</dd></div>@endif
            @endif
        </dl>
        @if (is_array($snapshot['results'] ?? null) && $snapshot['results'] !== [])
            <div class="overflow-x-auto mt-3">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">#</th><th class="py-1 pr-3">Empresa</th><th class="py-1 pr-3">Coincidencia</th><th class="py-1 pr-3">Evaluador</th><th class="py-1">Votos 👍/👎</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($snapshot['results'] as $r)
                            @php($v = $votesByCompany->get($r['empresa_id'], collect()))
                            <tr>
                                <td class="py-1 pr-3">{{ $r['position'] }}</td>
                                <td class="py-1 pr-3">{{ $r['name'] ?? 'id '.$r['empresa_id'] }}</td>
                                <td class="py-1 pr-3">{{ collect([$r['evidence']['rel'] ?? null, $r['evidence']['via'] ?? null])->filter(fn ($x) => is_scalar($x) && $x !== '')->implode(' · ') ?: $na }}</td>
                                <td class="py-1 pr-3">{{ in_array($r['empresa_id'], $e->extra_empresa_ids, true) ? 'sobra' : '' }}</td>
                                <td class="py-1">{{ $v->isEmpty() ? '—' : '👍 '.$v->where('vote', 1)->count().' · 👎 '.$v->where('vote', -1)->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif (is_array($snapshot['results'] ?? null))
            <p class="text-sm mt-2">Sin resultados.</p>
        @endif
        @if ($otherVotes->isNotEmpty() || $unlinkedVotes->isNotEmpty())
            <div class="text-sm mt-3">
                @foreach ($otherVotes as $ov)<p>{{ $ov['vote'] === 1 ? '👍' : '👎' }} {{ $ov['reason'] === 'falta_empresa' ? 'falta una empresa' : 'resultado completo' }} ({{ $ov['voter'] }})@if (filled($ov['comment'])): «{{ \Illuminate\Support\Str::limit($ov['comment'], 200) }}»@endif</p>@endforeach
                @foreach ($unlinkedVotes as $uv)<p class="text-gray-500">Voto no vinculado a una posición de esta instantánea: {{ $uv['vote'] === 1 ? '👍' : '👎' }} empresa id {{ $uv['empresa_id'] }} ({{ $uv['voter'] }})</p>@endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

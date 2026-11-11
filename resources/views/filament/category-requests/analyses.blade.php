{{--
    TASK-0016: historial de «Analizar con IA» de una solicitud, del más reciente al más antiguo. Cada análisis es una
    fila que no cambia. Todo texto (del afiliado, del modelo y de las fuentes) se escapa con {{ }}; los enlaces solo
    se muestran si son https a un nombre público. Lo que viene del catálogo se distingue de lo que sugiere la IA.
--}}
@php
    use App\Models\TaxonomyCategoryRequestAnalysis as A;
    use App\Services\CategoryAnalysis\CategoryAnalysisService as S;
    $analyses = A::tableExists() ? $getRecord()->analyses()->with('actor')->get() : collect();
@endphp

<div class="space-y-6 text-sm">
    @forelse ($analyses as $analysis)
        @php
            $out = $analysis->output ?? [];
            $sources = collect($analysis->sources)->keyBy('source_id');
            $selected = collect($analysis->categories_snapshot)->keyBy('category_id');
            $candidates = collect($analysis->catalog_candidates)->keyBy('category_id');
            $judged = collect($out['categories'] ?? [])->keyBy('category_id');
        @endphp
        <section class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <header class="flex flex-wrap items-center gap-2">
                <h3 class="text-base font-semibold">Análisis #{{ $analysis->id }}</h3>
                <x-filament::badge :color="$analysis->status === A::STATUS_COMPLETED ? 'success' : 'danger'">
                    {{ A::STATUS_LABELS[$analysis->status] ?? $analysis->status }}
                </x-filament::badge>
                @if ($analysis->limited)
                    <x-filament::badge color="warning">Limitado: sin fuentes externas verificadas</x-filament::badge>
                @endif
                <span class="text-gray-500">
                    {{ $analysis->created_at?->format('d/m/Y H:i') }} · {{ $analysis->actor?->name ?? 'usuario eliminado' }}
                    · {{ $analysis->provider }}{{ $analysis->model ? ' / '.$analysis->model : '' }} · instrucciones {{ $analysis->prompt_version }}
                    @if ($analysis->input_tokens !== null) · {{ $analysis->input_tokens }} + {{ $analysis->output_tokens }} tokens @endif
                    @if ($analysis->cost_usd !== null) · USD {{ number_format($analysis->cost_usd, 4) }} @endif
                </span>
            </header>

            <p class="mt-2"><span class="font-medium">Categorías elegidas:</span>
                {{ $selected->map(fn ($c) => $c['breadcrumb'].' · '.$c['code'])->implode('; ') }}</p>

            @if ($analysis->status === A::STATUS_FAILED)
                <p class="mt-2 text-danger-600">{{ $analysis->errorLabel() }} La solicitud no cambió.</p>
            @else
                <h4 class="mt-3 font-semibold">Resumen ejecutivo</h4>
                <p>{{ $out['summary'] ?? '' }}</p>

                <h4 class="mt-3 font-semibold">Interpretación técnica y alcance del servicio</h4>
                <p>{{ $out['interpretation'] ?? '' }}</p>

                <h4 class="mt-3 font-semibold">Comparación por categoría</h4>
                <div class="overflow-x-auto">
                    <table class="mt-1 w-full table-auto border-collapse text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="p-2">Categoría (catálogo)</th><th class="p-2">Relación</th><th class="p-2">Coincidencias</th>
                                <th class="p-2">Diferencias</th><th class="p-2">Cubre / no cubre</th><th class="p-2">Razón y fuentes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selected as $id => $category)
                                @php $j = $judged->get($id); @endphp
                                <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                    <td class="p-2"><span class="font-medium">{{ $category['name'] }}</span><br><span class="text-xs text-gray-500">{{ $category['breadcrumb'] }} · {{ $category['code'] }}</span></td>
                                    @if ($j)
                                        <td class="p-2">{{ A::RELATIONS[$j['relation']] ?? $j['relation'] }}</td>
                                        <td class="p-2"><ul class="list-disc ps-4">@foreach ($j['overlaps'] as $t)<li>{{ $t }}</li>@endforeach</ul></td>
                                        <td class="p-2"><ul class="list-disc ps-4">@foreach ($j['differences'] as $t)<li>{{ $t }}</li>@endforeach</ul></td>
                                        <td class="p-2">
                                            <span class="font-medium">Cubre:</span><ul class="list-disc ps-4">@foreach ($j['covered_activities'] as $t)<li>{{ $t }}</li>@endforeach</ul>
                                            <span class="font-medium">No cubre:</span><ul class="list-disc ps-4">@foreach ($j['uncovered_activities'] as $t)<li>{{ $t }}</li>@endforeach</ul>
                                        </td>
                                        <td class="p-2">{{ $j['rationale'] }}
                                            @if ($j['source_ids'] !== [])<br><span class="text-xs">Fuentes: {{ implode(', ', $j['source_ids']) }}</span>@endif
                                        </td>
                                    @else
                                        <td class="p-2" colspan="5"><span class="text-gray-500">La IA no evaluó esta categoría.</span></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (($out['suggested_category_ids'] ?? []) !== [])
                    <h4 class="mt-3 font-semibold">Sugerencias de la IA (no elegidas; vienen del catálogo vigente)</h4>
                    <ul class="list-disc ps-5">
                        @foreach ($out['suggested_category_ids'] as $id)
                            @php $c = $candidates->get($id); @endphp
                            @if ($c)<li>{{ $c['breadcrumb'] }} · {{ $c['code'] }} <span class="text-xs text-gray-500">(sugerencia de la IA)</span></li>@endif
                        @endforeach
                    </ul>
                    <p class="text-xs text-gray-500">Para compararlas, ejecute un análisis nuevo y elíjalas: este informe no cambia.</p>
                @endif

                <h4 class="mt-3 font-semibold">Recomendación</h4>
                <p><span class="font-medium">{{ A::RECOMMENDATIONS[$out['recommendation']['type'] ?? ''] ?? '' }}.</span> {{ $out['recommendation']['rationale'] ?? '' }}</p>
                @if (($out['recommendation']['category_ids'] ?? []) !== [])
                    <p class="text-xs">Categorías: {{ collect($out['recommendation']['category_ids'])->map(fn ($id) => ($selected->get($id) ?? $candidates->get($id))['code'] ?? $id)->implode(', ') }}</p>
                @endif
                <p class="text-xs text-gray-500">La IA asesora: aprobar, vincular o crear categorías sigue siendo una decisión de la Cámara.</p>

                @if (($out['clarification_questions'] ?? []) !== [])
                    <h4 class="mt-3 font-semibold">Preguntas para aclarar con el afiliado</h4>
                    <ul class="list-disc ps-5">@foreach ($out['clarification_questions'] as $t)<li>{{ $t }}</li>@endforeach</ul>
                @endif
            @endif

            <h4 class="mt-3 font-semibold">Referencias</h4>
            @if ($sources->isEmpty())
                <p class="text-gray-500">Sin fuentes externas: no se verificó nada fuera del catálogo CPV.</p>
            @else
                <ol class="list-decimal ps-5">
                    @foreach ($sources as $id => $s)
                        <li>
                            <span class="font-medium">{{ $id }}</span> ·
                            @if (S::isPublicHttpsUrl($s['url']))
                                <a class="text-primary-600 underline" href="{{ $s['url'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $s['title'] ?: $s['url'] }}</a>
                            @else
                                {{ $s['title'] }}
                            @endif
                            @if ($s['entity']) · {{ $s['entity'] }} @endif
                            · consultada {{ \Illuminate\Support\Carbon::parse($s['retrieved_at'])->format('d/m/Y') }}
                            @if ($s['published_at']) · publicada {{ $s['published_at'] }} @endif
                            · {{ $s['verified'] === true ? 'enlace comprobado' : ($s['verified'] === false ? 'el enlace no respondió' : 'enlace sin comprobar') }}
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ((($out['limitations'] ?? []) !== []) || $analysis->validation_issues !== [])
                <h4 class="mt-3 font-semibold">Limitaciones</h4>
                <ul class="list-disc ps-5">
                    @foreach ($out['limitations'] ?? [] as $t)<li>{{ $t }}</li>@endforeach
                    @foreach ($analysis->validation_issues as $t)<li class="text-warning-600">Control de la respuesta: {{ $t }}</li>@endforeach
                </ul>
            @endif
        </section>
    @empty
        <p class="text-gray-500">Todavía no hay análisis de esta solicitud.</p>
    @endforelse
</div>

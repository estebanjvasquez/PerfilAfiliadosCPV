<x-filament-panels::page>
    {{-- TASK-0027: evaluate one search. Every value is printed with {{ }} (escaped); nothing of a search is shown unless
         it belongs to this browser session (see CiraEvaluationFormPage). Fields the log does not have say so. --}}
    @php
        $na = 'no disponible';
        $fmtFuentes = function ($fuentes) {
            if (! is_array($fuentes) || $fuentes === []) { return null; }
            return collect($fuentes)->take(3)->map(fn ($f) => \Illuminate\Support\Str::limit(is_array($f) ? implode(' · ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $f))) : (string) $f, 90))->implode(' | ');
        };
    @endphp

    @if ($this->logRefused)
        <x-filament::section>
            <x-slot name="heading">Esta búsqueda no se puede evaluar desde aquí</x-slot>
            <p class="text-sm" data-cira-log-refused>Solo se puede evaluar una búsqueda desde el mismo navegador en el que se hizo. Vuelva a <a class="text-primary-600 underline" href="/cira-test/">CIRA Test</a>, haga la búsqueda y pulse «Evaluar esta búsqueda». También puede evaluarla sin registro escribiendo la búsqueda abajo.</p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Búsqueda evaluada</x-slot>
        @if ($snapshot === null)
            <p class="text-sm text-gray-500 dark:text-gray-400" data-cira-free-search>Búsqueda sin registro: escriba abajo lo que buscó. No se guardan resultados, posiciones ni versión del motor, porque no hay registro de ellos.</p>
        @else
            <dl class="text-sm grid gap-1" data-cira-snapshot>
                <div><dt class="inline font-semibold">Consulta:</dt> <dd class="inline">{{ $snapshot['user_input'] }}</dd></div>
                @if (($snapshot['search_log_id'] ?? null) === null)
                    <div><dd class="inline text-gray-500">{{ $snapshot['unavailable'] ?? 'Búsqueda sin registro.' }}</dd></div>
                @else
                    <div><dt class="inline font-semibold">Registro:</dt> <dd class="inline">#{{ $snapshot['search_log_id'] }} · {{ $snapshot['logged_at'] ? \Illuminate\Support\Carbon::parse($snapshot['logged_at'])->timezone('America/Caracas')->format('d/m/Y H:i') : $na }}</dd></div>
                    <div><dt class="inline font-semibold">Consulta enviada al buscador:</dt> <dd class="inline">{{ $snapshot['agent_query'] ?? $na }}</dd></div>
                    <div><dt class="inline font-semibold">Resultados:</dt> <dd class="inline">{{ $snapshot['result_count'] ?? $na }} · tiempo de respuesta: {{ isset($snapshot['response_ms']) ? $snapshot['response_ms'].' ms' : $na }} · versión del motor: {{ $snapshot['engine_version'] ?? $na }}</dd></div>
                    @if (! empty($snapshot['error']))
                        <div><dt class="inline font-semibold">Error de CIRA:</dt> <dd class="inline text-danger-600">{{ \Illuminate\Support\Str::limit($snapshot['error'], 300) }}</dd></div>
                    @endif
                @endif
            </dl>

            @if (is_array($snapshot['results'] ?? null))
                @if ($snapshot['results'] === [])
                    <p class="text-sm mt-2" data-cira-no-results>CIRA no devolvió resultados para esta búsqueda.</p>
                @else
                    <div class="overflow-x-auto mt-3">
                        <table class="w-full text-sm" data-cira-results>
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-3">#</th><th class="py-1 pr-3">Empresa</th><th class="py-1 pr-3">Por qué aparece</th><th class="py-1">Votos 👍/👎</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($snapshot['results'] as $r)
                                    @php($v = $votesByCompany->get($r['empresa_id'], collect()))
                                    <tr>
                                        <td class="py-1 pr-3">{{ $r['position'] }}</td>
                                        <td class="py-1 pr-3">{{ $r['name'] ?? 'empresa ya no disponible (id '.$r['empresa_id'].')' }}</td>
                                        <td class="py-1 pr-3">
                                            {{ collect([$r['evidence']['rel'] ?? null, $r['evidence']['via'] ?? null])->filter(fn ($x) => is_scalar($x) && $x !== '')->implode(' · ') ?: $na }}
                                            @if ($f = $fmtFuentes($r['evidence']['fuentes'] ?? null))<br><span class="text-gray-500">{{ $f }}</span>@endif
                                        </td>
                                        <td class="py-1">
                                            @if ($v->isEmpty())
                                                <span class="text-gray-500">—</span>
                                            @else
                                                👍 {{ $v->where('vote', 1)->count() }} · 👎 {{ $v->where('vote', -1)->count() }}
                                                @if ($v->contains('mine', true)) <span class="text-gray-500">(incluye su voto)</span>@endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($otherVotes->isNotEmpty())
                <div class="text-sm mt-3" data-cira-other-votes>
                    <p class="font-semibold">Otros votos de esta búsqueda</p>
                    <ul class="list-disc pl-5">
                        @foreach ($otherVotes as $ov)
                            <li>{{ $ov['vote'] === 1 ? '👍' : '👎' }} {{ $reasonsCira[$ov['reason']] ?? 'resultado completo' }}@if (filled($ov['comment'])): «{{ \Illuminate\Support\Str::limit($ov['comment'], 200) }}»@endif @if ($ov['mine'])<span class="text-gray-500">(suyo)</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <p class="text-xs text-gray-500 mt-2">Los votos 👍/👎 se registran en CIRA Test y aquí solo se muestran: no se duplican ni cambian el orden de los resultados.</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Su evaluación @if ($status) <x-filament::badge :color="$status === 'enviada' ? 'success' : 'warning'">{{ $status === 'enviada' ? 'enviada' : 'borrador' }}</x-filament::badge>@endif</x-slot>
        <form wire:submit="submit" class="space-y-4" data-cira-evaluation-form>
            {{ $this->form }}
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="button" color="gray" wire:click="saveDraft" wire:loading.attr="disabled" data-cira-save-draft>Guardar borrador</x-filament::button>
                <x-filament::button type="submit" wire:loading.attr="disabled" data-cira-submit>Enviar evaluación</x-filament::button>
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\CiraEvaluationHomePage::getUrl()">Mis pruebas</x-filament::button>
                <x-filament::button tag="a" color="gray" href="/cira-test/">Volver a CIRA Test</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>

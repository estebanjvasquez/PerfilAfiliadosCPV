<x-filament-panels::page>
    {{-- TASK-0027: consolidated report. Escaped output only. --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm font-semibold" for="cira-campaign">Campaña</label>
            <select id="cira-campaign" wire:model.live="campaignId" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                @foreach ($campaigns as $c)
                    <option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }} ({{ \App\Models\Cira\CiraEvalCampaign::STATUSES[$c->status] ?? $c->status }})</option>
                @endforeach
            </select>
            @if ($campaign)
                <x-filament::button color="gray" size="sm" wire:click="exportCsv" icon="heroicon-o-arrow-down-tray">Exportar CSV (opcional)</x-filament::button>
            @endif
        </div>
        <p class="text-sm text-gray-500 mt-2">Este informe no declara aceptado el buscador. Separa el <strong>resultado técnico</strong> (lo que CIRA devolvió, guardado en cada evaluación), el <strong>juicio sectorial</strong> (las valoraciones de los evaluadores) y la <strong>decisión de la Cámara</strong>, que se registra a mano por prueba.</p>
    </x-filament::section>

    @if (! $report)
        <x-filament::section><p class="text-sm">No hay campañas todavía.</p></x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Resumen</x-slot>
            <p class="text-sm" data-cira-report-totals>Evaluaciones enviadas: {{ $report['totals']['sent'] }} · borradores: {{ $report['totals']['drafts'] }} · evaluadores con evaluaciones: {{ $report['totals']['evaluators'] }} de {{ $report['totals']['invited'] }} invitados · sin registro de búsqueda: {{ $report['totals']['without_log'] }}</p>
            <p class="text-sm">Votos 👍/👎 de las búsquedas evaluadas (de CIRA Test, sin duplicar): 👍 {{ $report['votes']['up'] }} · 👎 {{ $report['votes']['down'] }} · reportes de empresa faltante: {{ $report['votes']['missing_reports'] }} · vinculados a un resultado: {{ $report['votes']['linked'] }} · no vinculados: {{ $report['votes']['unlinked'] }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Por prueba: juicio sectorial y decisión de la Cámara</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-cira-report-tests>
                    <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">Prueba</th><th class="py-1 pr-3">Correcta</th><th class="py-1 pr-3">Parcial</th><th class="py-1 pr-3">Incorrecta</th><th class="py-1 pr-3">Borradores</th><th class="py-1 pr-3">Evaluadores</th><th class="py-1">Decisión de la Cámara</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($report['tests'] as $code => $t)
                            <tr>
                                <td class="py-2 pr-3"><strong>{{ $code }}</strong> · {{ $t['title'] }}</td>
                                <td class="py-2 pr-3">{{ $t['correcta'] }}</td>
                                <td class="py-2 pr-3">{{ $t['parcial'] }}</td>
                                <td class="py-2 pr-3">{{ $t['incorrecta'] }}</td>
                                <td class="py-2 pr-3">{{ $t['drafts'] }}</td>
                                <td class="py-2 pr-3">{{ $t['evaluators'] }}</td>
                                <td class="py-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select wire:model="decisions.{{ $code }}.decision" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900" aria-label="Decisión {{ $code }}">
                                            <option value="">Sin decisión</option>
                                            @foreach ($decisionOptions as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <input type="text" maxlength="2000" wire:model="decisions.{{ $code }}.note" placeholder="Nota (opcional)" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900" aria-label="Nota {{ $code }}">
                                        <x-filament::button size="sm" wire:click="decide('{{ $code }}')">Guardar</x-filament::button>
                                    </div>
                                    @error("decisions.$code.decision")<p class="text-danger-600 text-xs">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Prioridades de mejora</x-slot>
            <div class="grid gap-4 md:grid-cols-2 text-sm">
                <div>
                    <p class="font-semibold">Empresas señaladas como faltantes</p>
                    <ul class="list-disc pl-5">@forelse (array_slice($report['missing'], 0, 15) as $m)<li>{{ $m['name'] ?? 'id '.$m['empresa_id'] }} ({{ $m['count'] }})</li>@empty<li>—</li>@endforelse</ul>
                </div>
                <div>
                    <p class="font-semibold">Resultados señalados como sobrantes</p>
                    <ul class="list-disc pl-5">@forelse (array_slice($report['extra'], 0, 15) as $m)<li>{{ $m['name'] ?? 'id '.$m['empresa_id'] }} ({{ $m['count'] }})</li>@empty<li>—</li>@endforelse</ul>
                </div>
                <div>
                    <p class="font-semibold">Hallazgos por tipo (clasificados en revisión)</p>
                    <ul class="list-disc pl-5">@foreach ($report['findings'] as $f)<li>{{ $f['label'] }}: {{ $f['count'] }}</li>@endforeach</ul>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Comentarios</x-slot>
            @forelse ($report['comments'] as $c)
                <div class="text-sm py-1 border-b border-gray-100 dark:border-white/10" data-cira-report-comment>
                    <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\CiraEvaluationResource::getUrl('view', ['record' => $c['id']]) }}">{{ $c['test'] }} · {{ $c['evaluator'] }}</a>
                    · {{ config('cira_evaluation.ratings')[$c['rating']] ?? '—' }}: {{ $c['comment'] }}@if ($c['observation']) — {{ $c['observation'] }}@endif
                </div>
            @empty
                <p class="text-sm text-gray-500">Sin comentarios todavía.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>

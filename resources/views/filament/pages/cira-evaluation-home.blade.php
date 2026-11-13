<x-filament-panels::page>
    {{-- TASK-0027: «Mis pruebas». Everything printed with {{ }} (escaped). --}}
    <x-filament::section>
        <x-slot name="heading">{{ $campaign->name }}</x-slot>
        <x-slot name="description">
            Usted participa como evaluador {{ $pseudonym }}. Haga cada búsqueda en CIRA Test y pulse «Evaluar esta búsqueda» debajo de los resultados.
            Puede guardar por partes y volver cuando quiera. Duración estimada: {{ $minutes }} minutos.
        </x-slot>
        <div class="flex flex-wrap gap-3">
            <x-filament::button tag="a" href="/cira-test/" icon="heroicon-o-magnifying-glass">Abrir CIRA Test</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\CiraEvaluationGuidePage::getUrl()" icon="heroicon-o-book-open">Guía de evaluación</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\CiraEvaluationFormPage::getUrl()" icon="heroicon-o-pencil-square">Evaluar una búsqueda sin registro</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Progreso de las 8 pruebas</x-slot>
        <ul class="divide-y divide-gray-100 dark:divide-white/10" data-cira-progress>
            @foreach ($progress as $code => $p)
                <li class="py-2 text-sm flex flex-wrap items-center justify-between gap-2">
                    <span><strong>{{ $code }}</strong> · {{ $p['title'] }}</span>
                    <span>
                        @if ($p['sent'] > 0)
                            <x-filament::badge color="success">{{ $p['sent'] }} enviada(s)</x-filament::badge>
                        @elseif ($p['drafts'] > 0)
                            <x-filament::badge color="warning">borrador</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">pendiente</x-filament::badge>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Mis evaluaciones</x-slot>
        @if ($evaluations->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Todavía no ha guardado ninguna evaluación.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-cira-my-evaluations>
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-3">Prueba</th><th class="py-1 pr-3">Búsqueda</th><th class="py-1 pr-3">Valoración</th><th class="py-1 pr-3">Estado</th><th class="py-1 pr-3">Actualizada</th><th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($evaluations as $e)
                            <tr>
                                <td class="py-1 pr-3">{{ $e->test_code }}</td>
                                <td class="py-1 pr-3">{{ \Illuminate\Support\Str::limit($e->query_text, 80) }}@if ($e->search_log_id === null && ($e->snapshot['search_log_id'] ?? null) === null) <span class="text-gray-500">(sin registro)</span>@endif</td>
                                <td class="py-1 pr-3">{{ $ratings[$e->rating] ?? '—' }}</td>
                                <td class="py-1 pr-3">{{ $e->status === 'enviada' ? 'Enviada' : 'Borrador' }}</td>
                                <td class="py-1 pr-3">{{ $e->updated_at?->timezone('America/Caracas')->format('d/m/Y H:i') }}</td>
                                <td class="py-1"><a class="text-primary-600 underline" href="{{ \App\Filament\Pages\CiraEvaluationFormPage::getUrl(['evaluacion' => $e->id]) }}">Abrir</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

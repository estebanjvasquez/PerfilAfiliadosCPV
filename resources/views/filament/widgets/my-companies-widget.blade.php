{{--
    TASK-0011: tarjeta «Mi empresa» de Inicio. Solo componentes de Filament y clases de texto que el panel ya compila;
    los espacios van en línea (el panel no compila un tema Tailwind propio).
--}}
<x-filament-widgets::widget class="cpv-my-companies-widget">
    <x-filament::section>
        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
            {{ $companies->count() === 1 ? 'Mi empresa' : 'Mis empresas' }}
        </h2>

        @if ($companies->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top: 0.25rem;">
                Tiene acceso a {{ $total }} empresas.
            </p>
            <div style="margin-top: 0.75rem;">
                <x-filament::button tag="a" :href="$listUrl" icon="heroicon-o-building-office">
                    Ver empresas
                </x-filament::button>
            </div>
        @else
            <div style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
                @foreach ($companies as $company)
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 1rem;">
                        <div style="flex: 1 1 16rem; min-width: 0;">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $company['name'] }}
                                <x-filament::badge
                                    :color="$company['percentage'] >= 100 ? 'success' : ($company['percentage'] > 0 ? 'warning' : 'danger')"
                                    style="display: inline-flex; margin-left: 0.25rem; vertical-align: middle;"
                                >
                                    {{ $company['percentage'] }}% completado
                                </x-filament::badge>
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if ($company['pending'] === [])
                                    Perfil completo. Revíselo de vez en cuando para mantenerlo al día.
                                @else
                                    Pendiente: {{ implode(', ', $company['pending']) }}.
                                @endif
                            </p>
                        </div>
                        <x-filament::button tag="a" :href="$company['url']" icon="heroicon-o-pencil-square">
                            Editar perfil
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

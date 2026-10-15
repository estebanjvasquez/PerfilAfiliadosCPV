{{--
    CPV-HOME-USEFUL-HELP: tarjeta «Ayuda para completar su perfil» del Escritorio. Solo componentes de
    Filament y las mismas clases de texto que usa su tarjeta de cuenta (el panel no compila un tema
    Tailwind propio); los espacios van en línea para no depender de utilidades que no estén compiladas.
--}}
<x-filament-widgets::widget class="cpv-profile-help-widget">
    <x-filament::section>
        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
            Ayuda para completar su perfil
        </h2>

        <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top: 0.25rem;">
            Antes de actualizar el perfil de su empresa, consulte la guía y explore las categorías de la Taxonomía CPV.
            El mapa es solo para ubicarse: las categorías se agregan desde la pestaña Categorías CPV de su empresa.
        </p>

        <div style="margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <x-filament::button tag="a" :href="$manualUrl" color="gray" icon="heroicon-o-book-open">
                Manual de usuario
            </x-filament::button>

            @if ($mapUrl)
                <x-filament::button tag="a" :href="$mapUrl" color="gray" icon="heroicon-o-map">
                    Mapa de la taxonomía
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

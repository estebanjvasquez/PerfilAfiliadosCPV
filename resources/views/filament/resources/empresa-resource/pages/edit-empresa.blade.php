{{--
    TASK-0011 (Bloque 2): la vista de Filament 3.3.55 (resources/pages/edit-record.blade.php) con un título entre el
    formulario por pasos y las secciones del perfil. Antes las 10 pestañas aparecían sin explicación, en una barra con
    desplazamiento horizontal que escondía las últimas («Presencia internacional», «Enfoques de sostenibilidad»), y el
    usuario no sabía que guardan al instante, a diferencia del formulario.
--}}
<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    <x-filament-panels::form
        id="form"
        :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
        wire:submit="save"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    @php
        $relationManagers = $this->getRelationManagers();
    @endphp

    @if (count($relationManagers))
        <section class="cpv-profile-sections" aria-labelledby="cpv-profile-sections-title">
            <h2 id="cpv-profile-sections-title" class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                Secciones del perfil
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Elija una sección para completarla. Cada cambio que confirme aquí se guarda al instante; no depende de
                «Guardar cambios». El porcentaje de cada sección indica cuánto falta.
            </p>
        </section>

        <style>
            /* Todas las pestañas a la vista: en varias filas en vez de una barra con desplazamiento horizontal. */
            .cpv-profile-sections ~ .fi-resource-relation-managers .fi-tabs {
                flex-wrap: wrap;
                row-gap: 0.25rem;
                overflow-x: visible;
            }
        </style>

        <x-filament-panels::resources.relation-managers
            :active-locale="isset($activeLocale) ? $activeLocale : null"
            :active-manager="$this->activeRelationManager ?? array_key_first($relationManagers)"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        />
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>

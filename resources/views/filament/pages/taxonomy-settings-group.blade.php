<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex gap-3">
            <x-filament::button type="submit">
                Guardar
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="restoreDefaults">
                Restaurar default
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

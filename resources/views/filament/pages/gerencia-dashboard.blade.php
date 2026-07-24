<x-filament::page>
    @php($filtros = $this->getFiltros())

    <form method="GET" class="filament-page flex flex-wrap items-end gap-4 rounded-xl bg-white p-4 shadow dark:bg-gray-800">
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Sector</label>
            <select name="sector_id" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                <option value="">Todos</option>
                @foreach ($this->getSectores() as $id => $name)
                    <option value="{{ $id }}" @selected((string) $filtros['sector_id'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Cámara / Capítulo</label>
            <select name="chamber_id" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                <option value="">Todas</option>
                @foreach ($this->getCamaras() as $id => $name)
                    <option value="{{ $id }}" @selected((string) $filtros['chamber_id'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Estado</label>
            <select name="state_id" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                <option value="">Todos</option>
                @foreach ($this->getEstados() as $id => $name)
                    <option value="{{ $id }}" @selected((string) $filtros['state_id'] === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="include_inactive" value="1" @checked($filtros['include_inactive'])>
            Incluir inactivas
        </label>

        <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500">
            Filtrar
        </button>

        @if ($filtros['sector_id'] || $filtros['chamber_id'] || $filtros['state_id'] || $filtros['include_inactive'])
            <a href="{{ url()->current() }}" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">
                Limpiar filtros
            </a>
        @endif
    </form>
</x-filament::page>

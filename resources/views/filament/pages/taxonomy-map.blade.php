@php
    $n = fn (int $value) => number_format($value, 0, ',', '.');
    $plural = fn (int $value, string $one, string $many) => $n($value).' '.($value === 1 ? $one : $many);
    $split = function (array $counts) use ($plural) {
        $parts = [$plural($counts['bien'], 'bien', 'bienes'), $plural($counts['servicio'], 'servicio', 'servicios')];
        if ($counts['otro'] > 0) {
            $parts[] = $plural($counts['otro'], 'sin tipo', 'sin tipo');
        }

        return implode(' · ', $parts);
    };
    $badge = ['bien' => 'Bien', 'servicio' => 'Servicio', 'otro' => 'Sin tipo'];
@endphp

<x-filament-panels::page>
    {{--
        Estilos acotados a .cpv-map, igual que el manual: el panel no compila un tema Tailwind propio,
        así que solo se usan las variables de color de Filament (claro/oscuro).
    --}}
    <style>
        .cpv-map { color: rgb(var(--gray-800)); line-height: 1.55; font-size: 0.95rem; }
        .dark .cpv-map { color: rgb(var(--gray-200)); }
        .cpv-map [hidden] { display: none !important; }

        .cpv-map__panel { border: 1px solid rgb(var(--gray-200)); border-radius: 0.75rem; padding: 1rem 1.25rem; background: #fff; }
        .dark .cpv-map__panel { border-color: rgba(255, 255, 255, 0.1); background: rgb(var(--gray-900)); }
        .cpv-map__intro p { margin: 0.4rem 0; }
        .cpv-map__intro strong { font-weight: 700; }
        .cpv-map a { color: rgb(var(--primary-700)); text-decoration: underline; text-underline-offset: 2px; }
        .dark .cpv-map a { color: rgb(var(--primary-300)); }

        .cpv-map__filter { margin-top: 1rem; display: grid; gap: 0.4rem; }
        .cpv-map__filter label { font-weight: 600; }
        .cpv-map__filter-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .cpv-map__filter input {
            flex: 1 1 14rem; min-width: 0; padding: 0.5rem 0.75rem; border-radius: 0.5rem;
            border: 1px solid rgb(var(--gray-300)); background: #fff; color: inherit;
        }
        .dark .cpv-map__filter input { border-color: rgb(var(--gray-600)); background: rgb(var(--gray-800)); }
        .cpv-map__button {
            padding: 0.5rem 0.9rem; border-radius: 0.5rem; border: 1px solid rgb(var(--gray-300));
            background: rgb(var(--gray-50)); font-weight: 600; color: inherit;
        }
        .dark .cpv-map__button { border-color: rgb(var(--gray-600)); background: rgb(var(--gray-800)); }
        .cpv-map__hint { font-size: 0.85rem; color: rgb(var(--gray-600)); }
        .dark .cpv-map__hint { color: rgb(var(--gray-400)); }
        .cpv-map__status { font-weight: 600; min-height: 1.5em; }

        .cpv-map__groups { margin-top: 1rem; display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); }
        .cpv-map__group { border: 1px solid rgb(var(--gray-200)); border-radius: 0.75rem; background: #fff; min-width: 0; }
        .dark .cpv-map__group { border-color: rgba(255, 255, 255, 0.1); background: rgb(var(--gray-900)); }
        .cpv-map__group[open] { grid-column: 1 / -1; }
        .cpv-map__group > summary { padding: 0.8rem 1rem; cursor: pointer; display: grid; gap: 0.15rem; }
        .cpv-map__group > summary .cpv-map__name { font-weight: 700; font-size: 1rem; }
        .cpv-map summary:focus-visible { outline: 2px solid rgb(var(--primary-600)); outline-offset: 2px; border-radius: 0.5rem; }
        .cpv-map__meta { font-size: 0.85rem; color: rgb(var(--gray-600)); }
        .dark .cpv-map__meta { color: rgb(var(--gray-400)); }
        .cpv-map__empty { font-size: 0.85rem; font-style: italic; color: rgb(var(--gray-600)); }
        .dark .cpv-map__empty { color: rgb(var(--gray-400)); }

        .cpv-map__families { list-style: none; margin: 0; padding: 0 1rem 1rem; display: grid; gap: 0.35rem; }
        .cpv-map__family > details > summary, .cpv-map__family-empty { padding: 0.45rem 0.6rem; border-radius: 0.5rem; background: rgb(var(--gray-50)); }
        .dark .cpv-map__family > details > summary, .dark .cpv-map__family-empty { background: rgb(var(--gray-800)); }
        .cpv-map__family > details > summary { cursor: pointer; }
        .cpv-map__family .cpv-map__name { font-weight: 600; }
        .cpv-map__categories { list-style: none; margin: 0.35rem 0 0.5rem; padding: 0 0 0 1rem; display: grid; gap: 0.2rem; }
        .cpv-map__category { display: flex; gap: 0.5rem; align-items: baseline; justify-content: space-between; padding: 0.15rem 0.25rem; border-bottom: 1px dashed rgb(var(--gray-200)); }
        .dark .cpv-map__category { border-bottom-color: rgba(255, 255, 255, 0.08); }
        .cpv-map__category .cpv-map__name { overflow-wrap: anywhere; }
        .cpv-map__badge { flex: none; font-size: 0.75rem; font-weight: 600; padding: 0.05rem 0.45rem; border-radius: 999px; border: 1px solid currentColor; }
        .cpv-map__badge--bien { color: rgb(var(--info-700)); }
        .cpv-map__badge--servicio { color: rgb(var(--success-700)); }
        .cpv-map__badge--otro { color: rgb(var(--gray-600)); }
        .dark .cpv-map__badge--bien { color: rgb(var(--info-300)); }
        .dark .cpv-map__badge--servicio { color: rgb(var(--success-300)); }
        .dark .cpv-map__badge--otro { color: rgb(var(--gray-300)); }
        .cpv-map mark { background: rgb(var(--warning-200)); color: rgb(var(--gray-950)); border-radius: 0.15rem; padding: 0 0.05rem; }
    </style>

    <div
        class="cpv-map"
        @if (\Filament\Support\Facades\FilamentView::hasSpaMode())
            {{-- format-ignore-start --}}x-load="visible"{{-- format-ignore-end --}}
        @else
            x-load
        @endif
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('taxonomy-map') }}"
        x-data="cpvTaxonomyMap()"
    >
        <section class="cpv-map__panel cpv-map__intro" aria-label="Qué es este mapa">
            <p>
                Este mapa muestra <strong>toda la Taxonomía CPV publicada</strong>:
                {{ $plural($totals['groups'], 'Grupo', 'Grupos') }},
                {{ $plural($totals['families'], 'Familia', 'Familias') }} y
                {{ $plural($totals['categories'], 'Categoría', 'Categorías') }}.
                Abra un Grupo para ver sus Familias, y una Familia para ver sus Categorías.
            </p>
            <p>
                Es solo para ubicarse: <strong>desde aquí no se agregan categorías</strong>. Para agregarlas a su empresa use
                <strong>Buscar y agregar categoría</strong> en la pestaña <strong>Categorías CPV (taxonomía nueva)</strong> de su empresa.
                Solo se pueden agregar Categorías, nunca un Grupo ni una Familia completos.
            </p>
            <p>
                Un Grupo o una Familia marcados <em>sin categorías todavía</em> no tienen Categorías publicadas: no hay nada que elegir allí.
                Si lo que su empresa ofrece estaría en ese lugar, busque una Categoría relacionada o solicite una revisión a la Cámara.
                <a href="{{ $manualUrl }}">Más ayuda en el manual</a>.
            </p>

            <div class="cpv-map__filter" x-cloak x-show="ready">
                <label for="cpv-map-filter">Filtrar el mapa por nombre</label>
                <div class="cpv-map__filter-row">
                    <input
                        id="cpv-map-filter"
                        type="search"
                        autocomplete="off"
                        x-ref="input"
                        x-model="query"
                        x-on:input.debounce.150ms="apply()"
                        x-on:keydown.escape.prevent="clear()"
                        aria-describedby="cpv-map-status cpv-map-hint"
                        placeholder="ej. válvulas, perforación, logística"
                    >
                    <button type="button" class="cpv-map__button" x-on:click="clear()" x-show="query !== ''">Limpiar filtro</button>
                </div>
                <p id="cpv-map-status" class="cpv-map__status" role="status" aria-live="polite" x-text="status"></p>
                <p id="cpv-map-hint" class="cpv-map__hint">
                    El filtro busca las letras que escriba (al menos 3) solo en los nombres de este mapa, sin distinguir mayúsculas ni tildes.
                    No reconoce sinónimos ni términos en inglés: para encontrar una categoría a partir de lo que hace su empresa, use
                    <strong>Buscar y agregar categoría</strong>.
                </p>
                <p class="cpv-map__hint" x-cloak x-show="noResults">
                    Ningún nombre del mapa contiene esas letras. Pruebe con otra palabra o una más general, o use
                    <strong>Buscar y agregar categoría</strong>, que también reconoce sinónimos y términos en inglés. Si aun así no la encuentra,
                    solicite una revisión a la Cámara desde la misma pestaña.
                </p>
            </div>
            <noscript>
                <p class="cpv-map__hint">El filtro necesita JavaScript. Puede recorrer el mapa abriendo cada Grupo y cada Familia.</p>
            </noscript>
        </section>

        <div class="cpv-map__groups">
            @foreach ($groups as $group)
                <details class="cpv-map__group" data-map-node="group">
                    <summary>
                        <span class="cpv-map__name" data-map-name>{{ $group['name'] }}</span>
                        <span class="cpv-map__meta">
                            {{ $plural($group['counts']['families'], 'Familia', 'Familias') }} ·
                            {{ $plural($group['counts']['categories'], 'Categoría', 'Categorías') }}
                        </span>
                        @if ($group['counts']['categories'] === 0)
                            <span class="cpv-map__empty">sin categorías todavía</span>
                        @else
                            <span class="cpv-map__meta">{{ $split($group['counts']) }}</span>
                        @endif
                    </summary>

                    <ul class="cpv-map__families">
                        @forelse ($group['families'] as $family)
                            <li class="cpv-map__family" data-map-node="family">
                                @if ($family['counts']['categories'] > 0)
                                    <details>
                                        <summary>
                                            <span class="cpv-map__name" data-map-name>{{ $family['name'] }}</span>
                                            <span class="cpv-map__meta">— {{ $plural($family['counts']['categories'], 'Categoría', 'Categorías') }}</span>
                                        </summary>
                                        <ul class="cpv-map__categories">
                                            @foreach ($family['categories'] as $category)
                                                <li class="cpv-map__category" data-map-node="category">
                                                    <span class="cpv-map__name" data-map-name>{{ $category['name'] }}</span>
                                                    <span class="cpv-map__badge cpv-map__badge--{{ $category['tipo'] }}">{{ $badge[$category['tipo']] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @else
                                    <div class="cpv-map__family-empty">
                                        <span class="cpv-map__name" data-map-name>{{ $family['name'] }}</span>
                                        <span class="cpv-map__empty">— sin categorías todavía</span>
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="cpv-map__empty">Este Grupo no tiene Familias publicadas: sin categorías todavía.</li>
                        @endforelse
                    </ul>
                </details>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

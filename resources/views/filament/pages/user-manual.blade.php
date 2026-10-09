<x-filament-panels::page>
    {{--
        Estilos acotados a .cpv-manual: el panel no compila un tema Tailwind propio, así que no se
        puede depender de clases utilitarias arbitrarias. Solo se usan las variables de color que
        Filament ya define (claro/oscuro) - sin dependencias externas.
    --}}
    <style>
        .cpv-manual { color: rgb(var(--gray-800)); line-height: 1.65; font-size: 0.975rem; }
        .dark .cpv-manual { color: rgb(var(--gray-200)); }

        .cpv-manual__layout { display: flex; flex-direction: column; gap: 1.5rem; }

        .cpv-manual__toc {
            border: 1px solid rgb(var(--gray-200)); border-radius: 0.75rem; padding: 1rem 1.25rem;
            background: #fff;
        }
        .dark .cpv-manual__toc { border-color: rgba(255, 255, 255, 0.1); background: rgb(var(--gray-900)); }
        .cpv-manual__toc-title { font-weight: 600; font-size: 1rem; margin: 0 0 0.5rem; }
        .cpv-manual__toc ol { margin: 0; padding-left: 1.25rem; list-style: decimal; }
        .cpv-manual__toc li { margin: 0.25rem 0; }

        .cpv-manual__content {
            min-width: 0; border: 1px solid rgb(var(--gray-200)); border-radius: 0.75rem;
            padding: 1.25rem; background: #fff;
        }
        .dark .cpv-manual__content { border-color: rgba(255, 255, 255, 0.1); background: rgb(var(--gray-900)); }

        @media (min-width: 1024px) {
            .cpv-manual__layout { display: grid; grid-template-columns: 17rem minmax(0, 1fr); align-items: start; gap: 2rem; }
            .cpv-manual__toc { position: sticky; top: 5rem; max-height: calc(100vh - 6rem); overflow-y: auto; }
            .cpv-manual__content { padding: 2rem 2.5rem; }
        }

        .cpv-manual a { color: rgb(var(--primary-700)); text-decoration: underline; text-underline-offset: 2px; }
        .dark .cpv-manual a { color: rgb(var(--primary-300)); }
        .cpv-manual a:focus-visible { outline: 2px solid rgb(var(--primary-600)); outline-offset: 2px; border-radius: 2px; }

        .cpv-manual__content h2, .cpv-manual__content h3, .cpv-manual__content h4 { scroll-margin-top: 5.5rem; font-weight: 700; line-height: 1.3; color: rgb(var(--gray-950)); }
        .dark .cpv-manual__content h2, .dark .cpv-manual__content h3, .dark .cpv-manual__content h4 { color: #fff; }
        .cpv-manual__content h2 { font-size: 1.35rem; margin: 2.25rem 0 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid rgb(var(--gray-200)); }
        .dark .cpv-manual__content h2 { border-bottom-color: rgba(255, 255, 255, 0.1); }
        .cpv-manual__content h2:first-child { margin-top: 0; }
        .cpv-manual__content h3 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; }
        .cpv-manual__content h4 { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
        .cpv-manual__content p { margin: 0.6rem 0; }
        .cpv-manual__content ul { list-style: disc; padding-left: 1.5rem; margin: 0.6rem 0; }
        .cpv-manual__content ol { list-style: decimal; padding-left: 1.5rem; margin: 0.6rem 0; }
        .cpv-manual__content li { margin: 0.25rem 0; }
        .cpv-manual__content strong { font-weight: 700; }
        .cpv-manual__content code { font-size: 0.9em; padding: 0.1rem 0.3rem; border-radius: 0.25rem; background: rgb(var(--gray-100)); }
        .dark .cpv-manual__content code { background: rgb(var(--gray-800)); }

        .cpv-manual__content .manual-table { display: block; overflow-x: auto; border-collapse: collapse; margin: 0.75rem 0; max-width: 100%; }
        .cpv-manual__content .manual-table th, .cpv-manual__content .manual-table td { border: 1px solid rgb(var(--gray-300)); padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; min-width: 10rem; }
        .cpv-manual__content .manual-table th { background: rgb(var(--gray-100)); font-weight: 600; }
        .dark .cpv-manual__content .manual-table th, .dark .cpv-manual__content .manual-table td { border-color: rgb(var(--gray-700)); }
        .dark .cpv-manual__content .manual-table th { background: rgb(var(--gray-800)); }

        .cpv-manual__content blockquote { margin: 1rem 0; padding: 0.75rem 1rem; border-left: 4px solid rgb(var(--gray-400)); border-radius: 0.5rem; background: rgb(var(--gray-50)); }
        .dark .cpv-manual__content blockquote { background: rgb(var(--gray-800)); }
        .cpv-manual__content blockquote > :first-child { margin-top: 0; }
        .cpv-manual__content blockquote > :last-child { margin-bottom: 0; }
        .cpv-manual__content .manual-callout--importante { border-left-color: rgb(var(--danger-600)); background: rgb(var(--danger-50)); }
        .cpv-manual__content .manual-callout--consejo { border-left-color: rgb(var(--success-600)); background: rgb(var(--success-50)); }
        .cpv-manual__content .manual-callout--nota { border-left-color: rgb(var(--info-600)); background: rgb(var(--info-50)); }
        .dark .cpv-manual__content .manual-callout--importante { background: rgba(var(--danger-500), 0.15); }
        .dark .cpv-manual__content .manual-callout--consejo { background: rgba(var(--success-500), 0.15); }
        .dark .cpv-manual__content .manual-callout--nota { background: rgba(var(--info-500), 0.15); }

        .cpv-manual__content .manual-back-to-index { margin-top: 1.75rem; font-size: 0.875rem; }
    </style>

    <div class="cpv-manual">
        <div class="cpv-manual__layout">
            <nav id="{{ $tocAnchor }}" class="cpv-manual__toc" aria-labelledby="cpv-manual-toc-title">
                <h2 id="cpv-manual-toc-title" class="cpv-manual__toc-title">Contenido</h2>
                <ol>
                    @foreach ($toc as $item)
                        <li><a href="#{{ $item['id'] }}">{{ $item['title'] }}</a></li>
                    @endforeach
                </ol>
            </nav>

            <article class="cpv-manual__content" aria-label="Contenido del manual de usuario">
                {!! $manualHtml !!}
            </article>
        </div>
    </div>
</x-filament-panels::page>

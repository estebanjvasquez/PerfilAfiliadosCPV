{{--
    CPV-HOME-BRANDING-AND-LAYOUT: estilos de la página de inicio. El panel solo los inyecta en esa página
    (render hook PAGE_START con scope en App\Filament\Pages\Dashboard) y cada regla se limita a clases
    propias, para no tocar otros encabezados ni otras tarjetas. Van en línea porque el panel no compila un
    tema Tailwind propio.
--}}
<style>
    /* Logo: tamaño contenido, proporción intacta. Su fondo es transparente y el texto casi negro, así que
       en modo oscuro se apoya sobre una superficie blanca para no perder contraste. */
    .cpv-home-logo { display: inline-block; line-height: 0; border-radius: 0.75rem; }
    .cpv-home-logo-img { display: block; height: 3.5rem; width: auto; max-width: 100%; }
    @media (min-width: 640px) { .cpv-home-logo-img { height: 4.5rem; } }
    .dark .cpv-home-logo { background-color: #fff; padding: 0.5rem 0.75rem; }

    /* Avisos de veracidad: texto de lectura, no cifra de estadística. */
    .cpv-home-notice .fi-wi-stats-overview-stat-value {
        font-size: 1rem;
        line-height: 1.5rem;
        font-weight: 500;
        letter-spacing: normal;
    }
</style>

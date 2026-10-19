{{--
    CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES: la 404 de las descargas (App\Http\Controllers\EmpresaExportController).
    Es la misma para una empresa inexistente, un enlace inválido y una empresa sin acceso. No nombra la empresa ni
    el permiso, y no distingue esos casos. Solo se muestra con sesión iniciada (las rutas están detrás del login),
    así que los enlaces son los del panel.
--}}
@include('errors.partials.error-shell', [
    'title' => $title,
    'lead' => $message,
    'items' => [
        'El enlace puede estar incompleto o desactualizado.',
        'Solo puede descargar el perfil de las empresas asociadas a su usuario.',
        'Abra «Empresas» y descargue el PDF desde la empresa que le corresponde.',
        'Si el problema continúa, comuníquese con la Cámara.',
    ],
    'links' => array_filter([
        'Ir a Empresas' => \Illuminate\Support\Facades\Route::has('filament.admin.resources.empresas.index') ? route('filament.admin.resources.empresas.index') : null,
        'Ir al Inicio' => \Illuminate\Support\Facades\Route::has('filament.admin.pages.dashboard') ? route('filament.admin.pages.dashboard') : url('/'),
    ]),
])

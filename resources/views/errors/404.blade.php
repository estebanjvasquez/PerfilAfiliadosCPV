{{--
    CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES: la 404 general, en español y neutral. Puede mostrarse sin sesión y
    sin panel (por ejemplo, una ruta que no existe), así que no lee el usuario. No muestra la dirección pedida ni
    ningún detalle técnico. Los enlaces son fijos: el Inicio del panel (si no hay sesión, el panel lleva al login)
    y el login.
--}}
@include('errors.partials.error-shell', [
    'title' => 'Página no encontrada',
    'lead' => 'No encontramos la página que buscaba, o su cuenta no tiene acceso a ella.',
    'items' => [
        'Revise que la dirección esté completa.',
        'Si llegó desde un enlace guardado, puede estar desactualizado: entre de nuevo desde el menú.',
        'Si el problema continúa, comuníquese con la Cámara.',
    ],
    'links' => array_filter([
        'Ir al Inicio' => \Illuminate\Support\Facades\Route::has('filament.admin.pages.dashboard') ? route('filament.admin.pages.dashboard') : url('/'),
        'Iniciar sesión' => \Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : null,
    ]),
])

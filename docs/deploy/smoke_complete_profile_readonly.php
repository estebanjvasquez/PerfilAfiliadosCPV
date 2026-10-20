<?php

/**
 * Prueba de humo de SOLO LECTURA del perfil completo en PDF (CPV-COMPLETE-COMPANY-PDF, despliegue autorizado en Issue #2
 * `6286221372`, punto 5). Se ejecuta DENTRO del contenedor de la app, como www-data, por stdin:
 *
 *   docker compose exec -T -u www-data app php < docs/deploy/smoke_complete_profile_readonly.php
 *
 * No toca ninguna empresa, usuario ni archivo: lee la configuración cargada del panel, las acciones de Empresas, la lista
 * blanca de secciones, la vista compilada y el manual renderizado. Nada se descarga ni se escribe (transacción pgsql READ
 * ONLY con ROLLBACK, por las dudas). La descarga autenticada de una empresa real queda para la UAT del propietario.
 */

$stdout = fopen('php://stdout', 'w');
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks) {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->instance('request', Illuminate\Http\Request::create('https://pruebas.camarapetrolera.app/', 'GET'));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    fwrite($stdout, json_encode(['checks' => [['check' => 'arranque', 'ok' => false, 'detail' => get_class($e).': '.strtok($e->getMessage(), "\n")]], 'failed' => 1])."\n");
    exit(1);
}

$db = Illuminate\Support\Facades\DB::connection('pgsql');
$opened = false;
$exit = 0;

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');

    $panel = Filament\Facades\Filament::getPanel('admin');
    $check('panel SPA activo y descargas exceptuadas', $panel->hasSpaMode() && in_array('*/descargas/empresas/*', $panel->getSpaUrlExceptions(), true), implode(',', $panel->getSpaUrlExceptions()));

    $full = App\Filament\Resources\EmpresaResource::fullProfileAction();
    $sections = App\Filament\Resources\EmpresaResource::profileSectionsAction();
    $check('acción «Perfil completo (PDF)»', $full->getName() === 'perfil_completo' && $full->getLabel() === 'Perfil completo (PDF)', (string) $full->getLabel());
    $check('acción «Elegir secciones»', $sections->getName() === 'perfil_secciones' && $sections->getLabel() === 'Elegir secciones', (string) $sections->getLabel());

    $resource = (string) file_get_contents(app_path('Filament/Resources/EmpresaResource.php'));
    $check('exportador de tabla rotulado «Exportar listado»', str_contains($resource, "->label('Exportar listado')") && str_contains($resource, 'No es el perfil completo de la empresa'));
    $check('acciones antes de las columnas', str_contains($resource, 'ActionsPosition::BeforeColumns'));
    // La declaración real era `Tables\Columns\IconColumn::make('')`; un comentario del archivo la cita sin el prefijo.
    $check('la columna vacía «PDF» ya no existe', ! str_contains($resource, "Columns\\IconColumn::make('')"));
    $check('cabecera de la ficha «Descargar perfil completo (PDF)»', str_contains((string) file_get_contents(app_path('Filament/Resources/EmpresaResource/Pages/EditEmpresa.php')), 'Descargar perfil completo (PDF)'));

    $all = array_keys(App\Support\CompanyDossier::SECTIONS);
    $check('10 secciones; por defecto, todas', count($all) === 10 && App\Support\CompanyDossier::sectionsFrom(null) === $all);
    $check('lista blanca: desconocidas ignoradas, orden del reporte', App\Support\CompanyDossier::sectionsFrom('zzz,<script>') === $all && App\Support\CompanyDossier::sectionsFrom('gestion,generales') === ['generales', 'gestion']);

    $blade = (string) file_get_contents(resource_path('views/pdf.blade.php'));
    $check('vista PDF con franja de alto fijo (PDF-01)', str_contains($blade, '.page-header .amarillo { height: 112px; overflow: hidden; }') && str_contains($blade, 'class="rif"'));
    $compiled = app('blade.compiler')->getCompiledPath(resource_path('views/pdf.blade.php'));
    $check('vista PDF compilada (view:cache)', is_file($compiled));

    $html = app(App\Support\UserManual\UserManualRenderer::class)->render()['html'];
    $start = strpos($html, 'id="descargar-perfil"');
    $section = $start === false ? '' : substr($html, $start, (strpos($html, '<h3 ', $start + 1) ?: strlen($html)) - $start);
    $missing = array_filter(['Perfil completo (PDF)', 'Elegir secciones', 'Todas las secciones (perfil completo)', 'Exportar listado', '-parcial.pdf'], fn ($t) => ! str_contains($section, $t));
    $check('manual #descargar-perfil con las etiquetas de la interfaz', $section !== '' && $missing === [], implode(', ', $missing));

    $route = app('router')->getRoutes()->getByName('filament.admin.export.pdf');
    $check('ruta PDF protegida sin cambios', $route && str_contains(implode(',', app('router')->gatherRouteMiddleware($route)), 'EnsureEmailIsVerified'));
} catch (Throwable $e) {
    $exit = 1;
    $check('excepción', false, get_class($e).': '.strtok($e->getMessage(), "\n"));
} finally {
    if ($opened) {
        $db->rollBack();
    }
}

$failed = count(array_filter($checks, fn ($c) => ! $c['ok']));
fwrite($stdout, json_encode(['checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
exit($exit === 0 && $failed === 0 ? 0 : 1);

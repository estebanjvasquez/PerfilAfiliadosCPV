<?php

namespace App\Http\Controllers;

use App\Exports\JoinViewExport;
use App\Models\Empresa;
use App\Support\CompanyDossier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`): descarga del perfil de UNA empresa, en PDF o
 * XLS.
 *
 * Las rutas viven en el panel (AdminPanelProvider::panel(), `->authenticatedRoutes()`):
 * - un invitado va al login del panel;
 * - un usuario sin verificar va a «Verifique su correo electrónico» (middleware `verified`);
 * - recién después se llega aquí.
 *
 * El id llega como texto y se valida a mano, sin route-model binding. El orden es: buscar la empresa, autorizar
 * (EmpresaPolicy::export) y SOLO ENTONCES consultar las vistas de reporte o renderizar. Una empresa inexistente,
 * un id inválido y una empresa ajena dan la MISMA respuesta 404 («Archivo no disponible»), así que no se
 * revela si existe.
 *
 * Las respuestas son privadas: `Cache-Control: private, no-store, max-age=0` y `nosniff`. Esas cabeceras
 * no invalidan copias que algún caché ya guardara antes.
 */
class EmpresaExportController extends Controller
{
    public const PRIVATE_HEADERS = [
        'Cache-Control' => 'private, no-store, max-age=0',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function pdf(Request $request, string $empresa): Response
    {
        $record = $this->authorizedEmpresa($request, $empresa);
        if (! $record) {
            return static::notAvailable($request);
        }

        // CPV-COMPLETE-COMPANY-PDF (`6270558322`): perfil completo por defecto. `?secciones=` solo elige secciones de la
        // lista blanca (CompanyDossier::sectionsFrom) y se lee DESPUÉS de autorizar: no cambia la empresa ni el acceso.
        $sections = CompanyDossier::sectionsFrom($request->query('secciones'));
        $complete = CompanyDossier::isComplete($sections);

        $pdf = Pdf::loadView('pdf', CompanyDossier::data($record, $sections))
            ->addInfo([
                'Title' => ($complete ? 'Perfil completo' : 'Perfil (secciones seleccionadas)').' — '.$record->name,
                'Creator' => 'Perfil de Afiliados CPV',
            ]);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(50, $canvas->get_height() - 40, 'Página {PAGE_NUM} de {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('Helvetica'), 8, [0.35, 0.35, 0.35]);

        $response = $pdf->download(static::filename($record, 'pdf', $complete ? '' : '-parcial'));
        $response->headers->add(self::PRIVATE_HEADERS);

        return $response;
    }

    public function xls(Request $request, string $empresa): Response
    {
        $record = $this->authorizedEmpresa($request, $empresa);
        if (! $record) {
            return static::notAvailable($request);
        }

        $response = Excel::download(new JoinViewExport((int) $record->getKey()), static::filename($record, 'xlsx'));
        $response->setPrivate();
        $response->headers->add(self::PRIVATE_HEADERS);

        return $response;
    }

    /** La empresa pedida, solo si existe Y el usuario puede descargarla. Si no, null (los dos casos igual). */
    private function authorizedEmpresa(Request $request, string $id): ?Empresa
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            return null;
        }
        $empresa = Empresa::query()->find((int) $id);

        return $empresa && Gate::forUser($request->user())->allows('export', $empresa) ? $empresa : null;
    }

    /**
     * `perfil-<rif>.pdf` (o `perfil-<rif>-parcial.pdf` con secciones elegidas), sin caracteres raros. Si no hay RIF,
     * `perfil-empresa-<id>`.
     */
    public static function filename(Empresa $empresa, string $extension, string $suffix = ''): string
    {
        $base = Str::slug((string) $empresa->rif) ?: 'empresa-'.$empresa->getKey();

        return "perfil-{$base}{$suffix}.{$extension}";
    }

    /**
     * La 404 de las descargas: la misma para una empresa inexistente, un id inválido y una empresa sin acceso.
     * No incluye el id, el nombre, el RIF, el permiso ni ningún detalle técnico.
     */
    public static function notAvailable(Request $request): Response
    {
        $title = 'Archivo no disponible';
        $message = 'No encontramos el archivo solicitado o su cuenta no tiene acceso para descargarlo.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $title, 'detail' => $message], 404, self::PRIVATE_HEADERS);
        }

        return response()->view('errors.export-not-available', ['title' => $title, 'message' => $message], 404, self::PRIVATE_HEADERS);
    }
}

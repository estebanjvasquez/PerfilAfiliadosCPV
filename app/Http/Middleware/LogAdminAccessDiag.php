<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DIAGNOSTICO TEMPORAL - eliminar despues de resolver el 403 intermitente en /admin
 * (ver conversacion: falla desde navegadores reales, nunca desde curl, sin importar
 * IP/headers replicados). Loguea la peticion cruda tal como la ve Laravel, para
 * comparar un caso que falla contra uno que funciona con datos reales.
 */
class LogAdminAccessDiag
{
    public function handle(Request $request, Closure $next)
    {
        $isAdmin = str_starts_with(ltrim($request->path(), '/'), 'admin');

        if ($isAdmin) {
            Log::info('DIAG_ADMIN_REQUEST', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'ips_chain' => $request->ips(),
                'scheme' => $request->getScheme(),
                'secure' => $request->secure(),
                'server_HTTPS' => $request->server('HTTPS'),
                'server_HTTP_X_FORWARDED_FOR' => $request->server('HTTP_X_FORWARDED_FOR'),
                'server_HTTP_CF_CONNECTING_IP' => $request->server('HTTP_CF_CONNECTING_IP'),
                'server_HTTP_CF_RAY' => $request->server('HTTP_CF_RAY'),
                'headers' => $request->headers->all(),
            ]);
        }

        $response = $next($request);

        if ($isAdmin) {
            Log::info('DIAG_ADMIN_RESPONSE', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}

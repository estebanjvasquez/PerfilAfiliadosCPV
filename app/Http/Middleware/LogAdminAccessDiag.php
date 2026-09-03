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
            $sessionData = null;
            $authInfo = null;
            try {
                if ($request->hasSession()) {
                    $sessionData = $request->session()->all();
                }
                $authInfo = [
                    'check' => auth()->check(),
                    'id' => auth()->id(),
                    'guard_default' => config('auth.defaults.guard'),
                ];
            } catch (\Throwable $e) {
                $authInfo = ['error' => get_class($e) . ': ' . $e->getMessage()];
            }

            Log::info('DIAG_ADMIN_RESPONSE', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'session' => $sessionData,
                'auth' => $authInfo,
                'exception' => $response->exception ? [
                    'class' => get_class($response->exception),
                    'message' => $response->exception->getMessage(),
                    'file' => $response->exception->getFile(),
                    'line' => $response->exception->getLine(),
                    'trace' => collect($response->exception->getTrace())->take(15)->map(fn ($t) => ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? '') . ' @ ' . ($t['file'] ?? '') . ':' . ($t['line'] ?? ''))->all(),
                ] : null,
            ]);
        }

        return $response;
    }
}

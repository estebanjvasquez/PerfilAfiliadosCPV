<?php

namespace App\Support\Http;

use Illuminate\Routing\Route;

/**
 * TASK-0018 / SEC-03 P1 (F-SEC03-01/02): el origen de la aplicación sale de la configuración (APP_URL), nunca de la
 * petición. Lo usan el generador de URLs (AppServiceProvider), TrustHosts y la redirección a https.
 */
final class CanonicalUrl
{
    /** Raíz configurada, sin barra final (puede incluir puerto y ruta base). Null si APP_URL no es una URL. */
    public static function root(): ?string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            ? $url
            : null;
    }

    public static function host(): ?string
    {
        $root = self::root();

        return $root === null ? null : strtolower((string) parse_url($root, PHP_URL_HOST));
    }

    /**
     * Para URL::formatHostUsing: cambia el origen que el generador tomó de la petición por el de APP_URL.
     *
     * - Si APP_URL es https, el resultado es https siempre. Si es http (local, pruebas), se conserva el esquema que ya
     *   traía la raíz: una petición segura nunca baja a http.
     * - Una ruta con dominio propio (p. ej. el panel con FILAMENT_DOMAIN) conserva ese dominio, que viene de la
     *   configuración y no de la petición.
     */
    public static function formatRoot(string $root, mixed $route = null): string
    {
        $canonical = self::root();
        if ($canonical === null || ($route instanceof Route && filled($route->getDomain()))) {
            return $root;
        }

        $scheme = str_starts_with(strtolower($canonical), 'https://') || str_starts_with(strtolower($root), 'https://')
            ? 'https'
            : 'http';

        return $scheme.'://'.preg_replace('~^https?://~i', '', $canonical);
    }

    /**
     * URL https con el host canónico y la ruta pedida, para redirigir sin usar el Host de la petición. Solo host y
     * puerto de APP_URL: `$requestUri` (getRequestUri) ya trae la ruta base. Null si APP_URL no es válida.
     */
    public static function secure(string $requestUri): ?string
    {
        $root = self::root();
        if ($root === null) {
            return null;
        }
        $port = parse_url($root, PHP_URL_PORT);
        $scheme = strtolower((string) parse_url($root, PHP_URL_SCHEME));
        // Un puerto explícito de APP_URL http no vale para https; uno de APP_URL https sí.
        $authority = self::host().($port !== null && $scheme === 'https' ? ':'.$port : '');

        return 'https://'.$authority.'/'.ltrim($requestUri, '/');
    }
}

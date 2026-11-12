<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * TASK-0018 / SEC-03 P2 (F-SEC03-03): la IP real del cliente detrás de Cloudflare → cloudflared → nginx.
 *
 * PHP ve todas las peticiones desde el gateway de Docker. Antes no se confiaba en ningún proxy, así que todos los
 * clientes compartían esa IP y los límites «por IP» (login, recuperación, reset) eran globales.
 *
 * - Solo se confía en los pares de `security.proxies.trusted` (por defecto loopback y el gateway exacto de la red de la
 *   aplicación; corrección de revisión 6819392897: antes, todo 172.16.0.0/12), nunca en `*`. Desde cualquier otro par,
 *   las cabeceras reenviadas se ignoran, como antes.
 * - Desde un par de confianza, la IP del cliente es la de `security.proxies.client_ip_header` (CF-Connecting-IP, que
 *   Cloudflare reescribe siempre): se copia a X-Forwarded-For y se descarta lo que el cliente haya puesto ahí. Si la
 *   cabecera falta o no es una IP, la petición conserva la IP del par.
 * - Solo se acepta X-Forwarded-For (y X-Forwarded-Proto si la redirección a https está encendida). X-Forwarded-Host se
 *   elimina siempre: el host de los enlaces lo fija P1 (CanonicalUrl), nunca una cabecera (gate técnico de P2).
 */
class TrustProxies extends Middleware
{
    /** @var int */
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    public function handle(Request $request, Closure $next)
    {
        $request->headers->remove('X-Forwarded-Host');
        $this->takeClientIpFromTheEdge($request);

        return parent::handle($request, $next);
    }

    protected function headers()
    {
        $headers = Request::HEADER_X_FORWARDED_FOR;
        if (config('security.https.redirect')) {
            $headers |= Request::HEADER_X_FORWARDED_PROTO;
        }

        return static::$alwaysTrustHeaders ?: $headers;
    }

    protected function proxies()
    {
        return static::$alwaysTrustProxies ?: $this->configuredProxies();
    }

    /** @return array<int, string> */
    protected function configuredProxies(): array
    {
        $value = config('security.proxies.trusted');
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(fn ($entry) => trim((string) $entry), $list),
            fn (string $entry) => $entry !== '' && ! in_array(strtolower($entry), ['none', '*', '**'], true),
        ));
    }

    private function takeClientIpFromTheEdge(Request $request): void
    {
        $header = trim((string) config('security.proxies.client_ip_header'));
        $peer = (string) $request->server->get('REMOTE_ADDR');
        $proxies = $this->configuredProxies();

        if ($header === '' || $proxies === [] || $peer === '' || ! IpUtils::checkIp($peer, $proxies)) {
            return;
        }

        $client = trim((string) $request->headers->get($header));
        if (filter_var($client, FILTER_VALIDATE_IP)) {
            $request->headers->set('X-Forwarded-For', $client);
        } else {
            $request->headers->remove('X-Forwarded-For');
        }
    }
}

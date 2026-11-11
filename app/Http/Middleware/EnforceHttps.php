<?php

namespace App\Http\Middleware;

use App\Support\Http\CanonicalUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-0018 / SEC-03 P5 (F-SEC03-06): transporte. Ver `config/security.php` (`https`).
 *
 * - Redirección a https (apagada por defecto): solo si un proxy de confianza dice explícitamente
 *   `X-Forwarded-Proto: http`. Sin esa cabecera no se redirige nunca, así que no puede haber bucle detrás de nginx
 *   (que marca toda petición como https). El destino usa el host canónico, no el de la petición.
 * - HSTS: en respuestas https, con `max-age` configurable; sin `includeSubDomains` ni `preload` salvo que se activen a
 *   propósito. No se envía en APP_ENV=local.
 */
class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        if ($this->arrivedOverPlainHttp($request) && ($target = CanonicalUrl::secure($request->getRequestUri())) !== null) {
            return redirect()->away($target, in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308);
        }

        $response = $next($request);

        $maxAge = (int) config('security.https.hsts_max_age');
        if ($maxAge > 0 && $request->isSecure()) {
            $value = 'max-age='.$maxAge;
            if (config('security.https.hsts_include_subdomains')) {
                $value .= '; includeSubDomains';
            }
            if (config('security.https.hsts_preload')) {
                $value .= '; preload';
            }
            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }

    private function arrivedOverPlainHttp(Request $request): bool
    {
        return (bool) config('security.https.redirect')
            && $request->isFromTrustedProxy()
            && strtolower(trim((string) $request->headers->get('X-Forwarded-Proto'))) === 'http'
            && ! $request->isSecure();
    }
}

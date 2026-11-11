<?php

namespace App\Http\Middleware;

use App\Support\Http\CanonicalUrl;
use Filament\Facades\Filament;
use Illuminate\Http\Middleware\TrustHosts as Middleware;

/**
 * TASK-0018 / SEC-03 P1 (F-SEC03-02): solo se atienden los hosts exactos de la aplicación (APP_URL, el dominio del panel
 * si lo hay y `security.trusted_hosts.extra`). Cualquier otro Host da 404 (Symfony lanza SuspiciousOperationException y
 * Laravel la convierte en NotFoundHttpException). Sin subdominios ni comodines.
 *
 * A diferencia del middleware de Laravel, también rige en las pruebas, para que se puedan probar; no en APP_ENV=local.
 */
class TrustHosts extends Middleware
{
    /**
     * @return array<int, string>
     */
    public function hosts()
    {
        $hosts = [CanonicalUrl::host()];

        foreach (Filament::getPanels() as $panel) {
            foreach ($panel->getDomains() as $domain) {
                $hosts[] = $domain;
            }
        }

        foreach (explode(',', (string) config('security.trusted_hosts.extra')) as $extra) {
            $hosts[] = $extra;
        }

        return collect($hosts)
            ->map(fn ($host) => strtolower(trim((string) $host)))
            ->filter(fn (string $host) => $host !== '' && ! str_contains($host, '*'))
            ->unique()
            ->map(fn (string $host) => '^'.preg_quote($host).'$')
            ->values()
            ->all();
    }

    protected function shouldSpecifyTrustedHosts()
    {
        return (bool) config('security.trusted_hosts.enforce') && ! $this->app->environment('local');
    }
}

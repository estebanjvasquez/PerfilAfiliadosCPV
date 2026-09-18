<?php

namespace App\Services\Taxonomy;

use Illuminate\Support\Facades\Http;

/**
 * TAXV2-12/13 (regla 1 de la sección 5 del documento de instrucciones: "revisar robots.txt antes
 * de crawlear"). Parser mínimo escrito a mano a propósito (ver el plan de esta fase: "sin
 * dependencia nueva") - soporta `User-agent`, `Disallow`, `Allow` (con comodín `*` y ancla `$`,
 * sintaxis estándar de robots.txt) y `Crawl-delay`, resolviendo por el grupo de user-agent que
 * coincida exacto o cayendo a `*`.
 *
 * Cachea el robots.txt ya parseado por host durante la vida de la instancia (normalmente 1 por
 * invocación del comando) para no volver a pedirlo por cada URL del lote.
 */
class RobotsTxtChecker
{
    /** @var array<string, ?string> robots.txt crudo por host (null = no accesible) */
    private array $bodyCache = [];

    public function isAllowed(string $url, string $userAgent = '*'): bool
    {
        $rules = $this->rulesFor($url, $userAgent);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            $path .= '?'.$query;
        }

        $longestAllow = $this->longestMatch($rules['allow'], $path);
        $longestDisallow = $this->longestMatch($rules['disallow'], $path);

        if ($longestDisallow === null) {
            return true;
        }

        return $longestAllow !== null && $longestAllow >= $longestDisallow;
    }

    public function crawlDelaySeconds(string $url, string $userAgent = '*'): ?float
    {
        return $this->rulesFor($url, $userAgent)['crawlDelay'];
    }

    /**
     * @return array{disallow: string[], allow: string[], crawlDelay: ?float}
     */
    private function rulesFor(string $url, string $userAgent): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($url, PHP_URL_PORT);
        $key = $scheme.'://'.$host.($port ? ':'.$port : '');

        if (! array_key_exists($key, $this->bodyCache)) {
            $this->bodyCache[$key] = $this->fetchBody($key.'/robots.txt');
        }

        $body = $this->bodyCache[$key];
        if ($body === null) {
            // Sin robots.txt accesible (timeout/DNS/HTTP no-2xx) no se bloquea el crawling por eso
            // solo - pero tampoco se asume vía libre para siempre: el comando decide qué hacer.
            return ['disallow' => [], 'allow' => [], 'crawlDelay' => null];
        }

        // El cuerpo crudo (no el resultado ya resuelto) es lo que se cachea por host: distintos
        // user-agents pueden pedir reglas del MISMO host y cada uno debe resolver su propio grupo -
        // cachear el resultado ya resuelto para el primer user-agent que preguntó filtraría
        // silenciosamente las reglas de cualquier otro user-agent contra ese mismo host.
        return $this->parse($body, $userAgent);
    }

    private function fetchBody(string $robotsUrl): ?string
    {
        try {
            $response = Http::timeout(10)->get($robotsUrl);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * @return array{disallow: string[], allow: string[], crawlDelay: ?float}
     */
    public function parse(string $content, string $userAgent = '*'): array
    {
        /** @var array<string, array{disallow: string[], allow: string[], crawlDelay: ?float}> $groups */
        $groups = [];
        $currentUas = [];
        $sawRuleSinceUa = true;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($sawRuleSinceUa) {
                    $currentUas = [];
                }
                $ua = strtolower($value);
                $currentUas[] = $ua;
                $groups[$ua] ??= ['disallow' => [], 'allow' => [], 'crawlDelay' => null];
                $sawRuleSinceUa = false;

                continue;
            }

            if ($currentUas === []) {
                continue;
            }

            $sawRuleSinceUa = true;

            foreach ($currentUas as $ua) {
                if ($field === 'disallow' && $value !== '') {
                    $groups[$ua]['disallow'][] = $value;
                } elseif ($field === 'allow' && $value !== '') {
                    $groups[$ua]['allow'][] = $value;
                } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                    $groups[$ua]['crawlDelay'] = (float) $value;
                }
            }
        }

        $uaLower = strtolower($userAgent);

        return $groups[$uaLower] ?? $groups['*'] ?? ['disallow' => [], 'allow' => [], 'crawlDelay' => null];
    }

    private function longestMatch(array $patterns, string $path): ?int
    {
        $best = null;

        foreach ($patterns as $pattern) {
            if ($this->matches($pattern, $path)) {
                $len = strlen($pattern);
                if ($best === null || $len > $best) {
                    $best = $len;
                }
            }
        }

        return $best;
    }

    private function matches(string $pattern, string $path): bool
    {
        if ($pattern === '/' || $pattern === '') {
            return true;
        }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $regex);
        $regex = str_replace('\$', '$', $regex);

        return (bool) preg_match('#^'.$regex.'#', $path);
    }
}

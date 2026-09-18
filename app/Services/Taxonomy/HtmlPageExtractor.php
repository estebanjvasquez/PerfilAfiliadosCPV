<?php

namespace App\Services\Taxonomy;

use DOMDocument;
use DOMXPath;

/**
 * TAXV2-12/13: extracción genérica desde HTML - sin dependencia nueva (`DOMDocument` es parte del
 * PHP core). A propósito NO conoce la estructura particular de SLB/OSHA/IADC ni de ninguna web de
 * empresa: reconoce patrones comunes de glosario (`<dl><dt>/<dd>`, "**Término**: definición") y deja
 * que la revisión humana (`taxonomy_candidate_terms`/`company_term_matches`) filtre el ruido -
 * mantener esto genérico evita construir un scraper frágil por sitio que se rompa con cualquier
 * rediseño de la página (ver ajuste de riesgo del plan: no introducir piezas que fallen solas).
 */
class HtmlPageExtractor
{
    private const MAX_TERMS_PER_PAGE = 40;

    /**
     * @return array<int, array{term: string, definition: string}>
     */
    public function extractGlossaryTerms(string $html, int $maxTerms = self::MAX_TERMS_PER_PAGE): array
    {
        $dom = $this->loadHtml($html);
        if (! $dom) {
            return [];
        }

        $results = [];

        foreach ($dom->getElementsByTagName('dt') as $dt) {
            if (count($results) >= $maxTerms) {
                break;
            }

            $dd = $dt->nextSibling;
            while ($dd && $dd->nodeType !== XML_ELEMENT_NODE) {
                $dd = $dd->nextSibling;
            }
            if (! $dd || strtolower($dd->nodeName) !== 'dd') {
                continue;
            }

            $term = $this->cleanText($dt->textContent);
            $definition = $this->cleanText($dd->textContent);

            if ($this->isPlausibleTerm($term) && $this->isPlausibleDefinition($definition)) {
                $results[] = ['term' => $term, 'definition' => $definition];
            }
        }

        if (count($results) >= $maxTerms) {
            return $results;
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//strong | //b') as $bold) {
            if (count($results) >= $maxTerms) {
                break;
            }

            $term = $this->cleanText($bold->textContent);
            if (! $this->isPlausibleTerm($term)) {
                continue;
            }

            $parent = $bold->parentNode;
            if (! $parent) {
                continue;
            }

            $siblingText = '';
            $found = false;
            foreach ($parent->childNodes as $child) {
                if ($child === $bold) {
                    $found = true;

                    continue;
                }
                if ($found) {
                    $siblingText .= $child->textContent;
                }
            }

            $definition = $this->cleanText((string) preg_replace('/^[\s:\x{2013}\x{2014}-]+/u', '', $siblingText));

            if ($this->isPlausibleDefinition($definition)) {
                $results[] = ['term' => $term, 'definition' => $definition];
            }
        }

        return $results;
    }

    /**
     * @return string[] URLs absolutas, solo del mismo host que $baseUrl.
     */
    public function extractSameHostLinks(string $html, string $baseUrl): array
    {
        $dom = $this->loadHtml($html);
        if (! $dom) {
            return [];
        }

        $baseOrigin = $this->originOf($baseUrl);
        $links = [];

        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')
                || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')) {
                continue;
            }

            $absolute = $this->resolveUrl($href, $baseUrl);
            if (! $absolute) {
                continue;
            }

            if ($this->originOf($absolute) !== $baseOrigin) {
                continue;
            }

            $normalized = $this->normalizeUrl($absolute);
            if ($normalized) {
                $links[$normalized] = true;
            }
        }

        return array_keys($links);
    }

    /**
     * Normaliza a `scheme://host[:puerto]/path` (sin query/fragment), garantizando SIEMPRE un path
     * de al menos `/`. Único punto que decide esta forma canónica - usado tanto para los links que
     * se descubren en una página como para la URL semilla de ambos comandos (`CrawlTaxonomySource`/
     * `CrawlCompanyWebsite`).
     *
     * Bug real encontrado al verificar TAXV2-13 contra una empresa real: la URL semilla
     * ("https://www.geohidra.com", sin barra final) y un self-link de esa misma home
     * ("https://www.geohidra.com/", con barra final) se trataban como 2 URLs distintas -
     * duplicando el fetch de la misma página y su evidencia. Forzar acá el mismo path por defecto
     * (`/`) en ambos casos elimina la clase de bug completa, en vez de parchear un solo call site.
     */
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        return $scheme.'://'.$host.$path;
    }

    /**
     * `scheme://host[:puerto]` de una URL - "mismo host" para efectos de este crawler significa
     * mismo origen completo, puerto incluido (relevante sobre todo para pruebas locales contra un
     * servidor de fixtures en un puerto no estándar; en sitios reales casi siempre es 80/443).
     */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme.'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public function extractTitle(string $html): ?string
    {
        $dom = $this->loadHtml($html);
        if (! $dom) {
            return null;
        }

        $titles = $dom->getElementsByTagName('title');

        return $titles->length > 0 ? $this->cleanText($titles->item(0)->textContent) : null;
    }

    public function extractVisibleText(string $html): string
    {
        $dom = $this->loadHtml($html);
        if (! $dom) {
            return '';
        }

        foreach (['script', 'style', 'noscript'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        // Solo el texto de <body>: el documento completo incluye <head> (<title>, <meta>...), que
        // no es contenido visible de la página y contaminaría el matching de términos (TAXV2-13)
        // con texto que un visitante real nunca ve en pantalla.
        $body = $dom->getElementsByTagName('body')->item(0);

        return $this->cleanText(($body ?? $dom)->textContent ?? '');
    }

    private function loadHtml(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $dom : null;
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $baseParts = parse_url($base);
        if (! $baseParts || empty($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath).'/');

        return $scheme.'://'.$host.$dir.$href;
    }

    private function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function isPlausibleTerm(string $term): bool
    {
        $len = mb_strlen($term);

        return $len >= 2 && $len <= 80 && ! preg_match('/^\d+$/', $term);
    }

    private function isPlausibleDefinition(string $definition): bool
    {
        $len = mb_strlen($definition);

        return $len >= 10 && $len <= 2000;
    }
}

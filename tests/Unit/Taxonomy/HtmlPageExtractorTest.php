<?php

namespace Tests\Unit\Taxonomy;

use App\Services\Taxonomy\HtmlPageExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HtmlPageExtractorTest extends TestCase
{
    #[Test]
    public function extracts_terms_from_definition_lists(): void
    {
        $html = '<html><body><dl>
            <dt>Wellhead</dt><dd>The component at the surface of an oil or gas well that provides the structural and pressure-containing interface.</dd>
            <dt>BOP</dt><dd>Blowout preventer, a large valve used to seal an oil or gas well.</dd>
        </dl></body></html>';

        $terms = (new HtmlPageExtractor())->extractGlossaryTerms($html);

        $this->assertCount(2, $terms);
        $this->assertSame('Wellhead', $terms[0]['term']);
        $this->assertStringContainsString('structural and pressure-containing interface', $terms[0]['definition']);
        $this->assertSame('BOP', $terms[1]['term']);
    }

    #[Test]
    public function extracts_terms_from_bold_definition_pattern(): void
    {
        $html = '<html><body><p><strong>Christmas tree</strong>: an assembly of valves used to control the flow of oil and gas out of a well.</p></body></html>';

        $terms = (new HtmlPageExtractor())->extractGlossaryTerms($html);

        $this->assertCount(1, $terms);
        $this->assertSame('Christmas tree', $terms[0]['term']);
        $this->assertStringContainsString('assembly of valves', $terms[0]['definition']);
    }

    #[Test]
    public function rejects_implausible_terms_and_definitions(): void
    {
        $html = '<html><body><dl>
            <dt>42</dt><dd>Solo un número, no un término real, con definición larga para pasar el filtro de longitud.</dd>
            <dt>OK</dt><dd>Muy corta</dd>
        </dl></body></html>';

        $terms = (new HtmlPageExtractor())->extractGlossaryTerms($html);

        $this->assertCount(0, $terms);
    }

    #[Test]
    public function extracts_only_same_host_links_and_normalizes_them(): void
    {
        $html = '<html><body>
            <a href="/about">About</a>
            <a href="https://example.test/services?ref=1#top">Services</a>
            <a href="https://other-domain.test/page">External</a>
            <a href="mailto:test@example.test">Mail</a>
            <a href="#section">Anchor</a>
        </body></html>';

        $links = (new HtmlPageExtractor())->extractSameHostLinks($html, 'https://example.test/home');

        $this->assertContains('https://example.test/about', $links);
        $this->assertContains('https://example.test/services', $links);
        $this->assertNotContains('https://other-domain.test/page', $links);
        $this->assertCount(2, $links);
    }

    #[Test]
    public function resolves_relative_links_against_the_current_path(): void
    {
        $html = '<a href="equipment.html">Equipment</a>';

        $links = (new HtmlPageExtractor())->extractSameHostLinks($html, 'https://example.test/products/index.html');

        $this->assertSame(['https://example.test/products/equipment.html'], $links);
    }

    #[Test]
    public function preserves_a_non_default_port_when_resolving_absolute_path_links(): void
    {
        // Bug real encontrado al verificar TAXV2-12 contra un servidor de fixtures local en un
        // puerto no estándar: la resolución de un href absoluto ("/x") perdía el ":8971" del host
        // base, generando una URL que apuntaba a un servidor/puerto distinto.
        $html = '<a href="/term1.html">Term 1</a>';

        $links = (new HtmlPageExtractor())->extractSameHostLinks($html, 'http://127.0.0.1:8971/index.html');

        $this->assertSame(['http://127.0.0.1:8971/term1.html'], $links);
    }

    #[Test]
    public function does_not_treat_a_different_port_on_the_same_host_as_same_origin(): void
    {
        $html = '<a href="http://127.0.0.1:9999/other.html">Other</a>';

        $links = (new HtmlPageExtractor())->extractSameHostLinks($html, 'http://127.0.0.1:8971/index.html');

        $this->assertSame([], $links);
    }

    #[Test]
    public function normalize_url_always_defaults_to_a_root_path(): void
    {
        // Bug real (TAXV2-13, empresa real): la URL semilla sin barra final y un self-link "/" de
        // esa misma home se trataban como 2 páginas distintas, duplicando el fetch y la evidencia.
        $extractor = new HtmlPageExtractor();

        $this->assertSame('https://example.test/', $extractor->normalizeUrl('https://example.test'));
        $this->assertSame('https://example.test/', $extractor->normalizeUrl('https://example.test/'));
        $this->assertSame('https://example.test/about', $extractor->normalizeUrl('https://example.test/about?x=1#y'));
    }

    #[Test]
    public function same_host_links_resolve_to_the_same_normalized_form_as_the_bare_seed_url(): void
    {
        $extractor = new HtmlPageExtractor();
        $seed = $extractor->normalizeUrl('https://example.test');

        $links = $extractor->extractSameHostLinks('<a href="/">Home</a>', $seed);

        $this->assertSame([$seed], $links);
    }

    #[Test]
    public function extracts_title_and_strips_scripts_from_visible_text(): void
    {
        $html = '<html><head><title>  Acme   Oilfield  Services </title></head>
            <body><script>var x = 1;</script><p>Hello world</p></body></html>';

        $extractor = new HtmlPageExtractor();

        $this->assertSame('Acme Oilfield Services', $extractor->extractTitle($html));
        $this->assertSame('Hello world', $extractor->extractVisibleText($html));
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Support\UserManual\UserManualRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0010B: contenido del manual de usuario en línea. Estos tests no tocan la base de datos (el
 * renderer solo lee `resources/manual/manual_usuario.md`), por eso no usan `DatabaseTransactions`.
 *
 * Además de verificar que cada sección exigida exista, varios tests cruzan las etiquetas citadas
 * en el manual contra el código real de la UI (`TaxonomyCategoriesRelationManager`), para que una
 * renombración futura de un botón rompa este test en vez de dejar el manual desactualizado.
 */
class UserManualContentTest extends TestCase
{
    /** @return array{html: string, toc: array<int, array{id: string, title: string}>} */
    private function manual(): array
    {
        return app(UserManualRenderer::class)->render();
    }

    private function plainText(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Texto de la sección cuyo título h2 tiene el id dado (hasta el próximo h2). */
    private function section(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression('/<h2 id="'.preg_quote($id, '/').'">/', $html, "Falta la sección #{$id}.");

        $start = strpos($html, '<h2 id="'.$id.'">');
        $next = strpos($html, '<h2 ', $start + 1);

        return $this->plainText(substr($html, $start, $next === false ? null : $next - $start));
    }

    private function relationManagerSource(): string
    {
        return (string) file_get_contents((new \ReflectionClass(TaxonomyCategoriesRelationManager::class))->getFileName());
    }

    #[Test]
    public function the_manual_has_all_required_sections_with_stable_anchors_in_the_table_of_contents(): void
    {
        $manual = $this->manual();

        $this->assertSame([
            'que-es',
            'ingreso-y-cuenta',
            'datos-generales',
            'taxonomia-cpv',
            'agregar-categorias',
            'principal-secundaria',
            'beneficios',
            'no-encuentro-categoria',
            'resto-del-perfil',
            'errores-frecuentes',
            'privacidad',
        ], array_column($manual['toc'], 'id'));

        foreach (['grupo-familia-categoria', 'busqueda-libre', 'explorar-familia', 'limites', 'confirmar-reclasificar-quitar', 'solicitar-revision', 'no-aplica', 'completitud'] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $manual['html'], "Falta el ancla estable #{$anchor}.");
        }

        foreach (['importante', 'consejo', 'nota'] as $type) {
            $this->assertStringContainsString('manual-callout--'.$type, $manual['html'], "Falta al menos un recuadro {$type}.");
        }
    }

    #[Test]
    public function the_manual_contains_the_cpv_taxonomy_section(): void
    {
        $text = $this->section($this->manual()['html'], 'taxonomia-cpv');

        $this->assertStringContainsString('Taxonomía CPV', $text);
        $this->assertStringContainsString('no es lo mismo que escribir palabras clave', $text);
        $this->assertStringContainsString('Categorías específicas', $text);
    }

    #[Test]
    public function the_manual_explains_group_family_and_category(): void
    {
        $text = $this->section($this->manual()['html'], 'taxonomia-cpv');

        $this->assertStringContainsString('Grupo > Familia > Categoría', $text);
        $this->assertMatchesRegularExpression('/Grupo: .*general|Grupo: el área amplia/u', $text);
        $this->assertStringContainsString('Familia: un conjunto', $text);
        $this->assertStringContainsString('Categoría: el producto, servicio o capacidad concreta', $text);
        $this->assertStringContainsString('nunca a una Familia o a un Grupo completo', $text);
    }

    #[Test]
    public function the_manual_documents_the_real_category_add_workflow(): void
    {
        $text = $this->section($this->manual()['html'], 'agregar-categorias');
        $source = $this->relationManagerSource();

        // Etiquetas que el manual cita y que DEBEN existir tal cual en la UI real.
        $labels = [
            'Buscar y agregar categoría',
            '¿Qué ofrece su empresa? (texto libre, español o inglés)',
            '¿Prefiere explorar una Familia completa en vez de buscar?',
            'Categorías de esa Familia',
            'Guardar como',
            'Elija categorías específicas, no la Familia completa',
            'Límite alcanzado',
            'Categorías agregadas',
            'Sugerida (sin confirmar)',
            'Declarada por la empresa',
            'Confirmada',
            'Confirmar',
            'Marcar como principal',
            'Marcar como secundaria',
            'Quitar',
        ];

        foreach ($labels as $label) {
            $this->assertStringContainsString($label, $text, "El manual no cita «{$label}».");
            $this->assertStringContainsString($label, $source, "«{$label}» ya no existe en la UI real: actualice el manual.");
        }

        $this->assertStringContainsString('Categorías CPV (taxonomía nueva)', $text);
        $this->assertStringContainsString("'Categorías CPV (taxonomía nueva)'", $source);
    }

    #[Test]
    public function the_manual_explains_principal_vs_secundaria_with_the_real_ui_wording(): void
    {
        $text = $this->section($this->manual()['html'], 'principal-secundaria');
        $source = $this->relationManagerSource();

        foreach (['Principal (lo que la empresa realmente hace)', 'Secundaria (servicio relacionado u ocasional)'] as $option) {
            $this->assertStringContainsString($option, $text);
            $this->assertStringContainsString($option, $source, "La opción «{$option}» cambió en la UI real: actualice el manual.");
        }

        $this->assertStringContainsString('No marque categorías que no tienen relación con su empresa', $text);
    }

    #[Test]
    public function the_manual_documents_the_category_not_found_review_request_behaviour(): void
    {
        $text = $this->section($this->manual()['html'], 'no-encuentro-categoria');

        $this->assertStringContainsString('no significa necesariamente', $text);
        $this->assertStringContainsString('Solicitar revisión', $text);
        $this->assertStringContainsString('¿Qué producto, servicio o capacidad necesita representar?', $text);
        $this->assertStringContainsString('Elija el motivo', $text);
        $this->assertStringContainsString('La Cámara puede contactarle', $text);
        $this->assertStringContainsString('revisa primero si alguna categoría existente ya cubre su necesidad', $text);
        $this->assertStringContainsString('no crea ni publica una categoría automáticamente', $text);

        // Los 6 motivos del contrato de TASK-0010A (lista cerrada).
        foreach ([
            'No encuentro una categoría que describa lo que hacemos.',
            'Las categorías que encuentro son demasiado generales.',
            'Creo que existe, pero la conozco con otro nombre o término.',
            'No estoy seguro de cuál categoría corresponde.',
            'Es una capacidad/producto/servicio especializado que no veo reflejado.',
            'Otro motivo',
        ] as $reason) {
            $this->assertStringContainsString($reason, $text);
        }
    }

    #[Test]
    public function the_benefits_section_does_not_promise_ranking_or_leads(): void
    {
        $text = $this->section($this->manual()['html'], 'beneficios');

        $this->assertStringContainsString('no garantiza una posición determinada', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(garantiza(mos)? (que|el primer|más clientes)|primer lugar|primeros resultados)\b/iu', str_replace('no garantiza', '', $text));
    }

    #[Test]
    public function the_manual_does_not_expose_internal_repo_debug_or_secret_language(): void
    {
        $markdown = (string) file_get_contents(UserManualRenderer::sourcePath());
        $text = $this->plainText($this->manual()['html']);

        $forbidden = [
            '/\b(app|docs|resources|vendor|storage|tests|audit|database|config)\/[\w.\/-]*/i' => 'ruta del repositorio',
            '/\.(php|blade|json|env|md|mjs|sql|yml)\b/i' => 'extensión de archivo interna',
            // TODO/FIXME distinguen mayúsculas: "todo" es una palabra común en español.
            '/\b(TODO|FIXME)\b/' => 'marcas de pendiente de desarrollo',
            '/\b(debug|dd\(|var_dump|stack ?trace|exception|tinker|artisan|migration|seeder)\b/i' => 'lenguaje de depuración',
            '/\b(APP_KEY|DB_PASSWORD|SECRET|api[_ -]?key|token|bearer|credential|password\s*=)\b/i' => 'secretos/credenciales',
            '/\b(TASK-\d+|Issue #\d+|orquestador|orchestrator|handoff|commit|branch|pull request|github|Playwright|Supabase|Postgres|staging|localhost|filament|livewire|laravel)\b/i' => 'nota interna de desarrollo',
            '/\b(RelationManager|TaxonomyCategorySearch|EmpresaTaxonomyCategory|self_declared|suggested|validated|es_principal|taxonomy_selection_settings)\b/' => 'identificador de código',
            '/capturas?\b|CAPTURA PENDIENTE/i' => 'referencia a capturas internas',
        ];

        foreach ([$markdown, $text] as $content) {
            foreach ($forbidden as $pattern => $why) {
                $this->assertDoesNotMatchRegularExpression($pattern, $content, "El manual contiene {$why}.");
            }
        }
    }

    #[Test]
    public function the_manual_has_no_runtime_dependency_on_docs_paths_or_assets(): void
    {
        $source = UserManualRenderer::sourcePath();

        $this->assertFileExists($source);
        $this->assertStringStartsWith(resource_path(), $source, 'El contenido debe vivir en resources/, no en docs/.');
        $this->assertStringNotContainsString(base_path('docs'), $source);

        $html = $this->manual()['html'];

        $this->assertStringNotContainsString('<img', $html, 'El manual no debe depender de imágenes.');
        $this->assertDoesNotMatchRegularExpression('/(src|href)="(?!#)[^"]*\.(png|jpe?g|gif|svg|webp|md|docx|pdf)"/i', $html);
        $this->assertDoesNotMatchRegularExpression('/href="(?!#)(?!https:\/\/)/', $html, 'Solo se permiten anclas internas o enlaces https.');

        // Toda ancla interna debe apuntar a un id existente (sin enlaces rotos).
        preg_match_all('/href="#([^"]+)"/', $html, $links);
        preg_match_all('/id="([^"]+)"/', $html, $ids);
        $known = array_merge($ids[1], [UserManualRenderer::TOC_ANCHOR]);
        foreach (array_unique($links[1]) as $anchor) {
            $this->assertContains($anchor, $known, "Enlace interno roto: #{$anchor}");
        }
    }

    #[Test]
    public function the_renderer_strips_raw_html_unsafe_links_and_images(): void
    {
        $html = app(UserManualRenderer::class)->renderMarkdown(<<<'MD'
            ## Título {#ancla-de-prueba}

            <script>alert(1)</script>

            <div onclick="alert(1)">x</div>

            [malo](javascript:alert(1)) [externo](https://example.org/x)

            ![captura](docs/manual_usuario/capturas/01-login.png)

            > **IMPORTANTE:** recuadro
            MD)['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<h2 id="ancla-de-prueba">Título</h2>', $html);
        $this->assertMatchesRegularExpression('/<a (?=[^>]*href="https:\/\/example\.org\/x")(?=[^>]*rel="[^"]*noopener[^"]*")[^>]*>/', $html);
        $this->assertStringNotContainsString('<p></p>', $html, 'Un párrafo que solo tenía una imagen no debe quedar vacío.');
        $this->assertStringContainsString('class="manual-callout manual-callout--importante"', $html);
    }
}

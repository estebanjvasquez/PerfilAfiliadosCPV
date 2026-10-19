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

    /**
     * Texto de la sección cuyo título (h2, h3 o h4) tiene el id dado, hasta el próximo título del
     * mismo nivel o superior. Desde que el manual se ordena por módulo del sistema, las partes de la
     * taxonomía son subsecciones (h3) de «Categorías CPV».
     */
    private function section(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression('/<h([234]) id="'.preg_quote($id, '/').'">/', $html, "Falta la sección #{$id}.");
        preg_match('/<h([234]) id="'.preg_quote($id, '/').'">/', $html, $m, PREG_OFFSET_CAPTURE);
        $level = (int) $m[1][0];
        $start = $m[0][1];

        $next = preg_match('/<h[2-'.$level.'][ >]/', $html, $n, PREG_OFFSET_CAPTURE, $start + 1) ? $n[0][1] : null;

        return $this->plainText(substr($html, $start, $next === null ? null : $next - $start));
    }

    private function relationManagerSource(): string
    {
        return (string) file_get_contents((new \ReflectionClass(TaxonomyCategoriesRelationManager::class))->getFileName());
    }

    #[Test]
    public function the_manual_has_all_required_sections_with_stable_anchors_in_the_table_of_contents(): void
    {
        $manual = $this->manual();

        // El índice sigue los módulos del sistema en el orden en que aparecen en pantalla (pedido del
        // propietario tras ver el despliegue de d23c0e6): la taxonomía es UNA entrada, no la mitad.
        $this->assertSame([
            'que-es',
            'ingreso-y-cuenta',
            'escritorio',
            'datos-generales',
            'resto-del-perfil',
            'camaras',
            'contactos',
            'usuarios',
            'sectores-y-servicios',
            'taxonomia-cpv',
            'recursos',
            'sistemas-de-gestion',
            'experiencia',
            'presencia-internacional',
            'sostenibilidad',
            'completitud-y-no-aplica',
            'errores-frecuentes',
            'privacidad',
        ], array_column($manual['toc'], 'id'));

        // Cada pestaña de la empresa tiene su sección de nivel 2, en el orden real de las pestañas.
        $tabs = array_map(fn ($rm) => class_basename($rm), \App\Filament\Resources\EmpresaResource::getRelations());
        $this->assertSame([
            'ChambersRelationManager', 'ContactsRelationManager', 'UsersRelationManager', 'ServicesRelationManager',
            'TaxonomyCategoriesRelationManager', 'AssetsRelationManager', 'ManagementRelationManager',
            'ExperiencesRelationManager', 'PresenceRelationManager', 'SustainabilitiesRelationManager',
        ], $tabs, 'Cambiaron las pestañas de la empresa: actualice el orden de las secciones del manual.');

        foreach (['agregar-categorias', 'principal-secundaria', 'beneficios', 'no-encuentro-categoria', 'grupo-familia-categoria', 'busqueda-libre', 'explorar-familia', 'limites', 'confirmar-reclasificar-quitar', 'solicitar-revision', 'no-aplica', 'completitud', 'mapa-del-sistema', 'botones-ventanas', 'mapa-taxonomia', 'verificar-correo', 'descargar-perfil'] as $anchor) {
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
        // La etiqueta FINAL del campo, no una paráfrasis: al reconciliar con la TASK-0010A
        // aceptada, el paso 2 pasó a citar el label que el afiliado ve en pantalla
        // («¿Por qué solicita la revisión?, elija el motivo...»), así que buscar «Elija el motivo»
        // con mayúscula dejó de encontrar nada.
        $this->assertStringContainsString(TaxonomyCategoriesRelationManager::SOLICITUD_JUSTIFICACION_LABEL, $text);
        $this->assertStringContainsString('elija el motivo', $text);
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

    /**
     * INTEGRACIÓN: las etiquetas de TASK-0010A son FINALES, y este test las ata a su origen.
     *
     * Antes había aquí `the_provisional_label_marker_never_reaches_the_reader`, que comprobaba que
     * un comentario `PROVISIONAL-TASK-0010A` siguiera en el fuente y no llegara al HTML. Tenía
     * sentido mientras A estaba pendiente de aceptación, pero dejar para siempre un test que EXIGE
     * un comentario interno es exactamente lo que la revisión `6171023438` pidió no hacer. A quedó
     * aceptada (comentario `6172299365`), el marcador se retiró y esto lo reemplaza.
     *
     * Lo que afirma ahora es más fuerte: el manual cita las etiquetas **leídas de las propias
     * constantes de la implementación**, no cadenas copiadas a mano. Si alguien cambia una etiqueta
     * en `TaxonomyCategoriesRelationManager` sin actualizar el manual - o al revés - este test
     * falla y dice cuál. Es la reconciliación convertida en garantía comprobable en vez de en una
     * nota que alguien tiene que acordarse de leer.
     */
    #[Test]
    public function the_manual_cites_the_final_task_0010a_labels_read_from_the_implementation(): void
    {
        $html = app(UserManualRenderer::class)->render()['html'];
        $markdown = file_get_contents(resource_path('manual/manual_usuario.md'));

        // Las etiquetas que el afiliado verá en pantalla, tomadas de donde la UI las define.
        $labels = [
            'acción de la grilla' => TaxonomyCategoriesRelationManager::SOLICITUD_ACTION_LABEL,
            'título del modal' => TaxonomyCategoriesRelationManager::SOLICITUD_MODAL_HEADING,
            'campo necesidad' => TaxonomyCategoriesRelationManager::SOLICITUD_NECESIDAD_LABEL,
            'campo justificación' => TaxonomyCategoriesRelationManager::SOLICITUD_JUSTIFICACION_LABEL,
            'campo términos probados' => TaxonomyCategoriesRelationManager::SOLICITUD_TERMINOS_LABEL,
        ];

        foreach ($labels as $cual => $label) {
            $this->assertNotSame('', trim((string) $label), "La constante de la UI para {$cual} está vacía.");
            $this->assertStringContainsString(
                $label,
                $html,
                "El manual no cita la etiqueta FINAL de {$cual} («{$label}»). Si la UI cambió, hay que reconciliar la sección «No encuentro una categoría» y la fila correspondiente de «Errores y dudas frecuentes»."
            );
        }

        // El comportamiento prometido, no sólo las palabras: el manual tiene que decir que la
        // solicitud NO crea la categoría y que la Cámara revisa primero si ya existe una.
        // «no crea NI PUBLICA una categoría automáticamente» es lo que dice el manual, así que el
        // patrón no puede exigir que «crea» y «categoría» sean contiguas.
        $this->assertMatchesRegularExpression(
            '/no\s+crea\b[^.]{0,40}categor/iu',
            $html,
            'El manual tiene que decir explícitamente que la solicitud no crea una categoría.'
        );
        $this->assertMatchesRegularExpression(
            '/(revisar|revisará|revisa)[^.]{0,120}categor/iu',
            $html,
            'El manual tiene que decir que la Cámara revisa si una categoría existente cubre la necesidad.'
        );

        // Y el marcador provisional ya NO debe estar: su trabajo lo hace este test.
        $this->assertStringNotContainsString(
            'PROVISIONAL-TASK-0010A',
            $markdown,
            'TASK-0010A está aceptada: el marcador provisional sobra y debe retirarse del fuente.'
        );

        // Sigue sin filtrarse detalle interno al afiliado.
        foreach ([
            'feature/task-0010a-category-request',
            'TaxonomyCategoriesRelationManager',
            'SOLICITUD_ACTION_LABEL',
            'html_input',
        ] as $interno) {
            $this->assertStringNotContainsString(
                $interno,
                $html,
                "El manual renderizado expone detalle interno al afiliado: {$interno}"
            );
        }
    }
}

<?php

namespace App\Support\Taxonomy;

/**
 * Metadata estática de los ~22 parámetros de `admin_defaults.scoring` del JSON V2 (TAXV2-4, ver
 * docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.9). El VALOR actual de
 * cada uno vive en `taxonomy_settings` (BD); acá solo label/descripción/default/rango/ejemplo/grupo
 * para que la pantalla de administración (TaxonomyRankingSettingsPage) no tenga que hardcodear
 * texto de ayuda por campo.
 *
 * Mismo espíritu que las constantes `LEVEL_*`/`RELEVANCE_*` de `TaxonomyCategory`: una sola fuente
 * de verdad para algo que de otro modo quedaría repetido entre el form, el seeder y "restaurar
 * default".
 */
class TaxonomyRankingParameters
{
    public const GROUP_DECLARED_PROFILE = 'Perfil CPV declarado';
    public const GROUP_WEBSITE_EVIDENCE = 'Evidencia en la web';
    public const GROUP_LEGACY_SERVICES = 'Servicios legacy';
    public const GROUP_RELATION_THRESHOLDS = 'Umbrales de relación';
    public const GROUP_MATCHING = 'Matching (fuzzy/semántico)';
    public const GROUP_CONTEXT = 'Contexto y control de ruido';
    public const GROUP_RANKING = 'Pesos de ranking';
    public const GROUP_AUTO_MAPPING = 'Auto Mapper (confianza)';

    public const GROUP_MCP_CANONICAL_EXPANSION = 'MCP - Expansión canónica (CIRA)';

    public const GROUP_CONCEPT_BUILDER = 'Phase 3 - Canonical Concept Builder';

    public const GROUP_CONCEPT_RELATIONS = 'Phase B - Grafo de relaciones entre conceptos';

    /**
     * @return array<string, array{label:string, description:string, default:float, min:float, max:float, example:string, group:string}>
     */
    public static function definitions(): array
    {
        return [
            'declared_profile.category' => [
                'label' => 'Categoría CPV declarada',
                'description' => 'Fuerza que aporta que la empresa haya declarado exactamente esta categoría (nivel más específico) en su perfil.',
                'default' => 1.00, 'min' => 0.0, 'max' => 1.5,
                'example' => 'Empresa declaró "Válvulas de Cabezal de Pozo API 6A" -> puntúa con este peso pleno.',
                'group' => self::GROUP_DECLARED_PROFILE,
            ],
            'declared_profile.family' => [
                'label' => 'Familia CPV declarada',
                'description' => 'Fuerza cuando la empresa declaró la Familia (nivel intermedio) pero no una categoría hoja específica.',
                'default' => 0.90, 'min' => 0.0, 'max' => 1.5,
                'example' => 'Empresa declaró la familia "API 6A Wellhead Valves" sin elegir una categoría hoja.',
                'group' => self::GROUP_DECLARED_PROFILE,
            ],
            'declared_profile.group' => [
                'label' => 'Grupo CPV declarado',
                'description' => 'Fuerza cuando solo se conoce el Grupo (nivel más amplio) declarado por la empresa.',
                'default' => 0.70, 'min' => 0.0, 'max' => 1.5,
                'example' => 'Empresa asociada solo al grupo "Válvulas", sin familia ni categoría.',
                'group' => self::GROUP_DECLARED_PROFILE,
            ],
            'website_evidence.exact_term' => [
                'label' => 'Término exacto en la web',
                'description' => 'Peso cuando un término del diccionario aparece exacto en el sitio/perfil de la empresa.',
                'default' => 0.85, 'min' => 0.0, 'max' => 1.5,
                'example' => 'La palabra "wellhead" aparece literal en la descripción de la empresa.',
                'group' => self::GROUP_WEBSITE_EVIDENCE,
            ],
            'website_evidence.exact_alias' => [
                'label' => 'Alias exacto en la web',
                'description' => 'Peso cuando un alias/sinónimo (no el término canónico) aparece exacto.',
                'default' => 0.80, 'min' => 0.0, 'max' => 1.5,
                'example' => '"black gold" (alias de "oil") aparece literal en el texto.',
                'group' => self::GROUP_WEBSITE_EVIDENCE,
            ],
            'website_evidence.contextual_term' => [
                'label' => 'Término contextual en la web',
                'description' => 'Peso cuando el término aparece cerca de contexto de apoyo, no aislado.',
                'default' => 0.65, 'min' => 0.0, 'max' => 1.5,
                'example' => '"pump" aparece junto a "API 610", que le da contexto industrial real.',
                'group' => self::GROUP_WEBSITE_EVIDENCE,
            ],
            'website_evidence.semantic_only_min' => [
                'label' => 'Solo semántico (mínimo)',
                'description' => 'Piso del rango de peso cuando el único match es semántico (sin coincidencia léxica).',
                'default' => 0.40, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Una consulta conceptual sin ninguna palabra literal en común con el término.',
                'group' => self::GROUP_WEBSITE_EVIDENCE,
            ],
            'website_evidence.semantic_only_max' => [
                'label' => 'Solo semántico (máximo)',
                'description' => 'Techo del rango de peso cuando el único match es semántico.',
                'default' => 0.60, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Ídem, límite superior del rango.',
                'group' => self::GROUP_WEBSITE_EVIDENCE,
            ],
            'legacy_services.declared_service' => [
                'label' => 'Servicio legacy declarado',
                'description' => 'Peso cuando la empresa declaró un servicio del catálogo legacy (`services`) relacionado con el término.',
                'default' => 0.75, 'min' => 0.0, 'max' => 1.5,
                'example' => 'Empresa con el servicio "SERVICIOS DE INYECCIÓN DE GAS Y VAPOR" ya cargado.',
                'group' => self::GROUP_LEGACY_SERVICES,
            ],
            'relation_thresholds.minimum_relation_weight' => [
                'label' => 'Peso mínimo de relación',
                'description' => 'Por debajo de este peso, una relación Término/Servicio -> CPV se considera demasiado débil (queda `deprecated`, no alimenta el ranking).',
                'default' => 0.45, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Usado por los importadores (TAXV2-2/3) para el auto-triage de relaciones al cargar el JSON V2.',
                'group' => self::GROUP_RELATION_THRESHOLDS,
            ],
            'relation_thresholds.strong_relation' => [
                'label' => 'Relación fuerte',
                'description' => 'A partir de este peso, una relación se considera fuerte (informativo, no cambia el status por sí solo).',
                'default' => 0.75, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Referencia para priorizar visualmente relaciones en la cola de revisión (TAXV2-6).',
                'group' => self::GROUP_RELATION_THRESHOLDS,
            ],
            'relation_thresholds.automatic_accept' => [
                'label' => 'Aceptación automática',
                'description' => 'A partir de esta confianza, una relación se aprueba automáticamente al importar, sin pasar por revisión manual.',
                'default' => 0.90, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Usado por los importadores (TAXV2-2/3) para el auto-triage de relaciones al cargar el JSON V2.',
                'group' => self::GROUP_RELATION_THRESHOLDS,
            ],
            'matching.fuzzy_min_short_term' => [
                'label' => 'Similitud mínima (término corto)',
                'description' => 'Umbral de similitud `pg_trgm` para aceptar un match difuso sobre un término corto (más estricto, para evitar falsos positivos entre palabras cortas).',
                'default' => 0.94, 'min' => 0.0, 'max' => 1.0,
                'example' => '"gas" vs "gaz" (typo) - términos cortos necesitan más similitud para no confundirse con otra palabra corta distinta.',
                'group' => self::GROUP_MATCHING,
            ],
            'matching.fuzzy_min_long_term' => [
                'label' => 'Similitud mínima (término largo)',
                'description' => 'Umbral de similitud `pg_trgm` para un match difuso sobre un término largo.',
                'default' => 0.90, 'min' => 0.0, 'max' => 1.0,
                'example' => '"consturccion" (typo) vs "construcción".',
                'group' => self::GROUP_MATCHING,
            ],
            'matching.semantic_min_similarity' => [
                'label' => 'Similitud semántica mínima',
                'description' => 'Umbral de similitud coseno (embeddings) para aceptar un match puramente semántico.',
                'default' => 0.72, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Consulta en lenguaje natural sin coincidencia léxica con ningún término del diccionario.',
                'group' => self::GROUP_MATCHING,
            ],
            'context.default_window_words' => [
                'label' => 'Ventana de contexto (default)',
                'description' => 'Cantidad de palabras alrededor de un término a considerar como su contexto, cuando el término no trae un valor propio.',
                'default' => 80, 'min' => 10, 'max' => 300,
                'example' => 'Se usa como default de `taxonomy_terms.context_window_words` al crear un término nuevo.',
                'group' => self::GROUP_CONTEXT,
            ],
            'context.generic_term_min_supporting_terms' => [
                'label' => 'Términos de apoyo mínimos (genérico)',
                'description' => 'Para un término marcado `context_required`, cuántos otros términos de apoyo deben aparecer cerca para contar como evidencia válida.',
                'default' => 2, 'min' => 0, 'max' => 10,
                'example' => '"pump"/"valve"/"steel" solos no bastan para clasificar Oil & Gas - necesitan compañía.',
                'group' => self::GROUP_CONTEXT,
            ],
            'context.ambiguity_penalty' => [
                'label' => 'Penalización de ambigüedad (default)',
                'description' => 'Penalización aplicada por default a un término genérico sin suficiente contexto de apoyo, cuando el término no trae su propio valor.',
                'default' => 0.22, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Reemplaza en espíritu la lista hardcodeada `GENERIC_ATTRACTOR_FAMILY_CODES` de `BuildEmpresaSearchDocuments.php`.',
                'group' => self::GROUP_CONTEXT,
            ],
            'ranking.cpv_declared_boost' => [
                'label' => 'Boost por CPV declarado',
                'description' => 'Multiplicador extra cuando la empresa ya tiene declarado el CPV que matchea la consulta.',
                'default' => 1.25, 'min' => 1.0, 'max' => 3.0,
                'example' => 'Consulta "válvulas" y la empresa ya declaró un CPV de la familia Válvulas.',
                'group' => self::GROUP_RANKING,
            ],
            'ranking.crawler_evidence_boost' => [
                'label' => 'Boost por evidencia de crawler',
                'description' => 'Multiplicador cuando la evidencia viene de un match encontrado por el crawler de webs de empresas (TAXV2-13).',
                'default' => 1.00, 'min' => 1.0, 'max' => 3.0,
                'example' => 'El crawler encontró "API 6A Wellhead Equipment" en la página de Productos de la empresa.',
                'group' => self::GROUP_RANKING,
            ],
            'ranking.regional_alias_boost' => [
                'label' => 'Boost por alias regional',
                'description' => 'Multiplicador cuando el match vino de un alias/jerga regional (ej. venezolana) en vez del término canónico.',
                'default' => 1.08, 'min' => 1.0, 'max' => 3.0,
                'example' => '"arbolito" (jerga VE) resolvió a "API 6A Wellhead Valves".',
                'group' => self::GROUP_RANKING,
            ],
            'ranking.multiple_evidence_boost' => [
                'label' => 'Boost por evidencia múltiple',
                'description' => 'Multiplicador cuando más de una fuente de evidencia coincide en el mismo CPV (ej. declarado + léxico + semántico a la vez).',
                'default' => 1.12, 'min' => 1.0, 'max' => 3.0,
                'example' => 'El mismo CPV aparece por declaración Y por texto libre en el perfil.',
                'group' => self::GROUP_RANKING,
            ],
            'auto_mapping.auto_approve_confidence' => [
                'label' => 'Confianza para auto-aprobar',
                'description' => 'A partir de esta confianza, el Auto Mapper (TAXV3-3) crea la relación Término→CPV directamente como aprobada, sin revisión.',
                'default' => 0.95, 'min' => 0.80, 'max' => 1.0,
                'example' => 'Coincidencia exacta con el nombre de una categoría CPV.',
                'group' => self::GROUP_AUTO_MAPPING,
            ],
            'auto_mapping.auto_activate_confidence' => [
                'label' => 'Confianza para activar con peso conservador',
                'description' => 'Entre este valor y el de auto-aprobar, la relación también se crea aprobada pero con un peso reducido (menos certeza) - queda visible como candidata a revisión opcional, no urgente.',
                'default' => 0.85, 'min' => 0.50, 'max' => 1.0,
                'example' => 'Heredado de un concepto canónico cuyo término hermano ya está aprobado.',
                'group' => self::GROUP_AUTO_MAPPING,
            ],
            'auto_mapping.candidate_confidence_floor' => [
                'label' => 'Piso de confianza para candidato',
                'description' => 'Por debajo de "activar" pero por encima de este piso, la relación se guarda como `candidate` (no aparece en el buscador, no es una tarea urgente) en vez de descartarse.',
                'default' => 0.70, 'min' => 0.30, 'max' => 1.0,
                'example' => 'Similitud léxica moderada contra un término ya mapeado.',
                'group' => self::GROUP_AUTO_MAPPING,
            ],
            'auto_mapping.source_corrob_boost' => [
                'label' => 'Boost por corroboración de fuente',
                'description' => 'Multiplicador de CONFIANZA (no de ranking de búsqueda) cuando el término tiene bindings verificados de más de 1 fuente - más fuentes documentando lo mismo da más certeza, pero no debe inflar el ranking proporcionalmente.',
                'default' => 1.00, 'min' => 1.0, 'max' => 1.05,
                'example' => 'Un término con binding verificado en OSHA Y SLB a la vez.',
                'group' => self::GROUP_AUTO_MAPPING,
            ],
            'mcp_canonical.level0_weight' => [
                'label' => 'Peso Nivel 0 (término exacto)',
                'description' => 'Fase 23A: peso de expansión canónica cuando la consulta matchea el término exacto de `taxonomy_terms` (sin alias, sin herencia por concepto).',
                'default' => 1.00, 'min' => 0.0, 'max' => 1.5,
                'example' => '"cabria" matchea literalmente el término "cabria" en el diccionario.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.level2_weight' => [
                'label' => 'Peso Nivel 2 (concepto canónico)',
                'description' => 'Fase 23A: peso cuando el término matchea vía pertenencia a un `taxonomy_canonical_concepts`, antes de resolver a un CPV concreto.',
                'default' => 0.90, 'min' => 0.0, 'max' => 1.5,
                'example' => '"cabria" pertenece al concepto "Derrick and Mast".',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.level3_weight' => [
                'label' => 'Peso Nivel 3 (CPV directo)',
                'description' => 'Fase 23A: peso cuando la relación Término→CPV es `approved` (propia o heredada de un hermano de concepto).',
                'default' => 0.90, 'min' => 0.0, 'max' => 1.5,
                'example' => '"cabria" resuelve a CPV-28.02.01G vía relación aprobada propia o heredada.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.level4_weight' => [
                'label' => 'Peso Nivel 4 (concepto técnico relacionado - reservado)',
                'description' => 'Fase 23A: peso reservado para un futuro salto semántico/conceptual (ej. DERRICK→"drilling equipment") - NO se usa mientras `canonical_related_l4_expansion_enabled` esté en 0. Definido para no rediseñar el schema cuando se calibre.',
                'default' => 0.65, 'min' => 0.0, 'max' => 1.5,
                'example' => 'Reservado - ver `canonical_related_l4_expansion_enabled`.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.level5_fallback_penalty' => [
                'label' => 'Penalización Nivel 5 (familia, fallback)',
                'description' => 'Fase 23A: peso muy reducido para el fallback determinístico de UN solo salto por `taxonomy_categories.parent_id` (Familia inmediata), usado solo cuando el CPV directo no tiene ninguna empresa. Nunca un salto semántico.',
                'default' => 0.30, 'min' => 0.0, 'max' => 1.0,
                'example' => 'CPV-28.02.01G sin empresas -> se prueba con su Familia CPV-28.02, marcado como RELATED_CANDIDATE, no DIRECT_MATCH.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.minimum_expansion_confidence' => [
                'label' => 'Confianza mínima de expansión',
                'description' => 'Fase 23A: por debajo de este peso, un match de expansión canónica se descarta (no genera evidencia) - evita contaminación semántica de matches muy débiles.',
                'default' => 0.50, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Un match de Nivel 5 ya penalizado que caería por debajo de este piso no se usa.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.canonical_query_expansion_enabled' => [
                'label' => 'Expansión canónica activa (interruptor general)',
                'description' => 'Fase 23A: 1 = el MCP consulta la Taxonomía V3 para expandir regionalismos/conceptos canónicos antes de buscar empresas. 0 = comportamiento idéntico a antes de esta fase (rollback sin redeploy).',
                'default' => 1, 'min' => 0, 'max' => 1,
                'example' => 'Apagar en caliente si una regresión aparece en producción.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.canonical_cpv_expansion_enabled' => [
                'label' => 'Expansión por CPV directo activa (L0-L3)',
                'description' => 'Fase 23A: 1 = habilita las señales `canonical_exact`/`canonical_alias`/`canonical_cpv`. Permite aislar un problema de L0-L3 sin apagar todo.',
                'default' => 1, 'min' => 0, 'max' => 1,
                'example' => 'Apagar solo esto si el ruido viene del CPV directo, no del fallback.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.canonical_related_expansion_enabled' => [
                'label' => 'Fallback de familia activo (L5)',
                'description' => 'Fase 23A: 1 = habilita la señal `canonical_related` (un salto de familia, penalizado). Se puede apagar por separado si el fallback genera falsos positivos sin tocar L0-L3.',
                'default' => 1, 'min' => 0, 'max' => 1,
                'example' => 'Apagar si el salto de familia empieza a traer empresas sin relación real.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.canonical_related_l4_expansion_enabled' => [
                'label' => 'Expansión por concepto técnico relacionado (L4 - reservado)',
                'description' => 'Fase 23A: reservado para un futuro salto semántico (ej. DERRICK→"drilling equipment"). Debe quedar en 0 hasta que haya datos reales que justifiquen abrirlo - es exactamente el riesgo de contaminación semántica que motivó esta fase.',
                'default' => 0, 'min' => 0, 'max' => 1,
                'example' => 'No activar sin recalibrar el benchmark completo primero.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.regional_expansion_enabled' => [
                'label' => 'Expansión de regionalismos activa (L1)',
                'description' => 'Fase 23A: 1 = habilita la detección de alias/regionalismos (`taxonomy_term_aliases`, `region`) como señal de expansión, usando `ranking.regional_alias_boost` como peso.',
                'default' => 1, 'min' => 0, 'max' => 1,
                'example' => '"cabrias" (alias de "cabria") cuenta como Nivel 1.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.related_candidates_parallel_enabled' => [
                'label' => 'Relacionados en paralelo activo (evidence layering)',
                'description' => 'Fase 24, Fase 2: 0 (default) = comportamiento de Fase 23A sin cambios - `canonical_related` (Nivel 5) solo corre si el CPV directo no encontró NINGUNA empresa (fallback puro). 1 = `canonical_related` corre SIEMPRE que exista un código de familia relacionado, coexistiendo con evidencia directa - protegido por `minimum_relation_confidence`/`max_related_candidates`/`generic_relation_penalty` de abajo. Apagar vuelve al comportamiento ya validado, sin redeploy.',
                'default' => 0, 'min' => 0, 'max' => 1,
                'example' => '"cabrias" ya tiene 7 empresas directas - con esto en 1, además se evalúan (acotadas) empresas de la Familia CPV-28.02.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.minimum_relation_confidence' => [
                'label' => 'Confianza mínima para relacionados en paralelo',
                'description' => 'Fase 24, Fase 2: solo aplica cuando `related_candidates_parallel_enabled`=1. El match de Nivel 5 (familia) que originó los candidatos relacionados debe tener un weight igual o mayor a este umbral - independiente de `minimum_expansion_confidence` (que aplica a todos los niveles), un dial específico para esta señal.',
                'default' => 0.30, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Con el default de `level5_fallback_penalty` (0.30), el umbral por defecto deja pasar exactamente ese caso - subirlo exige una familia con menos penalización relativa.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.max_related_candidates' => [
                'label' => 'Tope de candidatos relacionados por frase',
                'description' => 'Fase 24, Fase 2: solo aplica cuando `related_candidates_parallel_enabled`=1. Límite duro de empresas que la señal `canonical_related` puede aportar por frase - protección directa contra explosión de recall al dejar de ser un fallback exclusivo.',
                'default' => 5, 'min' => 0, 'max' => 50,
                'example' => 'Una familia CPV amplia con 40 empresas tageadas solo aporta 5 candidatos relacionados, nunca las 40.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'mcp_canonical.generic_relation_penalty' => [
                'label' => 'Penalización adicional de la señal relacionada',
                'description' => 'Fase 24, Fase 2: multiplicador GENERAL sobre el peso RRF de la señal completa `canonical_related` (no de cada match individual, eso ya lo hace `level5_fallback_penalty`) - un segundo dial para bajar cuánto pesa TODA la señal relacionada frente a las demás, sin tocar las demás listas. 1.0 = sin penalización adicional.',
                'default' => 1.00, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Bajar a 0.5 si, tras el benchmark CURRENT vs NEW, los candidatos relacionados resultan demasiado competitivos frente a evidencia semántica genuina.',
                'group' => self::GROUP_MCP_CANONICAL_EXPANSION,
            ],
            'concept_builder.auto_accept_threshold' => [
                'label' => 'Umbral AUTO_ACCEPT',
                'description' => 'Phase 3: score compuesto mínimo para que el Builder clasifique un candidato término→concepto como AUTO_ACCEPT. Sujeto además a la regla de seguridad de corroboración mínima (ver concept_builder.min_corroborating_signals_for_auto_accept) - ninguna señal semántica sola puede alcanzar este tier.',
                'default' => 0.90, 'min' => 0.80, 'max' => 1.0,
                'example' => 'Identidad canónica exacta + solapamiento de alias + CPV compartido de categoría, todos altos a la vez.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.auto_accept_conservative_threshold' => [
                'label' => 'Umbral AUTO_ACCEPT_CONSERVATIVE',
                'description' => 'Phase 3: score compuesto mínimo para AUTO_ACCEPT_CONSERVATIVE (se crea igual, pero queda marcado para revisión opcional, no urgente).',
                'default' => 0.80, 'min' => 0.60, 'max' => 1.0,
                'example' => 'Similitud léxica alta + CPV de familia compartido, sin identidad exacta.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.review_threshold' => [
                'label' => 'Umbral REVIEW',
                'description' => 'Phase 3: score compuesto mínimo para que un candidato entre a la cola de revisión humana en vez de descartarse (REJECT).',
                'default' => 0.55, 'min' => 0.30, 'max' => 0.90,
                'example' => 'Un candidato con una sola señal moderada (ej. solo similitud léxica 0.65).',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.min_corroborating_signals_for_auto_accept' => [
                'label' => 'Señales corroborantes mínimas para AUTO_ACCEPT',
                'description' => 'Phase 3 (regla de seguridad, sección 12 del pedido): cuántas señales independientes deben superar su propio umbral de corroboración para que un candidato pueda llegar a AUTO_ACCEPT/AUTO_ACCEPT_CONSERVATIVE - ninguna señal individual (pg_trgm, embedding, CPV compartido) puede producir ese tier por sí sola.',
                'default' => 2, 'min' => 1, 'max' => 5,
                'example' => 'pg_trgm alto por sí solo nunca basta; pg_trgm alto + CPV de categoría compartido sí corrobora.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.corroborating_signal_threshold' => [
                'label' => 'Umbral de señal corroborante',
                'description' => 'Phase 3: valor mínimo (0..1) que una señal individual del score compuesto debe alcanzar para contar hacia concept_builder.min_corroborating_signals_for_auto_accept.',
                'default' => 0.60, 'min' => 0.0, 'max' => 1.0,
                'example' => 'lexical_similarity=0.72 cuenta como corroborante; lexical_similarity=0.40 no.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_exact_canonical_signal' => [
                'label' => 'Peso: identidad canónica exacta',
                'description' => 'Phase 3: peso del signal exact_canonical_signal en el score compuesto (los 10 pesos de este grupo sin contar la penalización de intención suman 1.0).',
                'default' => 0.20, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Mismo canonical_term normalizado que un término ya vinculado al concepto.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_lexical_similarity' => [
                'label' => 'Peso: similitud léxica',
                'description' => 'Phase 3: peso del signal lexical_similarity (pg_trgm) en el score compuesto.',
                'default' => 0.12, 'min' => 0.0, 'max' => 1.0,
                'example' => '"gas natural" vs concepto "natural gas".',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_alias_overlap' => [
                'label' => 'Peso: solapamiento de alias',
                'description' => 'Phase 3: peso del signal alias_overlap (taxonomy_term_aliases compartidos con miembros del concepto).',
                'default' => 0.10, 'min' => 0.0, 'max' => 1.0,
                'example' => 'El candidato comparte un alias registrado con un término ya vinculado.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_shared_cpv' => [
                'label' => 'Peso: CPV compartido',
                'description' => 'Phase 3: peso del signal shared_cpv - si el candidato y algún miembro del concepto apuntan al mismo nodo CPV (o un ancestro común). NO implica identidad por sí solo (sección 11 del pedido).',
                'default' => 0.12, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Candidato y miembro del concepto ambos mapeados a CPV-28.02.01G.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_cpv_specificity' => [
                'label' => 'Peso: especificidad del CPV compartido',
                'description' => 'Phase 3: peso del signal cpv_specificity - CATEGORY > FAMILY > GROUP (sección 11 del pedido); un CPV compartido a nivel de Categoría pesa más que uno compartido solo a nivel de Grupo.',
                'default' => 0.08, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Compartir la Categoría hoja puntúa 1.0; compartir solo el Grupo raíz puntúa 0.0.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_cpv_mapping_quality' => [
                'label' => 'Peso: calidad del mapping CPV',
                'description' => 'Phase 3: peso del signal cpv_mapping_quality - pondera el shared_cpv por el relation_type/confidence/weight/status reales de las relaciones término→CPV involucradas (ver concept_builder.mapping_quality_*). Un anchor `contextual` de baja confianza no puede pesar igual que uno `exact`/`approved`.',
                'default' => 0.08, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Dos relaciones `exact`/`approved` sólidas puntúan mucho más que dos `contextual`/`candidate`.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_legacy_service_overlap' => [
                'label' => 'Peso: solapamiento de servicios legacy',
                'description' => 'Phase 3: peso del signal legacy_service_overlap (taxonomy_term_service_relations compartidas).',
                'default' => 0.08, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Candidato y miembro del concepto comparten el mismo servicio legacy declarado.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_embedding_similarity' => [
                'label' => 'Peso: similitud de embedding',
                'description' => 'Phase 3: peso del signal embedding_similarity, solo disponible cuando ambos términos ya tienen un vector persistido en taxonomy_term_embeddings (nunca se genera un embedding nuevo solo para puntuar - sección 9 del pedido).',
                'default' => 0.12, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Distancia coseno baja entre los vectores ya persistidos de ambos términos.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_language_equivalence' => [
                'label' => 'Peso: equivalencia de idioma',
                'description' => 'Phase 3: peso del signal language_equivalence (el concepto ya tiene un nombre en el idioma opuesto al del candidato, vía term_type=translation_alias u origen verificado bilingüe).',
                'default' => 0.05, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Concepto con canonical_name_en poblado y el candidato es la traducción ES verificada.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.weight_source_evidence' => [
                'label' => 'Peso: evidencia de fuente',
                'description' => 'Phase 3: peso del signal source_evidence (taxonomy_term_source_bindings verificados que también corroboran algún miembro del concepto).',
                'default' => 0.05, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Ambos términos verificados por la misma fuente externa (ej. SLB).',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.intent_contamination_penalty_weight' => [
                'label' => 'Peso de penalización por contaminación de intención',
                'description' => 'Phase 3 (sección 13 del pedido): cuánto RESTA del score compuesto cuando IntentContaminationDetector encuentra que el candidato es [INTENT + SUBJECT] de un término ya presente en el concepto (o viceversa). Se resta después de la suma ponderada, no se normaliza junto a los demás pesos.',
                'default' => 0.35, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Concepto ya contiene "cabria"; candidato es "mantenimiento de cabria" -> penalización fuerte.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.vector_top_k' => [
                'label' => 'Top-K de retrieval por vecino más cercano',
                'description' => 'Phase 3 (sección 9 del pedido): cuántos vecinos más cercanos trae la búsqueda vectorial por término antes del scoring compuesto - nunca comparación embedding all-pairs.',
                'default' => 20, 'min' => 1, 'max' => 100,
                'example' => 'Con ~1.900 términos, un KNN acotado evita los ~1.8M pares de una comparación exhaustiva.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.max_candidate_concepts_per_term' => [
                'label' => 'Tope de conceptos candidatos por término',
                'description' => 'Phase 3: cuántos conceptos candidatos (tras fusionar todas las señales de retrieval) se puntúan como máximo por término evaluado.',
                'default' => 10, 'min' => 1, 'max' => 50,
                'example' => 'Un término con matches débiles en 30 conceptos distintos solo puntúa los 10 mejores.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.mapping_quality_exact' => [
                'label' => 'Calidad de mapping: exact',
                'description' => 'Phase 3: peso de calidad para relaciones término→CPV con relation_type=exact, usado por el signal cpv_mapping_quality. taxonomy_term_cpv_relations.relation_type describe la calidad del MAPPING, nunca se reinterpreta como tipo de relación semántica entre conceptos (sección 11 del pedido).',
                'default' => 1.00, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Relación exact/approved usada como anchor de shared_cpv.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.mapping_quality_explicit_synonym' => [
                'label' => 'Calidad de mapping: explicit_synonym',
                'description' => 'Phase 3: peso de calidad para relation_type=explicit_synonym.',
                'default' => 0.95, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Relación explicit_synonym usada como anchor de shared_cpv.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.mapping_quality_strong_lexical' => [
                'label' => 'Calidad de mapping: strong_lexical',
                'description' => 'Phase 3: peso de calidad para relation_type=strong_lexical.',
                'default' => 0.85, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Relación strong_lexical usada como anchor de shared_cpv.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.mapping_quality_lexical' => [
                'label' => 'Calidad de mapping: lexical',
                'description' => 'Phase 3: peso de calidad para relation_type=lexical.',
                'default' => 0.70, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Relación lexical usada como anchor de shared_cpv.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.mapping_quality_contextual' => [
                'label' => 'Calidad de mapping: contextual',
                'description' => 'Phase 3: peso de calidad para relation_type=contextual (la más débil) - una relación contextual de baja confianza no puede funcionar como anchor equivalente a una exact/approved (sección 11 del pedido).',
                'default' => 0.50, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Relación contextual usada como anchor de shared_cpv.',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_builder.low_coherence_avg_similarity_threshold' => [
                'label' => 'Umbral de coherencia mínima (AUDIT_EXISTING)',
                'description' => 'Phase 3 (sección 14 del pedido): similitud léxica promedio mínima entre los términos de un concepto existente para NO marcarlo LOW_COHERENCE_CLUSTER durante la auditoría de solo-lectura.',
                'default' => 0.35, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Un concepto cuyos términos casi no comparten letras entre sí (posible agrupación espuria).',
                'group' => self::GROUP_CONCEPT_BUILDER,
            ],
            'concept_relations.min_lexical_similarity' => [
                'label' => 'Similitud léxica mínima entre nombres de concepto',
                'description' => 'Phase B: umbral `pg_trgm` mínimo entre los nombres (ES/EN) de dos conceptos canónicos para que la similitud de nombre cuente como señal hacia una propuesta RELATED_TO.',
                'default' => 0.35, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Nombres de concepto con alta superposición de subcadenas.',
                'group' => self::GROUP_CONCEPT_RELATIONS,
            ],
            'concept_relations.min_corroborating_signals' => [
                'label' => 'Señales corroborantes mínimas para proponer RELATED_TO',
                'description' => 'Phase B: cuántas de las 3 señales (similitud de nombre, CPV compartido, solapamiento de términos/alias) deben superar su propio umbral para que el par de conceptos genere una propuesta - ninguna señal sola alcanza.',
                'default' => 2, 'min' => 1, 'max' => 3,
                'example' => 'CPV compartido + solapamiento de alias, sin necesitar además nombre similar.',
                'group' => self::GROUP_CONCEPT_RELATIONS,
            ],
            'concept_relations.auto_eligible_threshold' => [
                'label' => 'Umbral AUTO_ELIGIBLE para relación entre conceptos',
                'description' => 'Phase B: confianza compuesta mínima para que una propuesta RELATED_TO se marque AUTO_ELIGIBLE en vez de REVIEW_REQUIRED. Nunca se usa para tipos direccionales/jerárquicos (PART_OF/HAS_PART/SUPERSEDES/SUPERSEDED_BY), que siempre son REVIEW_REQUIRED por diseño (ver audit/phase3_phase_b.md).',
                'default' => 0.75, 'min' => 0.50, 'max' => 1.0,
                'example' => 'Nombre muy similar + CPV compartido a nivel de Categoría.',
                'group' => self::GROUP_CONCEPT_RELATIONS,
            ],
            'concept_relations.review_threshold' => [
                'label' => 'Umbral REVIEW_REQUIRED para relación entre conceptos',
                'description' => 'Phase B: confianza compuesta mínima para que un par de conceptos se proponga (aunque sea para revisión), por debajo se descarta sin proponer nada.',
                'default' => 0.40, 'min' => 0.0, 'max' => 1.0,
                'example' => 'Una sola señal moderada (ej. solo CPV de Familia compartido).',
                'group' => self::GROUP_CONCEPT_RELATIONS,
            ],
            'concept_relations.max_cycle_check_depth' => [
                'label' => 'Profundidad máxima de chequeo de ciclos',
                'description' => 'Phase B: tope de saltos que la validación de ciclos recorre antes de asumir que no hay ciclo, para tipos de relación jerárquicos (PART_OF/HAS_PART). Independiente del `max_depth` por tipo del catálogo de gobernanza (ese limita traversal de negocio; este limita el costo del chequeo de seguridad).',
                'default' => 20, 'min' => 1, 'max' => 100,
                'example' => 'Con 79 conceptos, ninguna cadena real debería acercarse a este tope.',
                'group' => self::GROUP_CONCEPT_RELATIONS,
            ],
        ];
    }

    public static function defaults(): array
    {
        return array_map(fn ($definition) => $definition['default'], static::definitions());
    }
}

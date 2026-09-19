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
        ];
    }

    public static function defaults(): array
    {
        return array_map(fn ($definition) => $definition['default'], static::definitions());
    }
}

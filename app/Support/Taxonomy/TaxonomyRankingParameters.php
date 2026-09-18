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
        ];
    }

    public static function defaults(): array
    {
        return array_map(fn ($definition) => $definition['default'], static::definitions());
    }
}

# 001 · Para el orquestador — Handoff Fase C + auditoría del chatbot CIRA

**Fecha:** 2026-09-28
**De:** sesión de Claude Code sobre `PerfilAfiliadosCPV`
**Estado actual resumido:** ver `docs/orquestador/ESTADO.md`

---

## Alcance ejecutado

Dos trabajos: (a) auditoría del pipeline de intención del chatbot CIRA a partir de un dump de
`chat_memory`; (b) diseño + implementación + primera corrida de **Fase C (`--apply`) del Canonical
Concept Builder**.

## Fase C — decisiones de diseño (NO re-litigar)

**`--apply` materializa propuestas en las COLAS DE REVISIÓN, nunca publica.** Escribe
`taxonomy_candidate_concept_links` (`pending`) y `taxonomy_concept_relations` (`candidate`).
**Nunca toca `taxonomy_term_concepts`** — publicar sigue siendo exclusivo de
`CandidateConceptApprovalService::approve()` disparado por un humano en Filament. Esto implementa la
salvaguarda de `docs/task.md` §15.5 ("nunca bulk-apply"). **Un tier `AUTO_ACCEPT` tampoco publica
solo**; hay un test dedicado a esa propiedad.

6 propiedades de seguridad, cada una con su test:

1. Transacción única
2. Idempotencia explícita — la tabla no tiene índice único; además un par ya rechazado por un humano
   no se re-encola
3. Guarda de fingerprint obsoleto — si el grafo cambió entre el dry-run y la escritura, aborta
4. Tope de escrituras (`--max-writes`, default 500) — un plan mayor aborta antes de escribir nada
5. Audit log por fila (`actor_type=system` + `algorithm_version`)
6. Provenance estampada (`taxonomy_state_fingerprint`)

Más: verificación de tablas protegidas **dentro** de la transacción, que lanza excepción y revierte
todo si alguna cambió.

Cierra los 2 gaps que Phase B.1 había dejado documentados: el productor de
`taxonomy_state_fingerprint` y `resolver_version` (`canonical-concept-builder/phase-c-v1`).

Archivo principal: `app/Services/Taxonomy/CanonicalConceptApplyService.php`.
`planFrom()` es una función pura (no toca la base), separada a propósito de `apply()`.

## Corrida real ejecutada

`php artisan taxonomy:build-canonical-concepts --apply --limit=10 --max-writes=50 --skip-audit`

```
taxonomy_candidate_concept_links     0 →  10   10 propuestas de concepto nuevo, status=pending
taxonomy_concept_relations           0 →   2   status=candidate, ninguna approved
taxonomy_term_concepts             142 → 142   PROTEGIDA - sin cambios
taxonomy_canonical_concepts         79 →  79   PROTEGIDA - sin cambios
taxonomy_term_cpv_relations       9749 → 9749  PROTEGIDA - sin cambios
```

Verificado con consulta directa a la base, **no** con el reporte del propio comando: fingerprint
estampado en las 10 filas, 12 filas de `taxonomy_audit_log` todas con `actor_type=system`.
**Idempotencia verificada en producción**: una segunda corrida idéntica creó 0 filas y omitió las 10.

**Reversión:** borrar esas filas. Nada se publicó, no hay efecto que deshacer. Se identifican por el
fingerprint o por `algorithm_version='canonical-concept-builder/phase-c-v1'` en el audit log.

## Hallazgos sobre el chatbot CIRA

- El workflow actual (`Chat CIRA V5 - MCP`, id `zbVLoCdR09IA9yQK`) **ya no genera SQL crudo**: desde
  el 16-sep-2026 usa parámetros tipados hacia el Worker MCP. Toda la arquitectura de `whereClause`
  que aparece en los datos históricos de `chat_memory` es **legado ya reemplazado**.
- **El buscador está sano.** Verificado contra el código del Worker y contra datos reales: el filtro
  por ciudad/estado funciona correctamente.
- Único hallazgo accionable, severidad baja: la etiqueta `matched_via` no mencionaba los filtros
  estructurados activos → **ya corregido** en `perfilafiliados-mcp/src/empresa-tools.ts`.
- Test flaky preexistente detectado y corregido: `TaxonomyCategoriesRelationManagerTest` sorteaba el
  código de su fixture en `CPV-10..CPV-98`, rango que los datos CPV reales ocupan hasta `CPV-48` →
  **43.8% de fallo por corrida**. Movido a `CPV-900..CPV-999` (espacio verificado libre).

Detalle completo: `audit/chat_memory_intent_audit_2026-09-28.md` y `audit/phase3_phase_c_apply.md`.

## ⚠️ Lección de proceso — leer antes de auditar este sistema

Esta auditoría **concluyó dos veces que había un bug grave en producción, y las dos veces se
equivocó**:

1. Primero analizó datos históricos de `chat_memory` y reportó un bug de comillas SQL que afectaba al
   85% de las búsquedas — era código legado, reemplazado el 16-sep-2026.
2. Después interpretó las etiquetas de una respuesta del chat en vivo y reportó que el filtro por
   estado no funcionaba — sí funcionaba; las 6 empresas devueltas estaban todas en Zulia.

Ambas correcciones vinieron de ir a la fuente real (workflow desplegado, código del Worker, base de
datos), no de razonar sobre evidencia indirecta.

**Regla para este sistema: verificar contra la fuente antes de reportar, no después.**

## Pendiente

**Revisión humana en Filament** de los 10 candidatos (`TaxonomyCandidateConceptLinkResource`,
decisiones MAP_TO_EXISTING / CREATE_NEW / REJECT de Phase B.1). Después, decidir si se corre
`--apply` sin `--limit`.

⚠️ **Expectativa crítica:** poblar la cola **no cambia lo que devuelve el buscador**. El buscador lee
el grafo publicado (`taxonomy_term_concepts` = 142, intacto). Solo la aprobación humana lo mueve.
Eso no es una limitación de la entrega — es la salvaguarda que el proyecto eligió.

## Nada está commiteado

Los cambios de ambos repos están en working tree, sin commitear ni pushear. Ver `ESTADO.md` para el
listado exacto de archivos.

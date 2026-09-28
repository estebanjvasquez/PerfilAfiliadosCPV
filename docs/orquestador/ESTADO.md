# ESTADO ACTUAL — PerfilAfiliadosCPV

**Actualizado:** 2026-09-28
**Rama activa:** `feature/upgrade-filament-v3` · **Producción:** `main` (Filament v2 + MySQL, sin tocar)

> Foto del presente. El historial del ida y vuelta está en los archivos numerados de esta carpeta.

---

## Trabajo sin commitear (en working tree, 2 repos)

**`PerfilAfiliadosCPV`**
```
M  app/Console/Commands/BuildTaxonomyCanonicalConcepts.php     --apply desbloqueado + --max-writes
M  tests/Feature/Filament/TaxonomyCategoriesRelationManagerTest.php   de-flake de fixture
M  docs/task.md                                                sección 6quater nueva
?? app/Services/Taxonomy/CanonicalConceptApplyService.php      lógica de Fase C
?? tests/Unit/Taxonomy/CanonicalConceptApplyServiceTest.php    14 tests
?? audit/phase3_phase_c_apply.md                               auditoría de Fase C
?? audit/chat_memory_intent_audit_2026-09-28.md (+4 JSON)      auditoría del chatbot
?? database/chat_memory_sample.json, chat_memory_intent_eval_sample.json
```

**`perfilafiliados-mcp`**
```
M  src/empresa-tools.ts    fix cosmético de matched_via (tsc --noEmit OK)
```

## Estado de la base (verificado por consulta directa, 2026-09-28)

```
taxonomy_candidate_concept_links    10   ← poblado por Fase C, todos status=pending
taxonomy_concept_relations           2   ← status=candidate, ninguna approved
taxonomy_term_concepts             142   PROTEGIDA - sin cambios
taxonomy_canonical_concepts         79   PROTEGIDA - sin cambios
taxonomy_term_cpv_relations       9749   PROTEGIDA - sin cambios
```

Fingerprint de la corrida: `c80a040c46d632bcfb1ba9cc488716b30f3247d12ec278e8bfc8c065b8951acc`
Algoritmo: `canonical-concept-builder/phase-c-v1`

## Tests

- `CanonicalConceptApplyServiceTest`: **14/14 PASS**
- Suite completa de taxonomía: **140/140 PASS**

## Próximo paso

**Revisión humana en Filament** de los 10 candidatos encolados (`TaxonomyCandidateConceptLinkResource`,
decisiones MAP_TO_EXISTING / CREATE_NEW / REJECT). Después, decidir si se corre `--apply` sin `--limit`.

⚠️ Poblar la cola **no cambia lo que devuelve el buscador**. El buscador lee el grafo publicado
(`taxonomy_term_concepts` = 142, intacto). Solo la aprobación humana lo mueve. Si alguien reporta
"poblamos y el buscador no cambió", ese es el comportamiento diseñado.

## Restricciones vigentes

- No mergear `feature/upgrade-filament-v3` a `main` sin decisión del cliente.
- No modificar el workflow de n8n sin autorización explícita (infraestructura compartida, tráfico real).
- No publicar candidatos por fuera del flujo de aprobación humana.
- No `DELETE`-all / rebuild-all de taxonomía. Contar relaciones antes/después de cualquier escritura.

## Entorno (no obvio)

- PHP no está en PATH → `C:\Users\esteb\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_*\php.exe`
- git no está en PATH → el embebido de GitHub Desktop
- node/npm → `C:\Program Files\nodejs\`
- SSH al VPS de n8n → `C:\Users\esteb\.ssh\n8n_vmi2945958_ed25519` (host/Docker, **no** el panel web)
- Sin `DEBUG_TOKEN`/`MCP_TOKEN` del Worker (no recuperables, solo rotables)

## Índice de comunicaciones

| Archivo | Tema |
|---|---|
| `001-para-orquestador-handoff-fase-c.md` | Handoff completo: Fase C + auditoría del chatbot CIRA |

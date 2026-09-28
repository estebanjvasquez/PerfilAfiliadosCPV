# ESTADO ACTUAL — PerfilAfiliadosCPV

**Actualizado:** 2026-09-28
**Rama activa:** `feature/upgrade-filament-v3` · **Producción:** `main` (Filament v2 + MySQL, sin tocar)

> Foto del presente. El historial del ida y vuelta está en los archivos numerados de esta carpeta.

---

## Estado de git (2026-09-28, bajo el Collaboration Protocol)

Ambos repos **committeados y pusheados**, working tree limpio en los dos. Nada mezclado entre repos.

**`PerfilAfiliadosCPV`** — rama `feature/upgrade-filament-v3`, HEAD `20bf6eb` (era `cf100e2` al empezar)
```
2b9c697  docs(audit): CIRA intent pipeline audit + reusable eval dataset from chat_memory
62b64b0  test: de-flake TaxonomyCategoriesRelationManagerTest fixture (43.8% de fallo)
4a773fa  feat: Phase C - --apply materializa propuestas en las colas de revision
20bf6eb  docs: add docs/orquestador channel (superseded as primary by GitHub per collab protocol)
```

**`perfilafiliados-mcp`** — rama `master`, HEAD `29de993` (era `12a7fbd` al empezar)
```
29de993  fix: propagate structured-filter label to matched_via in the hybrid search path
```

**PR draft: BLOQUEADO.** El PAT guardado en el credential manager no tiene scope
`pull_requests:write` (403 Forbidden al intentar `POST /repos/.../pulls`). No se intentó escalar el
permiso por cuenta propia. **Acción pendiente de Esteban:** crear el PR a mano (`base: main`,
`head: feature/upgrade-filament-v3`, draft) con el cuerpo ya redactado, o dar un token con ese scope.
Cuerpo del PR listo en el mensaje de handoff — ver `001-para-orquestador-handoff-fase-c.md`.

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
| `002-para-orquestador-pr-body-fase-c.md` | Cuerpo del PR (creación bloqueada — falta scope `pull_requests:write` en el PAT) |

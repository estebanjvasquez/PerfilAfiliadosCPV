# TASK-0028 — CIRA: auditoría de fuentes, ranking y separación de capacidades y experiencias

| Campo | Valor |
|---|---|
| Estado | ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (2026-11-16) |
| Fuente | Issue #2 `6913187637` (estebanjvasquez, 2026-11-16T09:57:03Z): «ASIGNACIÓN DEL PROPIETARIO — CIRA: auditoría de fuentes, ranking y separación de capacidades/experiencias» |
| Tipo | **Auditoría.** No es una asignación de implementación |
| Rama de documentación | `fix/sec-01-r4-01-role-editor-preserve`, BASE `f080daa` |
| Relación | Nueva. No cambia TASK-0013, TASK-0014 ni TASK-0027, que conservan su identificador y su propósito |
| Gates | sin cambios en el flujo n8n activo, la BD compartida, el Worker desplegado, staging ni producción; sin desplegar A ni B; sin merge a `main`; sin loops ni sondeos |

## Comentario fuente (íntegro)

## ASIGNACIÓN DEL PROPIETARIO — CIRA: auditoría de fuentes, ranking y separación de capacidades/experiencias

**Alcance autorizado:** auditoría técnica de solo lectura, captura de evidencia de n8n, Supabase y código, experimentos reproducibles en entorno aislado, propuesta UX y plan de implementación. **NO autoriza** modificar el workflow n8n activo, migrar BD compartida, desplegar staging/producción, editar taxonomía, cambiar ranking vivo, hacer merge a main ni activar loops/polling.

**Instrucción para el desarrollador:** materializar una **tarea nueva con el siguiente ID disponible** siguiendo `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md`; verificar `git status -sb`, rama, HEAD y cambios preexistentes; revisar tarea activa y evitar interferencias. **No reutilizar TASK-0013/0014/0027, no inventar ID.** Este comentario constituye la asignación explícita de una tarea **de auditoría**, no de implementación. Conservar antecedentes y registrar la tarea nueva, evidencias, estado y handoff versionados. Leer este comentario completo una sola vez y solo referencias directas.

### Problema observado y evidencia verificable
Consulta real `balancin` en Supabase `cira_search_log.id=52`, `engine_version=flow zbVLoCdR09IA9yQK TASK-0013-step1-v2+explain+votos` (comprobar vigencia efectiva frente a n8n):
- #1 empresa 157 **LUKIVEN, S.A.**, relevancia 100, evidencia `lexical_taxonomy`, `canonical_cpv` (categoría CPV «Balancín»), también `lexical_experiencia`, `fulltext`, `experiencia`, `taxonomy`.
- #2 empresa 91 **DISTEINSA, C.A.**, relevancia 40: experiencia «Suministro y Asesoría para la Instalación de Sistemas de Sellado de Fluidos Chesterton en Balancines…», evidencia `lexical_experiencia` y `fulltext`.
- #3 empresa 333 **CONSORCIO OGS, C.A.**, relevancia 39: experiencia contractual, evidencia `lexical_experiencia` y `fulltext`.
- #4 empresa 391 **TOTAL CLEAN, C.A.**, relevancia 38: experiencia contractual, evidencia `lexical_experiencia` y `fulltext`.

**Hipótesis:** CIRA mezcla capacidades que la empresa ofrece con menciones de equipos/sectores en proyectos realizados. La mera mención de «balancín» no acredita fabricación/venta. La categoría «Balancín» tampoco prueba por sí sola el rol fabricante. Examinar además posibles aportes duplicados en `result_evidence.fuentes` (se observan entradas repetidas); distinguir duplicación de presentación frente a doble cómputo real.

### Fase 1 — Captura del motor real
Si existe acceso n8n, **solo lectura**: identificar workflow activo y versión ejecutada, nodos, conexiones, consultas SQL/RPC/HTTP, filtros, transformaciones, campos recuperados, prompts de IA, embeddings, búsquedas lexical/fulltext, fusión, deduplicación, scoring/ponderaciones, explicación de coincidencias, logs, errores y tiempos. Exportar copia sanitizada del JSON del workflow para análisis **sin credenciales, tokens, endpoints secretos ni PII**. No importar/guardar/activar/desactivar workflows. Si falta acceso, identificar artefactos exactos necesarios y cómo obtenerlos. Verificar si la versión de `cira_search_log` coincide con el flujo vivo.

### Fase 2 — Trazabilidad de datos
Supabase **solo SELECT**. Examinar las fuentes realmente usadas, incluidas `capacityView`, `ExperienceView`, `empresa_sector_service`, `empresa_taxonomy_category`, `empresa_experiencias`, `empresa_experiencia_embeddings`, `service_embeddings`, `cira_search_log`, `cira_search_feedback`, sectores, servicios y categorías. Para IDs 157/91/333/391: mapear capacidad declarada, taxonomía, experiencia que disparó coincidencia, contribución exacta al score y explicación. Distinguir `fulltext` que indexa experiencias de otros campos: eliminar experiencias de un nodo no basta si vuelven por fulltext o embeddings. Investigar duplicaciones, normalización y falsos positivos. No ejecutar nuevas búsquedas en sistemas vivos si alteran logs sin autorización adicional.

### Fase 3 — Experimentos reproducibles, sin tráfico real
Con snapshots/copia aislada y mismas consultas/datos, comparar:
- **Actual:** recuperar candidatos, ranking, fuentes, latencia, explicación.
- **A (aislamiento):** sectores + servicios + taxonomía CPV; excluir experiencias de **generación de candidatos, ranking, fulltext, embeddings y explicación de coincidencia**; conservarlas en perfil.
- **B (propuesta):** separar **«Empresas que ofrecen la capacidad»** (evidencia en servicios/taxonomía y rol sustentado) y **«Empresas con experiencias relacionadas»** (proyecto o actividad histórica). Experiencias no deben elevar automáticamente al grupo de capacidades. Evitar duplicados entre grupos; si empresa tiene ambos tipos de evidencia, mantener tarjeta principal y mostrar experiencia complementaria.

Analizar intención de consulta: `balancin`, `fabricantes de balancines`, `venta de balancines`, `mantenimiento de balancines`, `sellado de fluidos en balancines`, `limpieza de balancines`, `fabricación de válvulas industriales`, `mantenimiento de válvulas industriales`, consulta negativa y consultas reales sanitizadas. No atribuir rol fabricante/distribuidor/servicio por simple mención. Registrar Precision@5, falsos positivos, cobertura/omisiones, exactitud de rol, latencia y trazabilidad; no inventar etiquetas humanas.

### Fase 4 — Propuesta UX CIRA Test
Entregar **mockup verificable sin desplegar**, preferentemente estático o prototipo aislado, con:
1. Sección principal «Empresas que ofrecen productos o servicios relacionados»; cada tarjeta indica **servicio/categoría concreta**, rol demostrado (si lo hay), explicación y vínculo al perfil.
2. Sección secundaria «Empresas con experiencias relacionadas» claramente separada, opcionalmente contraída, con proyecto, actividad ejecutada si está respaldada, y aclaración «la experiencia no acredita fabricación o comercialización».
3. Selector «Solo capacidades» (predeterminado) / «Capacidades + experiencias».
4. Compatibilidad propuesta con votos 👍/👎, `cira_search_log`, `cira_search_feedback`, campaña de evaluación institucional y capturas inmutables. Precisar si el cambio requeriría migración o cambio de contrato API; preferir evitarlo.

### Fase 5 — Decisión y gates
Recomendar **A como experimento de control**; recomendar **B como destino** solo si reduce falsos positivos, conserva cobertura relevante, clasifica roles con evidencia, no rompe votos/evaluaciones y tiene rollback verificable. Si B no cumple, proponer A u opción intermedia fundamentada. No implementar ni desplegar B en esta tarea.

### Entregables versionados
1. Informe fuente-primero con evidencia y limitaciones.
2. Diagrama n8n real + matriz fuente → nodo/consulta → ranking.
3. Reproducción de `balancin` y cálculo de contribuciones, incluyendo posible doble cómputo.
4. Matriz Actual/A/B con consultas, métricas y casos de regresión.
5. Mockup UX de dos grupos y contrato de datos propuesto.
6. Riesgos, compatibilidad, plan de implementación por fases, rollback y decisión recomendada.
7. Actualización de `docs/orquestador/`, `audit/` y `audit/orchestrator_handoff.json` según protocolo, con SHAs base/review/handoff diferenciados, evidencia de cero mutaciones, y comentario de coordinación en Issue #2 si el acceso lo permite.

**Gates:** cualquier modificación del workflow n8n, cambio de ranking vivo, despliegue o migración requiere **nueva autorización explícita del propietario y nueva tarea**. Al llegar a `READY_FOR_REVIEW`, **STOP**; responder por la convención `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`. No iniciar otro trabajo autónomamente.

**Preguntas a contestar:** (1) causa exacta de DISTEINSA/TOTAL CLEAN; (2) porcentaje/aporte de experiencia por resultado; (3) posibilidad de excluirla por completo sin romper fuentes principales; (4) separación de intención fabricación/venta/mantenimiento/limpieza/sellado; (5) omisiones de A; (6) cobertura útil de B; (7) aspecto visual en CIRA Test; (8) nodos/archivos a cambiar; (9) regresiones/rollback; (10) decisión sustentada.

**Estado:** asignación de auditoría exclusivamente. Esperar revisión del propietario antes de implementación.

## Avance (2026-11-16)

**Estado:** READY_FOR_REVIEW. Auditoría, experimentos aislados y propuesta terminados; nada implementado ni desplegado.

**Entregables:**

| # | Entregable | Dónde |
|---|---|---|
| 1 | Informe fuente-primero, con evidencia y limitaciones | [`audit/CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md`](../../../audit/CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md) |
| 2 | Diagrama del flujo n8n real y matriz fuente → consulta → ranking | Informe §1.2 y §2.1; copia sanitizada en [`docs/cira/task0028/n8n/`](../../cira/task0028/n8n/) |
| 3 | Reproducción de «balancin», aportes y doble cómputo | Informe §3 |
| 4 | Matriz Actual / A / B con métricas y regresiones | Informe §4; datos en [`docs/cira/task0028/experimentos/`](../../cira/task0028/experimentos/) |
| 5 | Mockup de dos grupos y contrato de datos | [`docs/cira/task0028/mockup/cira_test_dos_grupos.html`](../../cira/task0028/mockup/cira_test_dos_grupos.html); informe §5 |
| 6 | Riesgos, compatibilidad, plan por fases, rollback y decisión | Informe §6–§9 |

**Resultados principales:**
- **DISTEINSA, CONSORCIO OGS y TOTAL CLEAN:** el 100 % de su puntaje para «balancin» viene de experiencias. Llegan por `lexical_experiencia` y por un `fulltext` cuyo documento es en un 64 % texto de experiencias. Lukiven tiene un 50 % de experiencias en su puntaje.
- **Doble cómputo real:** `balancin` y `balancín` cuentan como dos frases. Es uniforme: no cambió el top 5 de ninguna de las 55 consultas.
- **Métricas con etiquetas del cliente** (31 consultas):

  | Variante | No relevantes en el top 5 | Precisión sobre lo juzgado | Relevantes en el top 10 | Consultas vacías |
  |---|---|---|---|---|
  | Actual | 38 | 47 % | 39/49 | 2 |
  | A | 27 | 47 % | 30/49 | 6 |
  | B | 27 | 47 % | 39/49 | 2 |

- **Rol de fabricante o vendedor:** no se puede acreditar con los datos actuales. El 79 % de los vínculos empresa–categoría son `suggested` y la intención detectada no entra en el ranking.
- **Recomendación:** A como control y B como destino por fases, sin afirmar roles hasta tener un dato confirmado.

**Cero mutaciones** (verificado al cerrar, 2026-11-16 ~10:21Z):
- **n8n:** `zbVLoCdR09IA9yQK` sigue en `versionId bafd597a…` y `updatedAt 2026-11-09T16:11:20.166Z`.
- **BD compartida:** `cira_search_log` tenía 67 filas antes y después; `empresa_search_documents` no cambió y `esd_noexp` no existe en vivo.
- **Staging:** sigue en `5e6e3fc` y responde 200.
- **Copia aislada:** se retiró. El volcado queda en `/root/backups/cira-t28-20261116T100911Z` (fuera de git, 700).

**Gates pendientes:** cualquier fase del plan (§6 del informe) requiere una nueva autorización del propietario y una tarea nueva.

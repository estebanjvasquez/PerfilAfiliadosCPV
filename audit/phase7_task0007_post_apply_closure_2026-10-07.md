# TASK-0007 — APPLY ATÓMICO REAL EJECUTADO: validación post-publicación

**Autorización de ejecución:** Issue #2 comentario `6032819854` — *ORCHESTRATOR AUTHORIZATION RECORD —
TASK-0007 REAL ATOMIC BATCH APPLY/PUBLISH IN STAGING*
**Contrato de esta ronda de cierre:** comentario `6032959069` — *POST-APPLY AUDIT — REAL EXECUTION PASS /
CLOSURE VALIDATION REQUIRED*
**Protocolo de procedencia de la autorización:** comentario `6032759610`
**Re-audit previo (PASS técnico):** comentario `6031966511`
**Runtime desplegado:** `004d24e98159bf152c741bd9f0ee698b43139d87`
**HEAD revisado al momento de ejecutar:** `f8a98df5ff5e90a05849c709f3a5847f6945d198`
**Rama:** `feature/upgrade-filament-v3`
**Fecha:** 2026-10-07
**Documento de las tres rondas de implementación:** `audit/phase7_task0007_batch_apply_2026-10-05.md`

**EL APPLY/PUBLISH REAL SE EJECUTÓ.** Esto invierte la afirmación central del documento de
implementación, cuyo encabezado quedó corregido con un puntero a este archivo. Al cierre de esta ronda
las 12 propuestas están `APPLIED`, los 10 candidatos están 3 `published` + 7 `context_required`, las 2
relaciones están `rejected`, y hay 12 filas con `applied_at`. La taxonomía se publicó.

**En esta ronda de cierre no se ejecutó ningún APPLY y no se hizo ningún fix.** Todo lo medido abajo es
de solo lectura, salvo la captura de artefactos de auditoría y este documento.

---

## 1. Artefacto de ejecución capturado

`audit/task0007_batch_apply_result_2026-10-07.json`, traído tal cual del contenedor de staging por el
directorio `audit/` montado. **Sus campos de ejecución no se regeneraron ni se editaron.**

| Campo exigido por el paso 1 | Valor en el artefacto |
|---|---|
| `mode` | `ATOMIC_BATCH_APPLY` |
| `result` | `BATCH_APPLIED` |
| `blocker` | `null` |
| `applied_proposal_count` | 12 |
| `execution_unit_count` | 11 |
| `authorization_reference` | `Issue #2 comment 6032819854` |
| `target_environment` | `staging` |
| `baseline_taxonomy_fingerprint` | `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2` |
| `manifest_fingerprint` | `eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702` |
| `scope` | `FULL_PENDING_QUEUE` |
| `generated_at` | `2026-10-07 07:09:26` |
| `protected_counts_after` | coincide con la base viva (verificado de nuevo en la §4) |

### 1.1 Cómo llegó el manifiesto autorizado al contenedor

El código de la app está **horneado en la imagen** (`docker-compose.yml` sólo bind-montea `storage`,
`bootstrap/cache` y `public`), y la imagen desplegada se construyó en `004d24e`. El manifiesto
autorizado se agregó en el commit docs-only `f8a98df`, que por `paths-ignore` **no** disparó deploy, así
que no existía dentro de la imagen.

Se resolvió sin tocar runtime: `git fetch` + `git reset --hard f8a98df` en el host (delta exclusivamente
`audit/` + `docs/`, verificado con `git diff --stat` antes de hacerlo: 6 archivos, cero código
ejecutable), `chown -R 33:33 storage bootstrap/cache` como hace el script de deploy, y el directorio
`audit/` del host montado en el contenedor. El `sha256` del manifiesto en staging resultó
`d288f00cf5c8a9f4ce403d3ce732c1e7466d6abff3a24a1ed481465b581d268c`, **byte por byte igual al local** —
medido, no asumido. No hubo rebuild, ni migración, ni reinicio.

### 1.2 Un rechazo intermedio que vale registrar

El primer intento de `--execute` **falló y no escribió nada**. PowerShell 5.1 descartó las comillas
internas al invocar `ssh.exe`, así que el shell remoto interpretó `#2 comment …` como comentario y
`--authorized-by` llegó sin dígitos. La compuerta respondió
`--authorized-by="<referencia con al menos un dígito>" es obligatorio con --execute` y abortó **antes**
del banner destructivo y antes de cualquier escritura.

Se registra por dos razones: es evidencia en vivo de que la validación de la referencia de autorización
corre antes de todo lo demás, y porque ocultar un intento fallido de una ejecución irreversible sería
exactamente el tipo de omisión que esta cadena de auditoría existe para impedir. El reintento pasó el
script por `stdin` en vez de por argumentos, conservando la referencia exacta.

---

## 2. Fingerprint de taxonomía post-APPLY (paso 2)

`CanonicalConceptBuilderService::dryRunInputFingerprint()`, leído en `staging`:

| | Valor |
|---|---|
| Baseline pre-APPLY (atado al manifiesto) | `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2` |
| **Post-APPLY** | **`e1087a481ab15a45936519d2b0f6190e2e48d7f1c05e04cf87c0d1c703db9b5b`** |

**Difieren**, como exige el contrato: el grafo publicado cambió (+1 concepto canónico, +3
TERM→CONCEPT). Ningún `payload_fingerprint` ni `taxonomy_state_fingerprint` de ninguna propuesta se
reescribió.

Corroboración independiente y gratuita: el preflight de lote sin manifiesto ahora devuelve
`BATCH_EMPTY_REQUEST`, porque la cola `PENDING_APPLY` quedó vacía.

---

## 3. Regresión congelada de 32 queries (paso 3): `BLOCKED_AUTH_TOKEN_UNAVAILABLE`

**No se pudo correr.** `scripts/regression-suite.mjs` exige `--token` y pega contra
`POST {url}/debug-search`, que está protegido por el `DEBUG_TOKEN` del Worker.

Lo verificado antes de declarar el bloqueo, en vez de suponerlo:

- `GET https://perfilafiliados-mcp.sisteg.workers.dev/` → `200 {"ok":true,"service":"perfilafiliados-mcp"}`.
  El Worker está sano.
- `POST /debug-search` sin token → `401`. La capa de auth está viva.
- No existe `.dev.vars`, `.dev.vars.local`, `.env` ni `.env.local` en `perfilafiliados-mcp`, y
  `wrangler.toml` no contiene `DEBUG_TOKEN` ni `MCP_TOKEN` (0 ocurrencias). El token es un secreto de
  Worker, nunca persistido a un archivo trackeado — la convención establecida desde TASK-0003.
- No hay token de API de Cloudflare disponible en esta sesión para rotarlo.

**No se rotó ningún secreto.** La sección D del contrato lo prohíbe explícitamente y el paso 3 ordena
reportar `BLOCKED_AUTH_TOKEN_UNAVAILABLE` en vez de rotar. Tampoco se modificó código de búsqueda,
ranking, taxonomía, fixtures ni salida esperada para fabricar un verde: la suite simplemente no corrió.

`audit/regression_baseline_2026-09-23.json` **no se tocó.** No se creó ningún artefacto de resultado,
porque no hay resultado que guardar.

### 3.1 Qué sí se pudo medir sobre el riesgo que la suite cubriría

El bloqueo es de credencial, no de información. Las 32 queries del fixture son datos legibles, y la
taxonomía que el APPLY cambió es exactamente conocida: los conceptos **#2890** y **#4819**, y los
términos **#22**, **#23**, **#24**.

Medido en staging contra la base viva, con el mismo predicado L0 que usa el Worker (exacto,
`unaccent(lower(...))`, nunca `ILIKE`):

**0 de las 32 queries congeladas alcanza el concepto #4819 o el #2890.** Ninguna de ellas matchea los
términos #22/#23/#24 ni comparte concepto con ellos.

Y `taxonomy_concept_relations` **no aparece en ningún archivo** de `perfilafiliados-mcp/src/*.ts` (0
ocurrencias), así que el REJECT de las relaciones #61/#62 no puede afectar la búsqueda por construcción.

Esto es una acotación del riesgo, **no un sustituto de la corrida**. La suite compara nueve campos
estructurales por query, incluidos `top_empresa_ids` y `top_evidence_strengths`, que dependen de más
cosas que la expansión canónica. La compuerta sigue abierta y requiere el token.

---

## 4. Checks dirigidos post-publicación (paso 4)

El camino de búsqueda híbrida vive en el **Worker** (TypeScript: `canonical-expansion.ts`,
`hybrid-search.ts`), no en Laravel, así que ejercitarlo end-to-end depende del mismo token bloqueado.
Lo que sí se pudo hacer, y es la parte que responde la pregunta de fondo, es reproducir **de solo
lectura y contra las mismas filas de Supabase que el Worker lee** las ramas L0–L3 de
`canonical-expansion.ts` para `pipeline`, `refinery` y `refinería`.

El predicado decisivo de esa capa, leído en el código fuente del Worker: un código CPV se hereda a
través de un concepto canónico **sólo** vía
`taxonomy_term_cpv_relations r ... and r.status = 'approved'` sobre un término **hermano**
(`sib_link.term_id != t.id`).

| Consulta | Término L0 | CPV propias | Concepto vinculado | Hermanos | CPV heredados visibles al path vivo |
|---|---|---|---|---|---|
| `pipeline` | #24 `pipeline` | 8, **0 approved** (todas `needs_review`) | #2890 `oleoducto / oil pipeline` | **0** | **0** |
| `pipeline` | #25 `ducto` | 8, **0 approved** | — (sin concepto) | — | **0** |
| `refinery` | #22 `refinery` | 1, **0 approved** (`candidate`) | #4819 `refinería / refinery` | 1 (#23, 0 approved) | **0** |
| `refinery` | #23 `refinería` | 1, **0 approved** (`candidate`) | #4819 | 1 (#22, 0 approved) | **0** |
| `refinería` | #23 `refinería` | 1, **0 approved** (`candidate`) | #4819 | 1 (#22, 0 approved) | **0** |

**Conclusión:** los TERM→CONCEPT recién publicados **están en los datos pero no aportan ninguna
expansión CPV nueva al camino de búsqueda vivo.** Dos razones distintas, ninguna accidental:

1. El concepto **#2890** contiene **sólo** el término #24. La rama de herencia exige un hermano, y no
   hay hermano, así que no hay nada que heredar. El `MAP_TO_EXISTING` de #491 vinculó `pipeline` a un
   concepto que hoy no tiene otros términos.
2. El concepto **#4819** contiene los términos #22 y #23, que **sí** son hermanos entre sí, pero sus
   únicas filas CPV son `status = candidate` y la rama exige `approved`.

Como el nombre del concepto (`query_understanding.canonical_concepts` en los diagnósticos del Worker)
sale de esa misma rama, tampoco aparecería.

**No se inventó ningún mapeo CPV para el concepto nuevo**, y no se autorizó ni se hizo ningún cambio
TERM→CPV. Distribución global de estados CPV, sin cambios: 212 `approved`, 17 `candidate`, 238
`deprecated`, 9282 `needs_review`.

Evidencia estructurada completa: `audit/task0007_post_apply_closure_2026-10-07.json`, sección
`step_4_search_probe`. Está etiquetada ahí mismo como reproducción del lado-datos, **no** como llamada
end-to-end.

---

## 5. Invariantes post-APPLY (paso 5)

Las 18 cuentas protegidas coinciden **exactamente** con lo que el orquestador declaró, sin una sola
discrepancia:

| | Valor | | Valor |
|---|---|---|---|
| `candidate_links` | 10 | `canonical_concepts` | **82** |
| ├ `pending` | **0** | `term_concepts` | **145** |
| ├ `published` | **3** | `term_cpv_relations` | 9749 |
| ├ `context_required` | **7** | `reviewed_proposals` | 15 |
| ├ `rejected` | 0 | ├ `pending_apply` | **0** |
| └ `approved` | 0 | ├ `applied` | **12** |
| `concept_relations` | 2 | ├ `aborted` | 0 |
| ├ `candidate` | **0** | └ `superseded` | 3 |
| ├ `approved` | 0 | | |
| └ `rejected` | **2** | | |

- **Las 12 filas autorizadas:** todas `APPLIED`, `target_environment = staging`,
  `authorization_reference = Issue #2 comment 6032819854`, `applied_at` no nulo
  (`2026-10-07 07:09:25`/`:26`).
- **#420/#421/#422 intactas:** `SUPERSEDED`, con `applied_at`, `authorization_reference` y
  `target_environment` en `NULL`. No se tocó ninguna fila histórica.
- **Auditoría de ejecución:** exactamente **12** filas con esa referencia, ids `#5249`…`#5260`,
  `actor_type = system`, `target_environment = staging`, y `max(id)` global = 5260.
- **Concepto #4819:** `canonical_name_es = refinería`, `canonical_name_en = refinery`,
  `status = active`, creado `2026-10-07 07:09:25`.
- **TERM→CONCEPT nuevos:** #1191 (term #24 → concepto #2890), #1192 (term #22 → #4819),
  #1193 (term #23 → #4819).
- **Relaciones:** #61 `4 → 25` y #62 `16 → 61`, ambas `RELATED_TO`, ambas `rejected`.

### 5.1 Una aserción propia que falló, y por qué era la aserción y no el dato

El verificador inicial afirmaba «0 filas TERM→CPV para los términos 22/23» y devolvió **2**. Antes de
clasificarlo se midió el `created_at`:

Las dos filas (`#9728` term 22, `#9736` term 23, ambas `CPV-37.06.16S`) se crearon el **2026-09-18** por
`auto_mapper_v1`, con `status = candidate` — **19 días antes del APPLY**. Y: **0** filas TERM→CPV
creadas el 2026-10-07, **0** actualizadas el 2026-10-07, total intacto en **9749**.

El requisito real es que el APPLY no escribiera ninguna relación TERM→CPV, y eso se cumple. Mi
aserción exigía además que no existieran filas preexistentes del auto-mapper, que es otra cosa. Se
corrige la aserción, no el dato, y se deja registrado que el concepto nuevo **sí** tiene relaciones CPV
preexistentes en estado `candidate` — relevante porque explica, junto con la §4, por qué el concepto es
hoy inerte para la búsqueda.

---

## 6. Tests y smoke (paso 6)

El paso 6 pide no inventar churn de código, y no lo hubo: **el APPLY no cambió una sola línea de
runtime.** El `HEAD` desplegado sigue siendo `004d24e`.

### 6.1 Límite de verificación declarado: PHPUnit no se pudo correr en esta ronda

No es una omisión, son dos bloqueos simultáneos y verificados:

- **Local:** `php.exe` sigue bloqueado por la política de Application Control de esta máquina (`Program
  'php.exe' failed to run: An Application Control policy has blocked this file`). Es la misma política
  que bloquea `php_intl.dll` y que ya apareció en TASK-0006E y en la ronda 3 de esta tarea.
- **Staging:** el `Dockerfile` corre `composer install --no-dev`, así que `vendor/bin/phpunit` **no
  existe** en la imagen (verificado: `PHPUNIT_ABSENT`).

Instalar dev-dependencies en el contenedor de staging para correr tests habría sido modificar el
entorno operativo justo después de una publicación irreversible, sin autorización y sin que el código
bajo test hubiera cambiado. No se hizo. La última corrida completa conocida sigue siendo la de la ronda
3: **197/197 PASS, 1432 assertions**, sobre este mismo runtime `004d24e`.

### 6.2 Smoke de la app desplegada: sano

Once rutas, cero `500`/`503`:

| Ruta | Código |
|---|---|
| `GET /` | 200 |
| `GET /admin/login` | 200 |
| `GET /admin` | 302 → login |
| `GET /admin/taxonomy-reviewed-proposals` | 302 |
| `GET /admin/taxonomy-candidate-concept-links` | 302 |
| `GET /admin/taxonomy-concept-relations` | 302 |
| `GET /admin/taxonomy-canonical-concepts` | 302 |
| `GET /admin/taxonomy-reviewed-proposals/491` | 302 |
| `GET /admin/taxonomy-reviewed-proposals/629` | 302 |
| `GET /admin/taxonomy-candidate-concept-links/270` | 302 |
| `GET /admin/taxonomy-canonical-concepts/4819/edit` | 302 |

El `302` es la respuesta sana de una ruta de panel sin sesión: prueba que la ruta resuelve y que el
middleware corre, **no** que la página renderiza autenticada. Se dice así en vez de presentarlo como
prueba de render.

### 6.3 Log de Laravel en staging

Exactamente **2** líneas de nivel `ERROR` con fecha 2026-10-07, y las dos son **mías**: dos
invocaciones fallidas de `php artisan route:list --columns` a las 07:29 (esa opción no existe en esta
versión de Laravel). **Cero errores de aplicación**, y el APPLY corrió a las 07:09.

---

## 7. Clasificación final de esta ronda

| Punto del contrato | Estado |
|---|---|
| 1. Artefacto de ejecución capturado | **PASS** |
| 2. Fingerprint post-APPLY registrado y distinto | **PASS** |
| 3. Regresión congelada de 32 queries | **`BLOCKED_AUTH_TOKEN_UNAVAILABLE`** |
| 4. Checks dirigidos `pipeline`/`refinery`/`refinería` | **PASS (lado-datos)**; end-to-end bloqueado por el mismo token |
| 5. Invariantes post-APPLY | **PASS**, 0 discrepancias |
| 6. Tests y smoke | **Smoke PASS**; PHPUnit **no ejecutable** (límite declarado en §6.1) |
| 7. Auditoría y commit | este documento + 2 artefactos |

**No se encontró ninguna regresión.** Tampoco se encontró evidencia positiva de ausencia de regresión
en el camino de búsqueda, porque la compuerta que la daría está bloqueada por credencial. Las dos cosas
se reportan por separado a propósito.

Nada se arregló en esta ronda, no había nada que arreglar, y si lo hubiera habido el contrato ordena
parar y documentar en vez de corregir.

## 8. Lo que sigue sin autorización

Deploy a producción, merge a `main`, cualquier mutación adicional de taxonomía, rotación de secretos,
otro APPLY o replay, y cualquier fix a búsqueda/ranking/datos que apareciera durante la validación.

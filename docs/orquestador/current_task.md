# Tarea activa

**TASK-0008 — UAT DEL BUSCADOR PARA EL CLIENTE + REVISIÓN TAXONÓMICA DELEGADA** (Issue #2 comentarios
[`6035726459`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6035726459)
y [`6035827197`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6035827197)).
Abierta desde HEAD `fd2c018`. **Estado: READY_FOR_REVIEW — paquete completo en `docs/uat/`. Sólo
documentación y plantillas: cero cambios de aplicación, Worker, base de datos, taxonomía o secretos.**

## TASK-0008 — paquete de UAT y revisión taxonómica

Dos entregas independientes, ambas en `docs/uat/` (10 archivos), que pueden avanzar en paralelo con
personas distintas. Índice de orientación: `docs/uat/README.md`.

**Entrega A — UAT del buscador** (contrato `6035726459`):
`GUIA_PRUEBAS_BUSCADOR_CLIENTE.md` (guía para revisor no técnico, 8 grupos de consultas, escala 5→1
más `N/A`), `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv` y su gemelo `.md` (23 campos exigidos, 45 filas:
35 consultas propuestas + 10 en blanco para el cliente, validado con `Import-Csv`),
`GUIA_CLASIFICACION_RESULTADOS_UAT.md` (triage técnico con árbol de decisión) y
`RESUMEN_UAT_CLIENTE_TEMPLATE.md` (decisión final `ACCEPTED` / `ACCEPTED_WITH_OBSERVATIONS` /
`REQUIRES_CORRECTIONS`).

**Entrega B — revisión taxonómica del pasante** (contrato `6035827197`):
`GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md` con las 13 secciones pedidas y el texto listo para
asignar la tarea, `FORMATO_REVISION_TAXONOMICA_PASANTE.csv` y su gemelo `.md` (20 campos exigidos,
validado) y `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`.

### Lo que se verificó contra el sistema real, en vez de redactarse

El contrato prohíbe explícitamente inventar acciones de UI, así que las guías se escribieron **después**
de leer el código:

- **La pantalla de pruebas no es un buscador con caja de resultados**, es un chat (*"CIRA — Asistente
  CPV"*, `public/cira-test/index.html`) que llama a un webhook de **n8n**, con botones de atajo y
  *"Nueva conversación"*. La guía indica reiniciar la conversación antes de cada consulta, porque el
  chat arrastra contexto y eso contaminaría la prueba. URL verificada: `200`.
- **La cola real de TERM→CPV tiene sólo DOS acciones de decisión**: *Aprobar* y *Rechazar*
  (`ManagesTaxonomyRelationReview`), ambas con *Motivo* obligatorio y auditado. Las otras tres decisiones
  del vocabulario del cliente —`NEEDS_CONTEXT`, `ESCALATE`, `POSSIBLE_NEW_CATEGORY`— **no tienen botón**,
  y la guía lo dice así: se registran sólo en la planilla y la fila queda en *"En revisión"*.
- **Existen acciones masivas** (*"Aprobar seleccionadas"*). La guía advierte explícitamente que no se
  usan, porque el botón es real y con 9.282 filas pendientes la tentación también.
- Permisos reales: *Aprobar* exige `taxonomy_publish` (publica al buscador en vivo), *Rechazar* exige
  `taxonomy_edit_relations`. Documentado, incluido que algunos botones pueden no aparecer.
- Conteos de taxonomía del contrato (9.749 / 212 / 17 / 9.282 / 238) **corroborados** contra la base
  viva: coinciden exactamente.
- **No existe ninguna expectativa de negocio aprobada** en `docs/` (verificado), así que
  `Empresa_esperada` queda en blanco para que lo complete el cliente, como el contrato exige.
- Riesgo operativo señalado: si el flujo de n8n está inactivo, **todas** las consultas fallan igual y el
  UAT se ve como una falla catastrófica del buscador. Queda como chequeo previo de CPV/SISTEG. **No se
  invocó el webhook** desde esta tarea.

**TASK-0007 permanece CLOSED** (comentario `6035642794`, HEAD `fd2c018`). Esta tarea no toca nada de su
estado.

---

## TASK-0007 — APPLY ATÓMICO POR LOTE (CERRADA)

**CLOSED/PASS** en el comentario
[`6035642794`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6035642794).
Abierta desde HEAD `4e671fa`. **APPLY/PUBLISH real EJECUTADO en staging el 2026-10-07, validación
post-APPLY COMPLETA, ninguna compuerta abierta.**

## Cierre — APPLY real ejecutado y validado (`6032819854` + `6032959069`)

**El APPLY/PUBLISH atómico real se ejecutó.** El dueño autorizó en la conversación del orquestador y
éste registró la autorización en Issue #2 comentario
[`6032819854`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6032819854),
citando entorno `staging`, el conjunto exacto `[491,492,493,494,495,629,630,631,632,1688,1689,1690]` y
el fingerprint `eb7d1467…f3702`. La procedencia se registró con honestidad según el protocolo del
comentario `6032759610`: el registro **no** finge que el dueño lo escribió en GitHub. Esa referencia es
la que quedó grabada en `authorization_reference` de las 12 filas y de las 12 filas de auditoría.

`BATCH_APPLIED` — 12 propuestas en 11 unidades, una sola transacción, `blocker = null`. Estado publicado:
10 candidatos = 3 `published` + 7 `context_required`; 2 relaciones `rejected`; conceptos 81 → **82**;
TERM→CONCEPT 142 → **145**; TERM→CPV **9749 sin cambios**; `PENDING_APPLY` 12 → **0**, `APPLIED` 0 →
**12**; `SUPERSEDED` 3 y `ABORTED` 0 sin moverse. Concepto nuevo **#4819** (`refinería` / `refinery`),
TERM→CONCEPT nuevos **#1191/#1192/#1193**, auditoría **#5249…#5260**. **#420/#421/#422 intactas.**

Fingerprint de taxonomía **post**-APPLY: **`e1087a481ab15a45936519d2b0f6190e2e48d7f1c05e04cf87c0d1c703db9b5b`**
(el pre-APPLY era `c236bc51…`; difieren porque el grafo publicado cambió, como exige el contrato).

Validación post-APPLY del comentario `6032959069`: **todos los pasos PASS.** 18/18 invariantes sin
discrepancias; smoke 11 rutas, cero 500/503, cero errores de aplicación en el log.

**Regresión congelada de 32 queries: PASS** (comentario `6034438914`). Estuvo bloqueada dos veces y
ninguna se resolvió fabricando un verde: primero `BLOCKED_AUTH_TOKEN_UNAVAILABLE` (`6033475804`), luego
`BLOCKED_EXISTING_DEBUG_TOKEN_NOT_ACCESSIBLE` al intentar usar el token existente (`6033535873`) — por
diseño el plaintext sólo vive en el secret store de Cloudflare y en el `sessionStorage` del navegador
del admin. Se desbloqueó cuando el dueño autorizó rotar **únicamente** `DEBUG_TOKEN` (`6033692919`) y
ejecutó la rotación y la corrida él mismo. **Este agente no rotó ningún secreto en ningún momento de
TASK-0007.** El resultado se verificó de forma independiente: 32/32 queries, **288 campos comparados, 0
diffs**, y el snapshot resulta byte-idéntico a la baseline (`f416f839…`), que es la salida esperada de un
`--save` sin cambios. La baseline congelada **no se tocó**.

**Checks dirigidos end-to-end: PASS y coincidentes con la predicción.** `pipeline`, `refinery` y
`refinería` devuelven `canonical_concepts: []` y `cpv_relations: []` contra el Worker real — exactamente
lo que la sonda del lado-datos había predicho leyendo el predicado `r.status = 'approved'` de
`canonical-expansion.ts`. Las tres sí devuelven candidatos (20/27/17) por otras señales, así que la
búsqueda funciona; la capa de taxonomía todavía no aporta nada para esos términos porque sus relaciones
CPV siguen en `candidate`/`needs_review` y nadie autorizó aprobarlas.

**PHPUnit no fue ejecutable** en esta ronda (`php.exe` bloqueado localmente por Application Control y
`composer install --no-dev` en staging): límite declarado y no bloqueante, porque no hubo un solo cambio
de runtime desde el 197/197 de la ronda 3.

**No queda ninguna compuerta abierta de TASK-0007.** El cierre formal es acto del orquestador.

Detalle completo: **`audit/phase7_task0007_post_apply_closure_2026-10-07.md`**, más cuatro artefactos
sin secretos: `task0007_batch_apply_result_2026-10-07.json` (ejecución, sin editar),
`task0007_post_apply_closure_2026-10-07.json` (invariantes y sonda lado-datos),
`regression_post_apply_2026-10-07.json` (snapshot de las 32 queries) y
`task0007_targeted_search_post_apply_2026-10-07.json` (los tres checks end-to-end).

Sin autorización: producción, merge a `main`, más mutaciones de taxonomía, rotación de secretos, otro
APPLY/replay, y fixes a búsqueda/ranking/datos.

---

## Ronda 3 — re-audit `6015273402` (`CORRECTIONS_REQUIRED / FINAL EXECUTION-PATH HARDENING`)

> Histórico: lo de abajo describe el estado al cierre de la ronda 3, **antes** de la autorización del
> dueño y del APPLY real. Ver la sección de cierre, arriba, para el estado actual.

El orquestador aceptó las tres correcciones de la ronda 2 y el manifiesto/preflight regenerados, pero
encontró **dos agujeros residuales del camino de ejecución**. Los dos se aceptan **sin reservas**.

1. **El `applyBatch()` público podía saltearse el manifiesto en staging.** La compuerta de
   autorización corría dentro de `if ($manifest !== null)`, así que una invocación **directa** del
   servicio —`applyBatch($ids, $referencia)` desde Tinker, otro comando o un llamador futuro— pasaba la
   compuerta de entorno, abría la transacción y podía ejecutar **sin manifiesto, sin hash autorizado y
   sin atadura al conjunto autorizado**. No era teórico: el propio test de la ronda 2 ponía
   `APP_ENV=staging` y llamaba a `applyBatch()` sin manifiesto, con éxito. El CLI estaba bien, pero una
   compuerta que protege datos compartidos reales no puede vivir sólo en un envoltorio de CLI.
   **Corregido en el único camino de escritura:** en todo entorno **operativo** el manifiesto es
   obligatorio (`BATCH_MANIFEST_REQUIRED`, antes de la transacción y antes de cualquier lock), y como
   entonces está garantizado no nulo, el hash autorizado y la igualdad exacta del conjunto **siempre**
   corren ahí. La exigencia se deriva de `BATCH_EXECUTABLE_ENVIRONMENTS`, no de
   `environmentCanExecuteBatch()`, así que un entorno operativo nuevo la hereda sin que nadie se
   acuerde. La excepción sin manifiesto queda sólo para `testing` —donde cada lote vive en una
   transacción que nunca commitea— y está documentada como **SÓLO DE TESTS**.
2. **El hash se validaba DESPUÉS de la confirmación humana del CLI.** El CLI sólo comprobaba que
   `--expect-manifest-fingerprint` no estuviera vacía antes de imprimir el banner destructivo y
   preguntar; `MALFORMED` y `MISMATCH` vivían en `applyBatch()`, invocado **después** de esa
   confirmación. El servicio validaba bien, pero se le podía pedir a una persona que **confirmara un
   APPLY destructivo** con un hash mal copiado o equivocado para rechazarlo recién después — y el audit
   de la ronda 2 **sobreafirmó** al decir que la compuerta corría «antes de cualquier confirmación».
   **Corregido sin duplicar validadores:** hay **uno solo**,
   `ReviewedProposalService::authorizationFingerprintBlocker()`, ahora público y de solo lectura. El
   CLI lo llama antes del banner y de cualquier prompt; el servicio lo vuelve a llamar antes de la
   transacción por defensa en profundidad. El CLI rechaza **sin preguntar** hash faltante, malformado o
   distinto.

**Tests: 197/197 PASS, 1.432 aserciones, cero fallos, en un solo proceso** (lote 58 + group-locking 7 +
service 41 + confirmation 47 + preflight 24 + supersession 20; las cinco heredadas sin editar una
línea). **Suite del lote: 50 → 58 tests.** Los 8 nuevos cubren staging sin manifiesto → `BATCH_MANIFEST_REQUIRED`
con cero escrituras; staging con manifiesto pero sin hash → `MISSING`; el camino sin manifiesto
alcanzable sólo en `testing`; hash malformado / distinto / faltante → FAILED **sin llegar nunca al
prompt** (se afirma que el banner destructivo no se imprime); hash correcto → llega a la confirmación
normal, que el test responde «no» (fixture puro, cero escrituras); y un test estructural que fija que
el CLI no tiene validación de forma ni `hash_equals()` propios, que llama a la primitiva del servicio
antes del banner y del `confirm()`, y que el servicio re-valida antes de la transacción.

**Artefactos vigentes:** `audit/task0007_batch_manifest_2026-10-06_r3.json` y
`audit/task0007_batch_preflight_2026-10-06_r3.json` — 12 ids / 11 unidades / `blocker = null` /
`write_statements_observed = 0` / proyección **40** / conteos 10 · 2 · 142 · 81 · 9749 · 15 con 12
`PENDING_APPLY`, 3 `SUPERSEDED`, 0 `APPLIED`, 0 `ABORTED` / última auditoría de taxonomía **#3034**.
`manifest_fingerprint` recién generado: **`eb7d1467…`** (el mismo, porque ninguna ronda tocó lo que el
manifiesto ata ni cómo se hashea).

**HEAD de runtime desplegado a staging: `004d24e`** (workflow run 37458287882, `success`; smoke
`GET /` 200, `/admin/login` 200, las tres pantallas 302 → login 200, sin 500/503).

**APPLY REAL sigue NO AUTORIZADO y NO se ejecutó.**

---

## Ronda 2 — re-audit `6011317053` (`CORRECTIONS_REQUIRED`)

El orquestador encontró **dos defectos del camino de ejecución y un hueco de atadura de autorización**.
Los tres se aceptan **sin reservas**: los tres son alcanzables con el código de la ronda 1 y los tres
amplían lo que el dueño autorizó.

1. **Un manifiesto completo podía ejecutar sólo un subconjunto de sus ids.** `--id` sobrescribía los
   ids tomados del manifiesto y nadie exigía igualdad de conjuntos, así que
   `--manifest=<FULL aprobado> --id=491 --execute` **verificaba con éxito** y ejecutaba sólo #491. Y el
   daño no es «queda una fila sin aplicar»: #491 escribe `taxonomy_term_concepts`, así que tras esa
   ejecución parcial el fingerprint global cambia y las once revisiones restantes pueden quedar
   obsoletas — el conjunto atómico autorizado deja de ser recuperable **como ese conjunto**.
   **Corregido en dos capas:** `--id` queda **prohibido** con `--execute`, y el servicio exige igualdad
   exacta de conjuntos normalizados (`BATCH_MANIFEST_REQUEST_MISMATCH`), con el parámetro de ids
   **obligatorio y sin default** para que nadie pueda omitirlo en el futuro.
2. **Datos compartidos reales se podían ejecutar desde `APP_ENV=local`.** La prueba está en los
   propios artefactos de la ronda 1: se generaron con `generated_in_environment = local` leyendo la
   cola real, o sea que el `local` de esta estación **está conectado al dataset compartido**.
   **Corregido:** `BATCH_EXECUTABLE_ENVIRONMENTS = ['staging']` —único entorno operativo— más
   `BATCH_FIXTURE_TEST_ENVIRONMENT = 'testing'` como excepción de tests automatizados, separadas a
   propósito en vez de mezcladas en una lista. `local` conserva **preview y generación de manifiestos**
   (las dos de solo lectura, y es donde tienen sentido) pero no ejecución; `production` sigue
   prohibido. El CLI es más estricto que el servicio: no acepta la excepción de `testing`.
3. **La autorización del dueño no estaba atada al hash del manifiesto.** Un manifiesto auto-hasheado
   prueba «este archivo no se editó», no «este es el hash que el dueño autorizó».
   **Corregido:** `--expect-manifest-fingerprint` es obligatorio con `--execute`, se valida la forma
   sha256, se compara con `hash_equals()` contra el manifiesto cargado **antes de abrir la
   transacción**, y **no se deduce del archivo** — hay un test estructural que lo fija, porque
   deducirlo colapsaría los dos insumos de confianza en uno.

**Suite del lote: 35 → 50 tests.** Los 15 nuevos cubren subconjunto estricto bloqueado, subconjunto de
un subconjunto, pedir más de lo atado, mismo conjunto en otro orden y con duplicados aceptado,
precedencia tamper > mismatch, `local` bloqueado con su preview intacto, `production` bloqueado,
`staging` pasando la compuerta sobre un lote de fixture, hash coincidente aceptado, y hash faltante /
malformado / distinto rechazados antes de la transacción con cero escrituras — más tres tests de nivel
comando que prueban que `--id` no puede debilitar un manifiesto y que no se ejecuta fuera de staging.

**Artefactos vigentes:** `audit/task0007_batch_manifest_2026-10-06.json` y
`audit/task0007_batch_preflight_2026-10-06.json`. El `manifest_fingerprint` se **regeneró** —el
re-audit pidió no asumir que el anterior siguiera siendo autoritativo— y el valor medido es el mismo,
`eb7d1467…`, porque esta ronda no tocó lo que el manifiesto ata ni cómo se hashea, sino `verify()`.

**HEAD de runtime desplegado a staging: `c1d174e`** (workflow run 37439406937, `success`; smoke
`GET /` 200, `/admin/login` 200, las tres pantallas 302 → login 200, sin 500/503).

**APPLY REAL sigue NO AUTORIZADO y NO se ejecutó.**

---

## Ronda 1 — implementación, manifiesto y preflight de solo lectura

TASK-0006E quedó **`PASS / CLOSED`** en el comentario
[`5994449681`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5994449681).
**TASK-0007 está ABIERTA y el APPLY REAL sigue NO AUTORIZADO** — esta ronda autoriza el código, los
tests, el manifiesto de solo lectura, el preflight de lote contra datos reales y el deploy del runtime
a staging; nada más.

## El hueco semántico que abre la tarea

`dryRunInputFingerprint()` hashea el grafo de conceptos (`taxonomy_canonical_concepts` +
`taxonomy_term_concepts`) y una señal de versión de `taxonomy_concept_relations`. Tres de las unidades
de la cola real mutan justamente esas entradas: #491 inserta un TERM→CONCEPT, #629/#630 crean un
concepto más dos links, y #631/#632 escriben `taxonomy_concept_relations`.

Por lo tanto `apply(491) -> apply(492) -> ...` **no es una estrategia válida**: la primera escritura
que cambia el grafo deja obsoletas a todas las demás, y `apply()` registra la obsolescencia con
`abort()`, que es **terminal**. Como re-congelar está bloqueado por el índice único parcial, un loop
habría **quemado decisiones humanas una por una**. Las 12 propuestas no son 12 ejecuciones
independientes: son **un conjunto revisado contra un snapshot compartido**.

Esto está **demostrado ejecutando**, no argumentado: hay un test que congela dos propuestas fixture
contra el mismo snapshot, aplica la que cambia el grafo y mide que la segunda vuelve `ABORTED` con
`STALE_TAXONOMY_STATE`; y su gemelo que ejecuta el mismo par como lote y las deja las dos `APPLIED`.

## Qué se implementó

- **`applyBatch()`** — una transacción, baseline computado **una vez**, conjunto cargado y bloqueado en
  orden de id, **toda** unidad validada contra ese baseline **antes** de la primera escritura, y cero
  escrituras y **cero ABORTs** si algo bloquea. No es un loop alrededor de `apply()` ni un segundo
  motor de reglas: valida con `evaluateApplicability()`, la misma función que corren `apply()` y
  `preflight()`, con un parámetro nuevo y **opcional** (`?string $baselineFingerprint = null`) que con
  `null` reproduce el comportamiento anterior byte por byte.
- **11 unidades para 12 filas** — #629/#630 es UNA unidad indivisible, el escritor de grupo se llama
  una sola vez, y pedir medio grupo devuelve `BATCH_GROUP_INCOMPLETE` en solo lectura.
- **Manifiesto** (`ReviewedProposalBatchManifest`) — ata ids, decisiones, los dos fingerprints de cada
  propuesta, origen, grupo, unidades, baseline, forma de la cola protegida y alcance, dentro de un
  `manifest_fingerprint` **determinístico** (`generated_at` y el entorno quedan fuera del hash, porque
  si el hash cambiara al regenerar no se podría citar en una autorización). Se verifica **dentro de la
  transacción, con los locks ya tomados**.
- **Lock común de ejecución C2** — un `pg_advisory_xact_lock` único que toman como primera acción
  `applyBatch()` **y** `apply()`. Sin él un apply suelto podría mover el grafo entre la validación y la
  escritura del lote, y los locks de fila no alcanzan porque ese apply puede entrar por una propuesta
  que el lote no pidió. Orden: **ejecución → grupo → filas de propuesta → filas fuente**.
- **`previewBatch()`** — el mismo camino sin fase de escritura, sin locks y midiendo sus propios
  statements. A diferencia de `preflightAll()`, evalúa el CONJUNTO: baseline único, unidades
  deduplicadas y una proyección agregada de **40 filas** con el grupo contado una sola vez (sumar las
  filas del JSON por propuesta daba 49 y era incorrecto).
- **Dos comandos**: `taxonomy:reviewed-proposal-batch-manifest` (solo lectura) y
  `taxonomy:apply-reviewed-proposal-batch`, cuyo **modo predeterminado es el preflight de solo
  lectura** — aplicar exige `--execute` más manifiesto, `--authorized-by` y `--expect-environment`
  coincidente con el entorno real. `production` está prohibido **en el servicio**
  (`BATCH_ALLOWED_ENVIRONMENTS = ['local','testing','staging']`).

**Sin migración:** el lote no agrega columnas ni estados.

## Resultado del preflight de lote REAL (solo lectura)

12 propuestas aceptadas, **11 unidades**, **cero bloqueos**, `write_statements_observed = 0`, baseline
`c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2` — el mismo del cierre de
TASK-0006E. Write-set proyectado **40 filas** y conteos proyectados idénticos a la PARTE 7 del
comentario: 81→82 conceptos, 142→145 TERM→CONCEPT, **9749 TERM→CPV sin cambio**, 10 candidatos
`pending` → 3 `published` + 7 `context_required`, 2 relaciones → 2 `rejected` y **0 approved**,
`PENDING_APPLY` 12→0, `APPLIED` 0→12, `SUPERSEDED` 3 sin cambio, `ABORTED` 0.

**El lote real NO se ejecutó.** Las 12 siguen `PENDING_APPLY`, los 10 candidatos `pending`, las 2
relaciones `candidate`, 0 filas con `applied_at`, y #420/#421/#422 intactas.

Detalle en
[`audit/phase7_task0007_batch_apply_2026-10-05.md`](../../audit/phase7_task0007_batch_apply_2026-10-05.md);
texto verbatim en [`tasks/0007-atomic-batch-apply.md`](tasks/0007-atomic-batch-apply.md); artefactos en
[`audit/task0007_batch_manifest_2026-10-05.json`](../../audit/task0007_batch_manifest_2026-10-05.json) y
[`audit/task0007_batch_preflight_2026-10-05.json`](../../audit/task0007_batch_preflight_2026-10-05.json).

## Qué falta para ejecutar de verdad

Una autorización humana nueva y explícita en Issue #2 que cite el conjunto exacto de ids
`[491, 492, 493, 494, 495, 629, 630, 631, 632, 1688, 1689, 1690]` y el `manifest_fingerprint`
**`eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702`** (alcance `FULL_PENDING_QUEUE`),
más el entorno autorizado. Un lote sin bloqueos significa «hoy nada lo impide», nunca «aprobado para
ejecutarse».

Si entre hoy y ese día cambia cualquier cosa de la cola o del grafo, el manifiesto **se rechaza solo**
(`BATCH_QUEUE_DRIFT` / `BATCH_BASELINE_STALE`) con cero escrituras, y hay que regenerarlo y
reautorizarlo — que es lo que hace que la autorización signifique algo.

**HEAD de runtime desplegado a staging: `7d015e2`** (workflow run 37360603242, `success`; smoke
`GET /` 200, `/admin/login` 200, las tres pantallas de taxonomía 302 → login 200, sin 500/503).

---

# Historial — TASK-0006E (CERRADA / PASS en `5994449681`)

## Compuerta de cierre (comentario `5993828105`) — preflight final de solo lectura

El dueño completó las **tres re-revisiones humanas** por la UI autenticada, creando **#1688**
`petroleum`, **#1689** `crude oil` y **#1690** `oil and gas`, las tres `CONTEXT_REQUIRED`, con
`reviewer_id=3`, `prepared_by_actor_type=human_reviewer`, `prepared_via=http` y auditoría #3032/#3033/
#3034 (`actor_type=user`, `user_id=3`). **El agente no participó de esas decisiones.**

Esta ronda corrió **un** preflight de solo lectura y **nada más**: cero APPLY, cero publicación, cero
supersesión, cero re-freeze, cero cambios de esquema/runtime, cero cambios de datos.

**Resultado: las 12 propuestas vivas son `READY_TO_APPLY`. Cero bloqueos.**
#491, #492, #493, #494, #495, #629, #630, #631, #632, #1688, #1689, #1690 —
`write_statements_observed: 0`.

Fingerprint actual exacto: `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`.

Las tres históricas se reportan **aparte** como `ALREADY_SUPERSEDED`, con `would_apply_abort_with = null`
y write-set **0** — la prueba de que un `apply()` sobre ellas informa y se va **sin pisar el rastro**.
Siguen con sus fingerprints de 2026-10-01, su revisor y su `reviewed_at` originales, y **sin linaje
retroactivo** (0 filas en toda la tabla con `superseded_by_proposal_id` o `supersedes_proposal_id`).

Grupo bilingüe #629/#630: coherente, `member_payload_fingerprint_valid = {629: true, 630: true}`, sin
miembros manipulados. #631/#632 son `REJECT`, así que `relation_validation` es `null` — **eso es el
contrato, no un hueco**: el camino de REJECT retorna *antes* de la validación server-side porque rechazar
no publica nada en el grafo; lo que sí se verificó es que su payload es válido y que las dos relaciones
siguen en `candidate`.

Conteos protegidos, idénticos al recuento independiente del orquestador: **10 / 2 / 142 / 81 / 9749 / 15
propuestas / 12 `PENDING_APPLY` / 3 `SUPERSEDED` / 0 aplicadas / 0 abortadas**, 0 candidatos publicados,
0 relaciones aprobadas. Y **ningún candidato tiene más de un `PENDING_APPLY`**: 263/264/265 pasaron de 1
supersedida a 1 supersedida + 1 viva, que es justo lo que el índice único parcial permite porque sólo
cuenta `PENDING_APPLY`.

**Proyección de un APPLY futuro (no es una autorización):** 40 filas — +1 concepto (81→82), +3
`taxonomy_term_concepts` (142→145), **los 10 candidatos resueltos** (3 `published`, 7
`context_required`), las 2 relaciones a `rejected`, las 12 propuestas a `APPLIED`, 12 filas de auditoría,
y **cero** relaciones TÉRMINO→CPV. Un APPLY cerraría la cola real **por completo**.

**El dueño volvió a elegir `CONTEXT_REQUIRED`**, y eso no vuelve innecesaria la supersesión
retroactivamente: la pregunta se le devolvió **abierta**, con `oleoducto` y `gasoducto` ya existiendo y
registrados en el delta, y la respondió contra el estado actual. Una decisión revalidada contra el estado
vigente y una decisión caducada que coincide en el texto **no son lo mismo** para el contrato C2 — la
primera es aplicable, la segunda no lo era.

**Lo que falta no es otra verificación técnica.** La cola viva ya no tiene nada obsoleto ni bloqueante
(`NEEDS_REVALIDATION = 0`), así que se cumple la condición de `5954835892` para que TASK-0007 **pueda**
abrirse. Falta la **autorización humana de ejecución**, que es un acto separado por diseño desde
TASK-0004. Un `READY_TO_APPLY` es una foto sin lock: dice «hoy nada lo impide», nunca «aprobado para
ejecutarse».

Detalle completo en la **§10** de
[`audit/phase6_task0006e_supersession_2026-10-02.md`](../../audit/phase6_task0006e_supersession_2026-10-02.md);
artefacto en
[`audit/task0006e_final_preflight_2026-10-05.json`](../../audit/task0006e_final_preflight_2026-10-05.json).

Detalle en
[`audit/phase6_task0006e_supersession_2026-10-02.md`](../../audit/phase6_task0006e_supersession_2026-10-02.md);
texto verbatim en [`tasks/0006e-stale-supersession.md`](tasks/0006e-stale-supersession.md); artefacto del
preflight posterior en
[`audit/task0006e_preflight_after_supersession_2026-10-02.json`](../../audit/task0006e_preflight_after_supersession_2026-10-02.json).

## Qué resolvió, y por qué hacía falta un estado nuevo

#420 `petroleum`, #421 `crude oil` y #422 `oil and gas` estaban **obsoletas**: el grafo de conceptos
cambió después de que se revisaran (se crearon `oleoducto` #2890 y `gasoducto` #2891 el 2026-10-01
~13:07; las tres se revisaron ~09:48–09:50).

Eso era un **callejón sin salida**, y conviene ver la forma exacta: `apply()` registra la obsolescencia
con `abort()`, que es **terminal**; re-congelar está bloqueado por el índice único parcial
`WHERE status = 'PENDING_APPLY'`; y editar el fingerprint a mano está prohibido (OPCIÓN D del diseño).
O sea: **no existía ninguna forma de volver a pedirle la decisión a un humano sin destruir la
anterior**. Lo que hacía falta no era saltear la revalidación —el gate está bien y el cambio de estado
es sustantivo para esos términos— sino **volver a preguntar sin borrar la respuesta vieja**.

## La transición real

| Propuesta | Candidato / término | Antes | Después | Sucesor |
|---|---|---|---|---|
| #420 | 263 / `petroleum` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |
| #421 | 264 / `crude oil` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |
| #422 | 265 / `oil and gas` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |

**0 campos inmutables modificados** en las tres — verificado por comparación **cruda** (sin casts) de la
fila completa, 17 campos por propuesta más el estado del candidato. «No destructiva» es la propiedad
entera del diseño, así que no se confía en que el UPDATE liste pocas columnas.

`supersession_actor_type = agent` y **`supersession_by_id = NULL`**: la ejecutó el agente bajo
autorización del dueño, y atribuirla a la cuenta de una persona que no la ejecutó repetiría el error de
procedencia que TASK-0006C reparó.

**Delta de estado capturado en las tres:** fingerprint congelado `1d0eb041f642…` → actual
`c236bc5159ae…`, con los dos conceptos creados después de la revisión **nombrados** — #2890
`oleoducto / oil pipeline` (13:07:11) y #2891 `gasoducto / gas pipeline` (13:07:30). Se **persiste**
porque el fingerprint es un hash: si no se guarda en el instante de la transición, la explicación de por
qué la decisión caducó se pierde para siempre.

## Los candidatos vuelven a la cola, sin tocar ninguna regla de visibilidad

263/264/265 siguen `pending`, sin propuesta viva, con el slot `PENDING_APPLY` **libre** y `freezeReview`
disponible otra vez. No hizo falta cambiar la regla de visibilidad de TASK-0006D —
`liveFrozenProposal()` busca una propuesta **`PENDING_APPLY`**, así que una `SUPERSEDED` no bloquea
nada—, y eso es la señal de que ese endurecimiento estaba bien planteado. La página de detalle de cada
candidato explica que hubo una revisión anterior, cuál, cuándo caducó, y que **es evidencia histórica,
no un punto de partida obligado**.

## Una compuerta que había que cerrar explícitamente

`SUPERSEDED` no es `APPLIED` ni `ABORTED`, así que **sin una compuerta propia** una propuesta
supersedida habría caído por la cadena de validación y `apply()` la habría **ABORTADO, pisando el rastro
recién grabado**. Se cerró con una compuerta terminal en la cadena **compartida** (cero escrituras) más
una prohibición **a nivel de base de datos** en el trigger. Probado contra la base real.

## Estado protegido y preflight posterior

Conteos sin cambios: **10 / 2 / 142 / 81 / 9749 / 12 propuestas / 0 aplicadas / 0 abortadas**, 0
candidatos publicados, 0 relaciones aprobadas. Los únicos deltas: `PENDING_APPLY` 12 → 9 y `SUPERSEDED`
0 → 3. Las otras nueve propuestas: **0 con status cambiado**.

Preflight de solo lectura posterior: **9 `READY_TO_APPLY` / 3 `ALREADY_SUPERSEDED` / 0
`NEEDS_REVALIDATION`**, `write_statements_observed: 0`. Las tres ya **no** reportan
`STALE_TAXONOMY_STATE` ni `READY_TO_APPLY`. **La cola viva quedó sin ninguna propuesta
obsoleta/bloqueante** — la condición que el orquestador puso para que TASK-0007 pueda abrirse algún día,
detrás de una autorización de APPLY nueva y explícita que **no existe**.

## STOP respetado — la re-revisión humana no se hizo ni se preparó

Instrucción explícita: el agente **no** debe tomar las decisiones semánticas nuevas sobre `petroleum` /
`crude oil` / `oil and gas`. Se respetó al pie de la letra: **no se congeló ninguna propuesta** para
263/264/265, **no se preseleccionó nada**, y la supersesión es **sin sucesor** precisamente para que no
haya una decisión redactada esperando un clic. El motivo durable describe **por qué** caducó la
revisión, nunca **qué** debería decidirse ahora.

**El dueño re-revisará los tres candidatos personalmente por Filament** contra el grafo actual, y puede
elegir `CONTEXT_REQUIRED` otra vez o cualquier otra decisión válida.

## Tests y staging

`ReviewedProposalSupersessionTest` **20/20** (nueva, 140 assertions) + las heredadas de unidad
**119/119 sin editar una línea** + `TaxonomyReviewedProposalResourceTest` **12/12** (incluye un test
nuevo que abre la pantalla de detalle de una propuesta **realmente supersedida**, porque la sección
nueva mete listas anidadas en un `KeyValueEntry` y eso es la forma exacta del incidente 503/500 de
TASK-0002) + 2 nuevos de UX de candidatos. Único fallo local: el gap preexistente de `ext-intl`.

Staging: la transición corrió con **`451ba11`** desplegado (run `success`), con smoke limpio **antes y
después**; el HEAD final **`ead559f`** —que agrega el test de render de una fila supersedida y la
documentación— también se desplegó (run `success`) y smokeó limpio, porque el workflow deliberadamente
**no** ignora `tests/**`. Migración aplicada antes de desplegar; el `migrate --force` del deploy es un
no-op.

> **Limitación de verificación, declarada:** después de ejecutar la transición y correr toda la
> validación, la política de Application Control de esta máquina pasó a **bloquear `php.exe`** (la misma
> que ya bloqueaba `php_intl.dll`). Todo lo afirmado arriba se midió **antes** de ese bloqueo. Lo único
> posterior es el commit de documentación, el despliegue del HEAD final y su smoke, que no necesitan PHP
> local. El último escrito a datos reales fue la supersesión.

---

## TASK-0006D (cerrada, histórico) — Preflight + UX de candidatos + diseño de propuestas obsoletas

(Issue #2 comentario
[`5949253156`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5949253156)).
Abierta desde HEAD `510400a`. **Estado: `PASS / CLOSED`** (comentario `5954835892`, HEAD revisado
`af4abec`).

Existe para quitar la incertidumbre que queda **antes** de abrir TASK-0007. **TASK-0007 sigue sin
abrir y APPLY/PUBLICACIÓN sigue NO AUTORIZADO.** Cero mutaciones de datos reales en esta ronda.

## Ronda 2 — re-audit `5952211890`: gate de integridad de grupos bilingües

El re-audit
[`5952211890`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5952211890)
(HEAD revisado `f9caf15`) **aceptó las partes 1 a 5** y bloqueó el cierre por **un defecto de seguridad
de ejecución** en el camino agrupado. **El bloqueo se acepta sin reservas: es real y alcanzable.**

**El defecto.** `evaluateApplicability()` validaba el `payload_fingerprint` **sólo de la propuesta de
entrada**. `evaluateBilingualGroup()` le validaba a cada hermano status, fingerprint de estado, drift de
fuente e identidad bilingüe — **pero nunca su propio `payload_fingerprint`**. Y
`writeBilingualGroupCreateNew()` publica y marca `APPLIED` a **todos** los hermanos pendientes. O sea:
`apply(#629)` podía escribir #630 **sin revalidar el payload inmutable de #630** en el momento de la
ejecución. Que el preflight hubiera evaluado #630 por separado no cerraba el hueco, por la razón exacta
que da el orquestador: el preflight es **una foto**, y `apply()` tiene que revalidar bajo lock todo lo
que está por escribir.

Dos aclaraciones de procedencia que **no** atenúan el defecto: no es corrupción de datos actual (los
payloads reales de #629/#630 estaban y siguen válidos), y el hueco venía del **diseño original** del
grupo (sección D de TASK-0006B) — la extracción de TASK-0006D lo heredó sin corregirlo, y encima el
audit de la ronda 1 afirmó que `apply()` «revalida TODO otra vez con locks», lo cual para el camino
agrupado **era falso**. Se corrigen las dos cosas.

**Lo corregido:**

- **A — integridad por miembro.** El fingerprint de **cada miembro pendiente** se revalida como
  **primera** compuerta de ese miembro, antes de leer un solo campo de su payload o de su fila fuente.
  Va primero por una razón concreta: `decision`, `proposal_type` y `taxonomy_state_fingerprint` —que las
  compuertas siguientes consultan— son **tres de los 9 campos** que cubre el fingerprint. Un miembro
  manipulado da `TAMPER_DETECTED` nombrando la propuesta ofensora, y **entrar por cualquiera de los dos
  hermanos da el mismo resultado**.
- **Defecto adicional que esto dejó al descubierto.** `payload_fingerprint_valid` se **deducía** del
  bloqueo, así que con el tamper en un hermano habría reportado como manipulada la fila de entrada
  **estando intacta**. Ahora la validez de la entrada se **registra como evidencia** en su propia
  compuerta, no se infiere.
- **B — semántica terminal de grupo.** Un bloqueo terminal ahora aborta el **grupo completo**,
  atómicamente y con **una fila de auditoría por miembro**. Antes abortaba sólo la entrada y dejaba al
  hermano `PENDING_APPLY` dentro de un grupo ya fallido: **varado en silencio**, aparentemente
  aplicable, cuando aplicarlo solo crearía el concepto con un único término adjunto. Por qué todo
  bloqueo terminal de un miembro es grupal: un grupo describe **una** convergencia indivisible, así que
  no existe un subconjunto que siga siendo correcto aplicar. **`HUMAN_CONFIRMATION_REQUIRED` sigue NO
  siendo terminal y no aborta nada.**
- **D — evidencia en el informe.** Validez de payload **por miembro** (`null` = no evaluado, nunca
  «válido»), ids manipulados, y un agregado de grupo. Y el write-set de un bloqueo terminal agrupado
  pasa a declarar **2 filas por miembro pendiente**, no 2: decirlo mal haría parecer barato un
  `apply()` «de prueba» que quemaría varias decisiones humanas de una vez.
- **E — consistencia del diseño.** `supersession_state_delta` estaba exigido en la prosa pero **omitido
  de la lista de columnas**. Reconciliado, con la razón por la que **tiene** que persistirse (el
  fingerprint es un hash: si el delta no se guarda cuando ocurre la supersesión, se pierde para siempre
  y la confirmación del sucesor queda sin la evidencia que la hace significativa).

**Un test heredado tuvo que reescribirse, y el motivo es en sí mismo evidencia de que la corrección
funciona:** volvía al hermano no aplicable mutando su `decision`, que **es** uno de los 9 campos del
fingerprint, así que ahora produce (correctamente) `TAMPER_DETECTED`. Se cambió a mover su `status`, que
**no** está cubierto.

**Tests de la ronda 2:** `ReviewedProposalPreflightTest` **24/24** (191 assertions, 18 → 24) y las
suites heredadas de C2 reejecutadas **completas** contra el código corregido: **95/95**. Esto último
importa en concreto, porque la corrección B cambió el desenlace de un camino que esas suites ya
ejercitaban (el drift de un miembro ahora aborta **los dos**), así que su verde dice que el cambio es
compatible con todo lo aprobado y no sólo con sus propios tests.

**Staging de la ronda 2:** HEAD de runtime `4eee136` (run `success` para ese sha exacto); `/` 200,
`/admin/login` 200, las tres pantallas 302 → login 200, **sin 500/503**, sin migraciones.

**Re-ejecución del preflight sobre las 12 reales:** clasificación **idéntica** (3 obsoletas / 9 listas),
cero escrituras, y evidencia nueva de grupo — #629 y #630 reportan ahora
`member_payload_fingerprint_valid = {629: true, 630: true}`: la integridad del grupo bilingüe real está
**verificada y registrada**, no supuesta.

**Nada de datos reales se tocó:** #420–#422 y #629/#630 sin mutar, conteos preservados
(10 / 2 / 142 / 81 / 9749 / 12 / 0 aplicadas, 0 abortadas), cero APPLY/PUBLISH, sin migraciones.

Detalle completo en
[`audit/phase6_task0006d_preapply_preflight_2026-10-02.md`](../../audit/phase6_task0006d_preapply_preflight_2026-10-02.md);
texto verbatim en [`tasks/0006d-preapply-preflight.md`](tasks/0006d-preapply-preflight.md); diseño de
la PARTE 3 en
[`designs/0006d-stale-proposal-supersession.md`](designs/0006d-stale-proposal-supersession.md);
artefacto del preflight real en
[`audit/task0006d_preflight_12_proposals_2026-10-02.json`](../../audit/task0006d_preflight_12_proposals_2026-10-02.json).

## PARTE 1 y 4 — Preflight de solo lectura de las 12 propuestas

**El problema que resuelve.** Hasta acá, la única forma de saber si una propuesta congelada seguía
siendo aplicable era llamar a `apply()` — y `apply()` es **destructivo cuando falla**: registra
obsolescencia, tamper, drift y relación inválida con `abort()`, que pasa la propuesta a `ABORTED` de
forma **terminal**. Como re-congelar está bloqueado por el índice único parcial, usar `apply()` como
sonda **quema una decisión humana sin recuperación posible**. El orquestador lo dijo así: «apply() is
NOT a safe preflight API».

**Reutilización, no reimplementación.** No se escribió un segundo validador: se **extrajo el único que
hay**. Toda la cadena de validación de `apply()` vive ahora en
`evaluateApplicability(proposal, lockRows)`, que usan los dos — `apply()` con `lockRows: true` y
`preflight()` con `false`. **La única diferencia entre los dos es cómo se LEEN las filas fuente** (con
o sin `lockForUpdate()`); qué se valida, en qué orden y con qué desenlace es un solo cuerpo de código.
Tres mecanismos impiden que divergan: `applyAbortReasonForBlocker()` es el único lugar que mapea cada
bloqueo a su `ABORT_*` y **lanza** si falta uno; un test estructural comprueba que los tres métodos de
escritura de `apply()` **no contienen ni una llamada a `abort()`** (si no pueden abortar, todo aborto
sale de la cadena compartida); y un test de paridad por vocabulario verifica cada bloqueo para los dos
tipos de origen. **Ninguno llama a `apply()`**, como pidió el orquestador.

**Resultado real (solo lectura, `write_statements_observed: 0`):**

| Categoría | Propuestas |
|---|---|
| `READY_TO_APPLY` (9) | #491, #492, #493, #494, #495, #629, #630, #631, #632 |
| `NEEDS_REVALIDATION` (3) | #420, #421, #422 — `STALE_TAXONOMY_STATE` |
| `BLOCKED_FOR_OTHER_REASON` (0) | — |

Lo que **no** se dio por supuesto: los **12** `payload_fingerprint` son válidos (cero tamper, incluidas
las cuatro que pasaron por la anulación y re-confirmación de TASK-0006C); las **8** confirmaciones
humanas están presentes y las 8 son `http`; el concepto destino de #491 **existe** de verdad; el grupo
bilingüe #629/#630 es **coherente** (2 miembros, mismo fingerprint, misma identidad ES/EN, sin drift);
y las relaciones #61/#62 siguen `candidate`.

**Hallazgo que el preflight hizo visible.** #491 mapea el término `pipeline` (en) al concepto **#2890**
— uno de los dos conceptos cuya creación dejó obsoletas a #420–#422 (#2890 `oleoducto`/`oil pipeline`
13:07:11, #2891 `gasoducto`/`gas pipeline` 13:07:30; #420–#422 revisadas ~09:48–09:50 y #491 a las
13:08). Corrobora **con datos** que el cambio de estado que invalidó esas tres es **sustantivo para
esos mismos términos**: `petroleum`, `crude oil` y `oil and gas` se marcaron «demasiado genéricos para
un mapeo directo» cuando `oleoducto`/`gasoducto` no existían. El gate no está siendo pedante.

**Lo que un `READY_TO_APPLY` NO significa:** el preflight es una foto **sin lock**, válida en el
instante en que se tomó. No reserva nada y no autoriza nada; `apply()` revalida todo otra vez con
locks. Significa «hoy no hay nada que lo impida», nunca «aprobado para ejecutarse».

## PARTE 3 — Diseño del tratamiento de propuestas obsoletas (solo diseño)

**Recomendación: OPCIÓN A** (supersesión no destructiva + sucesor), con el sucesor **opcional** y el
diff de estado como **requisito de entrega**, no como mejora. Razones: no toca `apply()` (un sucesor
`agent` + `requires_human_confirmation` ya lo rechaza la compuerta **existente**); compatible con el
índice único parcial **por construcción** (el predecesor sale de `PENDING_APPLY` antes de que el
sucesor entre, en una transacción); y **la OPCIÓN C es un subconjunto de la A**, no una alternativa —
supersedir *sin* sucesor devuelve el candidato a la UI de revisión normal, que la PARTE 2 de esta misma
tarea ya hace reaparecer sola. La OPCIÓN B se rechaza: convertiría el gate de obsolescencia en dos
preguntas con dos fuentes de verdad. La OPCIÓN D queda registrada como **NO ACEPTABLE**.

Condición explícita: **sin el diff, la OPCIÓN A es peor que la C**, porque presentaría una decisión ya
redactada pidiendo confirmarla sin mostrar qué cambió — el consentimiento ceremonial que el orquestador
advirtió.

**Para los tres casos reales: supersesión SIN sucesor.** La pregunta que corresponde no es «¿confirmás
la decisión vieja?» sino «¿`petroleum` sigue siendo demasiado genérico ahora que existen `oleoducto` y
`gasoducto`?». Eso es una revisión nueva, no una confirmación.

**Nada de esto se implementó ni se ejecutó.** La transición de datos sobre #420/#421/#422 requiere
autorización explícita y separada en un comentario posterior del Issue #2.

## PARTE 6 — Tests y staging

**Tests: 161 de unidad + 43 de Filament.** `ReviewedProposalPreflightTest` **18/18** (nuevo) y
`TaxonomyCandidateConceptLinkReviewTest` **19/19** (6 nuevos/reescritos). Las **143 regresiones
heredadas pasan sin editar una sola línea** —`ReviewedProposalServiceTest` 41/41,
`ReviewedProposalConfirmationTest` 47/47, `ReviewedProposalGroupLockingTest` 7/7,
`CanonicalConceptApplyServiceTest` 21/21, `CandidateConceptApprovalServiceTest` 27/27,
`TaxonomyReviewedProposalResourceTest` 11/11, `TaxonomyReviewedProposalConfirmationUiTest` 9/9,
`TaxonomyCandidateConceptLinkResourceTest` 4/4— y **eso es la evidencia central** de que extraer la
cadena de validación no cambió el comportamiento de `apply()`.

Se declara en vez de dejarlo implícito: la corrida **base** previa al refactor **no** sirve como
verificación de regresión (arrancó antes de terminar la extracción y PHPUnit autocarga una clase una
sola vez, así que corrió contra el servicio viejo); se conserva como base y todo se reejecutó contra el
código final.

**El único fallo local** es el gap preexistente de `ext-intl` en la regresión de nested-signals de
TASK-0002, verde en staging. **Verificado, no asumido:** restaurando ese archivo a su versión de HEAD
`510400a` falla idéntico, con la misma excepción.

**Staging:** HEAD de runtime `ab1eedd` (run «Deploy a Contabo» `success` para ese sha exacto); `/` 200,
`/admin/login` 200, y las tres pantallas de taxonomía 302 → login 200. **Sin 500/503.** Sin
migraciones. **Invariantes reverificados de solo lectura tres veces** (antes, tras los tests y tras el
despliegue), idénticos: **10 / 2 / 142 / 81 / 9749 / 12 revisadas / 0 aplicadas**, los 10 candidatos
`pending`, las 2 relaciones `candidate`, cero residuo de tests. Regresión de 32 consultas de búsqueda
**heredada sin cambios**.

## PARTE 5 — Riesgo de edición de relaciones aprobadas: NO bloquea

**Veredicto: no puede afectar a las 12 propuestas ni a la ejecución de TASK-0007.** El riesgo es real y
está confirmado en código (el guard solo revalida cuando `isDirty('status')` y el nuevo status es
`approved`, así que editar extremos/tipo de una fila **ya aprobada** no revalida). Pero: (1) hoy no hay
nada en estado editable — #61/#62 son `candidate` **y** tienen propuestas vivas, así que `canEdit()` es
falso, y hay **cero** relaciones aprobadas; (2) las dos propuestas de relación son **REJECT**, y ese
camino retorna **antes** de llamar a la validación de relaciones; (3) las 10 propuestas de candidato no
leen relaciones; y (4) aun hipotéticamente, `taxonomy_concept_relations` está **dentro** de
`dryRunInputFingerprint()`, así que cualquier edición por el CRUD cambia el fingerprint y `apply()`
aborta en vez de publicar contra un grafo no revalidado.

**Nota de preparación hacia adelante** (no bloquea): aplicar #631/#632 deja #61/#62 en `rejected` **sin
propuesta pendiente**, y entonces `canEdit()` pasa a ser **verdadero**. Y como la validación de
relaciones **no filtra por status** (una fila `rejected` sigue contando como duplicado exacto), editar
esas dos filas después de TASK-0007 podría cambiar el resultado de una propuesta **futura** sin
revalidación. Queda como gate separado de endurecimiento administrativo.

---

## Cierre de la fase de revisión humana C2 (TASK-0006 / 0006B / 0006C) — histórico

TASK-0006, TASK-0006B y TASK-0006C quedaron **CLOSED / PASS** por el audit final
(Issue #2 comentario
[`5947549221`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5947549221)).

El audit final verificó, en modo solo lectura y tras las confirmaciones del dueño por Filament:

| Ítem | Estado al cierre |
|---|---|
| #492–#495 | **confirmadas válidamente por humano** vía HTTP/Filament; siguen `PENDING_APPLY` |
| #629–#632 | **confirmadas válidamente** y sin cambios |
| Filas fuente candidatas | 10 |
| Relaciones candidatas | 2 |
| `taxonomy_term_concepts` | **142** |
| `taxonomy_canonical_concepts` | **81** |
| TERM→CPV | **9749** |
| Propuestas revisadas | **12** |
| Aplicadas | **0** |
| Mutación de taxonomía publicada | **ninguna, en ninguna ronda** |

**Lo que cerró el ciclo.** El dueño confirmó #492–#495 personalmente por la UI autenticada el
2026-10-02 (07:36:55 – 07:37:52), con `confirmed_by_id=3` y **canal `http`**. Verificado también en
esta ronda, de solo lectura: de las 12 propuestas, **8 están confirmadas por humano y las 8 llevan
canal `http`; ninguna lleva `console`**, y **cero** quedan esperando confirmación. La restricción a
canal HTTP se sostiene sobre el dataset real completo, no solo en los tests.

**La reparación de TASK-0006C funcionó exactamente como se diseñó, y se puede comprobar en la propia
fila:** cada una de las cuatro conserva **a la vez** el rastro de la anulación
(`invalidated_confirmation_snapshot`, con la confirmación original y su `channel='console'` — la
evidencia misma de por qué esa procedencia no era válida) **y** la confirmación nueva y legítima con
`channel='http'`. La historia no se sobrescribió: la asimetría aguantó, porque la corrección solo
podía **anular** y nunca **reasignar**, y la confirmación de reemplazo tuvo que venir de la UI
autenticada. La bitácora sola reconstruye la secuencia completa por propuesta: filas #1380–#1383
(confirmaciones originales inválidas), #1853–#1856 (`confirmation_invalidated_at`, la corrección) y
#1857–#1860 (confirmaciones válidas del usuario #3).

**Confirmar no es ejecutar:** las 12 siguen `PENDING_APPLY`. Las decisiones están en cola, no
aplicadas.

## Seguimiento CERRADO en TASK-0006D — UX de la tabla de candidatos

> **Estado: CORREGIDO** en la PARTE 2 de TASK-0006D (ver «Tarea activa» arriba y la §8 del audit de
> TASK-0006D). Lo que sigue es el reporte original tal como se registró.

Issue #2 comentario
[`5947407519`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5947407519)
reportó un defecto real **en código propio**, detectado mientras el dueño hacía la confirmación
humana legítima: la tabla de candidatos **sigue ofreciendo** `Revisar (congelar decisión C2)` en las
filas 266–269 **aunque cada una ya tiene una propuesta `PENDING_APPLY`** (#492–#495) y la propia tabla
las etiqueta `CONGELADA_PENDIENTE`. Al entrar por ahí con `exploration` y llenar los campos bilingües
salió «Identidad bilingüe inválida…», que **no** es evidencia de que #492 esté mal formada: es un
camino de revisión alternativo y engañoso.

**Causa raíz:** la visibilidad de `freezeReview` solo chequea que el candidato esté `pending` y que el
usuario tenga permiso de `update`; **no** se oculta cuando ya existe una propuesta `PENDING_APPLY`
viva. El candidato sigue `pending` **por diseño** (`freeze()` nunca muta la fila fuente), así que el
chequeo confundió «sigue pending» con «sigue sin revisar». La corrección tiene que mirar la existencia
de una propuesta viva, no el status del candidato.

**Registrado para la próxima ronda de código** (no implementado acá, porque esta ronda se limitó a
documentar el cierre sin cambios de runtime): ocultar o deshabilitar `freezeReview` cuando ya hay una
propuesta `PENDING_APPLY`; preferentemente reemplazarlo por un enlace directo a esa propuesta;
conservar la etiqueta `CONGELADA_PENDIENTE`; y agregar un test de regresión que pruebe que un
candidato con propuesta pendiente activa **no puede** entrar a un segundo flujo de freeze desde la UI.

El audit final confirma que este endurecimiento **no invalida** la reparación de TASK-0006C ni la
compuerta de confirmación humana.

---

## TASK-0006B / TASK-0006C (histórico del cierre)

**TASK-0006B** — Confirmación humana C2 + `CREATE_NEW` bilingüe + convergencia gobernada
(Issue #2 comentario `5936206843`). Abierta desde HEAD `42d3c6b`. **CLOSED / PASS.**

**TASK-0006C** — Reparación de procedencia de confirmación de #492–#495
(diseño `PASS FOR IMPLEMENTATION` en `5939882569`, autorización explícita del dueño en
`5939903005`). **CLOSED / PASS.** Detalle en la **§11 del audit**.

| Lo ejecutado en TASK-0006C | Estado |
|---|---|
| Migración aditiva + excepción estrecha del trigger (solo ANULACIÓN) | hecho, cero filas tocadas por la migración |
| `confirm()` restringido a canal HTTP/UI | hecho — consola devuelve `CHANNEL_NOT_HUMAN` |
| Anulación de la procedencia inválida de **#492–#495** | hecho — volvieron a `UNCONFIRMED` y el dueño las confirmó después por la UI |
| Decisiones, payloads y fingerprints | **intactos** en las 4 |
| #629–#632 | **no tocadas** (punto 10) |
| Confirmación por el agente | **ninguna** (punto 9) |
| APPLY / publicación / merge a `main` | **nada** |

### Ronda 2 (previa, dentro de la misma tarea)

Implementó y probó el **endurecimiento de concurrencia** del APPLY agrupado y **diseñó** la corrección
de procedencia. Detalle completo en
[`audit/phase6_task0006b_human_confirmation_2026-10-01.md`](../../audit/phase6_task0006b_human_confirmation_2026-10-01.md)
(§10 para esta ronda); texto verbatim en
[`tasks/0006b-human-confirmation.md`](tasks/0006b-human-confirmation.md); diseño correctivo en
[`designs/0006c-confirmation-provenance-correction.md`](designs/0006c-confirmation-provenance-correction.md).

### Bloqueo aceptado: la confirmación de #492–#495 no es procedencia humana válida

`confirm()` exige `Auth::id() === $confirmer->id` precisamente para que nadie pueda «confirmar en
nombre de» otra cuenta. En la ronda 1 **el agente ejecutó las confirmaciones desde consola**,
autenticando la cuenta #3 con `Auth::login()` y satisfaciendo así ese chequeo. El razonamiento con el
que se ejecutó —que la `confirmation_reference` al comentario del dueño bastaba— **era incorrecto**:
una referencia de gobernanza prueba **qué** decidió el dueño, no que el usuario #3 **ejecutó
personalmente** la confirmación. Eso derrota el invariante anti-suplantación que el mecanismo existe
para sostener.

| | Estado |
|---|---|
| **Contenido** de las decisiones 266–269 | **válido** (autorizado en `5936206843`) |
| **Mecanismo/código** de confirmación | **aceptado** por el re-audit |
| **Procedencia almacenada** en #492–#495 | **INVÁLIDA / `CORRECTION_REQUIRED`** |

**Qué hizo esta ronda, y qué no:** la metadata de confirmación de #492–#495 **no se tocó** (el trigger
la hace inmutable y la reparación exige una autorización humana nueva); **no** se confirmó nada por
consola; **no** se confirmaron #629/#630 ni #631/#632. Solo cambiaron código, tests y documentación.

### Endurecimiento de concurrencia (implementado y probado)

`apply()` bloqueaba primero la fila de entrada y después todas las del grupo, así que dos `apply()`
concurrentes entrando por hermanos distintos podían tomar locks opuestos y quedar en **deadlock** de
PostgreSQL. Postgres lo detecta y revierte una —nunca se publicaba de más—, pero «una peticion muere
con deadlock» es más débil que el contrato pedido.

Corregido: el **primer** lock de la transacción es ahora un **advisory lock de transacción** con clave
derivada del `proposal_group_id`, **idéntica para todos los hermanos**, tomada **antes de cualquier
lock de fila**. La espera circular desaparece por construcción; el segundo hermano espera, ve el grupo
aplicado y devuelve `ALREADY_APPLIED`. Las propuestas **sin** grupo no toman ningún advisory lock.

**Tests: 7/7** (`ReviewedProposalGroupLockingTest`, 26 assertions), **sin ningún APPLY real**: clave
idéntica entre hermanos, estable y distinta entre grupos; **exclusión mutua real medida con dos
conexiones** a Postgres (con control negativo); que `apply()` **efectivamente** toma el lock,
consultado en `pg_locks`; que el camino sin grupo no cambió; y que el par sigue convergiendo en **un
solo** concepto. Se declara el límite: no se simula una carrera con dos procesos PHP (exigiría
commitear fixtures reales, no autorizado); la ausencia de deadlock se demuestra por construcción más
la exclusión mutua medida.

### Diseño correctivo (solo diseño, pendiente de autorización)

Asimetría deliberada: **anular** una confirmación (los cuatro campos a NULL) será posible **solo** bajo
una autorización de corrección declarada y con rastro obligatorio; **reasignar** una confirmación
seguirá **prohibido por el trigger, sin excepción**. Así el único desenlace de una corrección es
«vuelve a estar sin confirmar», y la única forma de volver a confirmar es la acción autenticada de
Filament: **ninguna ruta permite inventar un confirmador**. La confirmación mala no se borra, se mueve
a un rastro de anulación con snapshot + fila de auditoría propia. El diseño incluye además la
recomendación de que `confirm()` **rechace** todo canal que no sea `http`, cerrando el camino que
produjo la atribución inválida.

| Decisión del dueño | Representación |
|---|---|
| 266–269 → **CONFIRMAR** `CONTEXT_REQUIRED` | #492–#495 **`HUMAN_CONFIRMED`** por el revisor #3, decisiones y fingerprints **intactos** |
| 270/271 → un concepto, ES `refinería` / EN `refinery` (opción A) | grupo `043fce22…`: propuestas **#629** (cand. 270) + **#630** (cand. 271), una sola identidad bilingüe |
| Relaciones #61 y #62 → **REJECT** | propuestas **#631** y **#632**, `PENDING_APPLY`, relaciones intactas en `candidate` |

**Lo que se construyó (A–D):**

- **A — confirmación humana exigible.** Migración **estrictamente aditiva** (columnas nulables + un
  booleano `DEFAULT FALSE` + CHECK + 2 índices + trigger; cero `DROP`, cero `ALTER TYPE`). Nueva
  operación `confirm()` que escribe **solo** las columnas de confirmación: `reviewer_id`,
  `reviewed_at`, la decisión, el snapshot de fuente y los **dos** fingerprints quedan intactos — el
  `payload_fingerprint` se computa sobre una lista fija de 9 campos de decisión que no incluye ninguna
  columna nueva, así que confirmar **no puede** invalidar la tamper-detection (verificado en vivo: los
  8 fingerprints reales idénticos). Idempotente, concurrency-safe, solo el usuario **autenticado** y
  con el permiso `update` del tipo de origen, sin poder confirmar «en nombre de» otra cuenta, con el
  canal **auto-capturado** (no falseable) y un evento de auditoría propio (`field=confirmed_at`) que
  separa PREPARED/FROZEN → HUMAN_CONFIRMED → APPLIED. Un trigger de base de datos impide apagar el
  marcador o sobrescribir una confirmación.
- **Regla de compatibilidad (exacta):** `apply()` consulta **solo** `requires_human_confirmation`,
  creado con `DEFAULT FALSE`, así que **toda** propuesta congelada antes de TASK-0006B sigue siendo
  aplicable igual que antes — su procedencia de revisión original ya satisface el requisito de
  revisión humana (#420/#421/#422/#491 sin tocar). Que `ReviewedProposalServiceTest` pase **41/41 sin
  editar una línea** es la prueba. La compuerta **rechaza** en vez de abortar: abortar es terminal y
  re-congelar está bloqueado por el índice único parcial, así que un `apply()` prematuro dejaría la
  decisión humana irrecuperable.
- **Backfill de #492–#495** determinístico y auto-validante (ids + marcador de atribución presente en
  `context_reason` + `PENDING_APPLY`), auditable (4 filas de bitácora) y limitado a identificar el
  requisito: **nunca** tocó una decisión.
- **B — UX de confirmación** en Filament: muestra la decisión original, su payload y la procedencia
  **real** (dice que la preparó un agente, sin presentar al titular de la cuenta como autor), exige
  referencia de gobernanza + casilla deliberada, muestra confirmador y fecha, y **sigue sin ningún
  botón de APPLY/Publicar**.
- **C — `CREATE_NEW` bilingüe:** `canonical_name_es` **y** `canonical_name_en` explícitas, sin
  traducción ni fallback (una identidad parcial se **rechaza**), sugerencia del Builder solo como
  evidencia, fingerprint cubriendo **las dos** nombres, y compatibilidad hacia atrás **probada** para
  los payloads monolingües históricos.
- **D — convergencia:** el grupo es **una fila por candidato** unidas por `proposal_group_id`, no una
  fila con una lista. Razón dura: con una sola fila el segundo candidato quedaría **fuera** del índice
  único parcial y nada impediría congelarle otra propuesta en paralelo — la carrera de concepto
  duplicado. `apply()` bloquea el grupo entero, crea **UN** concepto y adjunta los dos términos en una
  transacción, reutilizando el concepto si un hermano ya se aplicó; el drift en **cualquiera** de los
  dos orígenes aborta todo. **No** es un rediseño N:M: la cardinalidad TÉRMINO→CONCEPTO y la semántica
  de búsqueda no cambian.
- Las capacidades son **alcanzables desde la UI real** (lección del re-audit `5930560603`): selector
  monolingüe/bilingüe, los dos campos de nombre, y un multi-select de convergencia que lista los demás
  candidatos `pending` con su idioma y marca con `↔` los de igual `canonical_term` como **sugerencia,
  no filtro**.

**Decisión de diseño declarada:** las propuestas nuevas **#629–#632** quedaron
`requires_human_confirmation = true` (preparadas por el agente). El **contenido** lo fijó el dueño en
el comentario, pero la **ejecución** del freeze la hizo el agente, y marcarlas solo **agrega** una
compuerta: no publica nada, no puede perder datos, y TASK-0007 no está abierta. Si el orquestador
prefiere tratarlas como decididas directamente por el humano, la resolución es **aditiva**: un clic en
«Confirmar decisión preparada». **No hace falta borrar ni re-congelar nada** — justo el hueco que esta
tarea cerró.

**Tests:** 34/34 nuevos de servicio (167 assertions) + 7/7 nuevos de UI, y las regresiones protegidas
**sin editar**: `ReviewedProposalServiceTest` 41/41, `TaxonomyReviewedProposalResourceTest` 11/11,
`TaxonomyConceptRelationValidationTest` 11/11, `TaxonomyConceptExplorerTest` 23/23,
`TaxonomyConceptRelationReviewTest` 14/14, `TaxonomyCandidateConceptLinkResourceTest` 4/4,
`TaxonomyCandidateConceptLinkReviewTest` 13/14. **El único fallo** es el gap local preexistente de
`ext-intl` en la regresión de nested-signals de TASK-0002, verde en staging y ajeno a este diff.

**Staging:** HEAD desplegado `1617e72` (run «Deploy a Contabo» `success` para ese sha exacto); smoke
`/` 200, `/admin/login` 200, y las tres pantallas de taxonomía 302 → login 200. **Sin 500/503.** Esta
sesión **no** tiene clave SSH al host, así que el HEAD se verificó por el run del workflow y no por
inspección directa; el riesgo de 500 en la pantalla autenticada queda cubierto por los tests que
abren la página de detalle con `assertOk()` (11/11 **después** del cambio de infolist).

**Estado protegido:** candidatos 10, relaciones 2, `term_concepts` **142**, `canonical_concepts`
**81**, TERM→CPV **9749**, propuestas revisadas **8 → 12**, **aplicadas 0**. Cero residuo de tests.

**Sigue NO autorizado:** APPLY/publicación (TASK-0007 sin abrir), producción, merge a `main`, y
cualquier borrado/re-freeze de #492–#495.

## TASK-0006 (ronda previa, histórico)

**TASK-0006** — Revisión humana de la cola real (Issue #2 comentarios `5933152293` → re-audit
`5934324928`). HEAD revisado `bd70ac7`.

**Estado: `CORRECTIONS_REQUIRED` / COMPUERTA DE GOBERNANZA HUMANA — ESPERANDO DECISIONES HUMANAS.**
Cero APPLY, cero publicación, cero cambios de código, y **cero escrituras reales en esta ronda de
corrección** (el comentario lo prohíbe expresamente). Detalle completo —tres bloqueos y el hueco de
workflow reportado— en la **sección 10** de
[`audit/phase6_task0006_queue_review_2026-10-01.md`](../../audit/phase6_task0006_queue_review_2026-10-01.md);
texto verbatim en [`tasks/0006-queue-human-review.md`](tasks/0006-queue-human-review.md).

| Ítem | Estado gobernado actual |
|---|---|
| 266 `exploration` | propuesta #492 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 267 `upstream` | propuesta #493 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 268 `midstream` | propuesta #494 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 269 `downstream` | propuesta #495 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 270 `refinery` + 271 `refinería` | **SIN DECIDIR** — requiere decisión humana de diseño (bloqueo 2) |
| Relaciones 61 y 62 | **SIN DECIDIR** — requiere política humana de curación (bloqueo 3) |
| #420 / #421 / #422 / #491 | revisadas por humano — **protegidas** |

**BLOQUEO 1 — las decisiones 266–269 no son decisiones humanas.** El orquestador no las acepta como
tales: `reviewer_id=3` / `actor_type=user` dice estructuralmente que el usuario #3 revisó, mientras la
nota durable en `context_reason` dice que la preparó el agente, y **una auditoría de gobernanza no
puede apoyarse en una atribución contradictoria**. El análisis de los cuatro términos se conserva
**como recomendación/evidencia**, aceptado explícitamente en ese carácter. Las 4 propuestas se
preservan intactas y marcadas; el revisor humano debe inspeccionar y confirmar, rechazar o revisar
cada una antes de cerrar TASK-0006.

**Hueco de workflow REPORTADO (condicional del bloqueo 1), sin bypass.** Auditado leyendo esquema y
código: **hoy C2 no puede registrar la confirmación humana de una propuesta ya congelada sin borrado o
re-freeze.** No hay columna de segundo actor (`confirmed_by`/`confirmed_at`); los estados son solo
`PENDING_APPLY`/`APPLIED`/`ABORTED`; `ReviewedProposalService` solo expone `freeze()` y `apply()` y
`abort()` es privado y solo alcanzable desde la ruta de APPLY; y el índice único parcial
`one_pending_per_candidate` **impide por base de datos** un segundo `PENDING_APPLY` para el mismo
candidato. Editar `decision_payload` a mano rompería `payload_fingerprint`. Se reportaron cuatro
opciones (extensión aditiva con `confirm()` —la única no destructiva y exigible—; limpieza autorizada +
re-freeze; entrada en `taxonomy_audit_log` sin compuerta; solo documental) y **ninguna se implementó ni
se eligió**: ninguna está autorizada y es decisión de diseño. Ver sección 10.3 del audit.

**BLOQUEO 2 — 270/271.** El orquestador confirma el análisis y prohíbe crear conceptos duplicados y
usar un APPLY como atajo de secuenciación. Decisión humana requerida: (A) extender `CREATE_NEW` para
congelar nombres canónicos ES + EN explícitos con flujo de identidad bilingüe revisado, o (B) definir
otra secuencia gobernada para crear el concepto y luego mapear el alias de traducción.

**BLOQUEO 3 — relaciones 61/62.** Dejarlas sin decidir fue correcto. Requieren decisión humana
explícita o una regla de curación declarada; hasta entonces siguen `candidate` y sin congelar.

**Estado protegido (sin cambios en esta ronda):** candidatos 10, relaciones 2, `term_concepts` 142,
`canonical_concepts` 81, `TERM→CPV` 9749, propuestas revisadas **8**, **aplicadas 0**. No se tocaron
#492–#495, 270, 271 ni las relaciones 61/62.

**Sigue NO autorizado:** APPLY/publicación (TASK-0007 sin abrir), producción, merge a `main`, y
cualquier limpieza/borrado/re-freeze de #492–#495 sin autorización separada y explícita.

## TASK-0006A (cerrada, histórico)

**Explorador de conceptos para revisión humana + diagnóstico de mapeo**
(Issue #2 comentario `5929287629`). Abierta desde HEAD `a4d8b2f`.

**Estado: CLOSED / APPROVED** (comentario `5933152293`, HEAD revisado `d532a0b`). Ambas correcciones
del re-audit `5930560603` quedaron `PASS`.

**Contexto:** TASK-0006 (revisión humana de la cola real) está EN CURSO y quedó **pausada** para los
candidatos sin resolver, porque el revisor encontró una limitación real de UX/diagnóstico revisando
el término `pipeline`: el selector de MAP_TO_EXISTING solo ofrecía los duplicados sugeridos por el
Builder y truncaba la búsqueda a 20 sin avisar, lo que podía crear falsa confianza de que las pocas
opciones visibles eran las únicas válidas.

**Estado:** READY_FOR_REVIEW (ronda 2). El re-audit del comentario `5930560603` bloqueó por una razón
correcta: el servicio informaba `total`/`truncated` pero **los controles reales de Filament llamaban
solo a `searchOptions()`**, que los descartaba — el revisor veía como máximo N opciones sin señal de
que hubiera más y sin forma de alcanzarlas, y lo mismo con las categorías CPV omitidas. Corregido:

- **Explorador paginado real:** campo de búsqueda + acciones Página anterior/siguiente + línea de
  estado **renderizada** ("Mostrando X-Y de N coincidencias, página P de T"). Se quitó
  `->searchable()` del `Select` para no dejar un camino paralelo con tope silencioso; el `Select`
  lista la página actual, así que toda coincidencia es alcanzable. El tamaño de página es 25 y
  estable: **no** se subió al tamaño del catálogo, para que siga siendo correcto cuando crezca.
- **Categorías CPV inspeccionables:** filtro por código/nombre + paginación propia. Se informan a la
  vez el total alcanzable real y el que coincide con el filtro, así que filtrar no puede hacer
  parecer que hay menos.
- **4 tests de nivel UI** sobre el formulario real (`mountTableAction`/`setTableActionData`/
  `callMountedTableAction`) que prueban lo que faltaba: que el total y la página se **renderizan**,
  que un concepto solo presente en la página 2 se elige y se congela, y que una categoría fuera del
  preview inicial se inspecciona paginando o filtrando. Suite: **23/23**.

Semántica sin cambios: MAP_TO_EXISTING sigue siendo un concepto, CONTEXT_REQUIRED sigue en cero
mapeos, sin multi-select TÉRMINO→CPV, sin cambios de búsqueda/ranking/cardinalidad, sin APPLY.

Detalle completo en
[`audit/phase6_task0006a_concept_explorer_2026-10-01.md`](../../audit/phase6_task0006a_concept_explorer_2026-10-01.md);
texto verbatim en [`tasks/0006a-concept-explorer.md`](tasks/0006a-concept-explorer.md).

Lo entregado:

- **A — descubrimiento:** nuevo `ConceptExplorerService` (solo lectura). La búsqueda recorre los
  **79 conceptos activos** completos por nombre ES, nombre EN, término miembro y alias; filtra
  `status=active` (corrige un defecto real: antes se ofrecían conceptos `merged`); el tope pasó de 20
  silencioso a 50 **informando siempre el total real y el overflow**; etiquetas con `#id` + tipo/
  dominio para distinguir homónimos. Las recomendaciones del Builder siguen visibles, etiquetadas
  como evidencia y distintas del catálogo buscable. Sin hardcoding de `pipeline`.
- **B — diagnóstico:** panel de solo lectura con identidad del concepto, términos con identidad
  aprobada, alias, categorías CPV alcanzables (código + Grupo/Familia/Categoría + breadcrumb),
  impacto predicho de empresas, procedencia y advertencias de huecos de datos. Todo de datos ya
  gobernados; nada inventado; nada truncado en silencio.
- **C — polisemia:** guía explícita MAP_TO_EXISTING vs CONTEXT_REQUIRED, más un selector de
  inspección solo para CONTEXT_REQUIRED que es **pura evidencia** y nunca entra al payload congelado.
  Sin ninguna regla automática: la decisión sigue siendo humana.
- **D — cardinalidad:** auditada ANTES de implementar. El esquema admite N conceptos por término,
  pero el pivote no tiene dónde representar contexto y el índice de búsqueda expande por hermanos de
  concepto sin compuerta de contexto, así que mapear a N conceptos haría un fan-out incondicional de
  categorías CPV. **La arquitectura NO lo soporta de forma segura** → la UI queda de un solo
  concepto y el mapeo contextual multi-concepto se registra como tarea futura con compuerta nueva.
- **Sin migraciones ni cambios de esquema.** Cero cambios en semántica de búsqueda o taxonomía
  publicada.

**Lo que esta tarea NO hizo, por instrucción explícita:** no modificó las 3 revisiones ya congeladas,
no procesó los 7 candidatos restantes ni las 2 relaciones, y no ejecutó ningún APPLY.

## Estado de la cola real (estado vivo)

Las 10 filas fuente originales siguen existiendo (`263`–`272`), igual que las 2 relaciones candidatas
(`61`, `62`). Tras TASK-0006B hay **12 propuestas congeladas**, ninguna aplicada, y **la cola real ya
no tiene ítems sin decidir**:

La columna «Preflight» es el resultado real del **preflight final de solo lectura** del 2026-10-05
(comentario `5993828105`). Tras las tres re-revisiones del dueño hay **15 propuestas**:
`PENDING_APPLY` **12** + `SUPERSEDED` **3**.

| Propuesta | Origen | Decisión | Estado | Confirmación humana | `applied_at` | Preflight (tras TASK-0006E) |
|---|---|---|---|---|---|---|
| #420 | cand. 263 (`petroleum`) | `CONTEXT_REQUIRED` | **`SUPERSEDED`** | no requiere (revisión humana original) | NULL | **`ALREADY_SUPERSEDED`** |
| #421 | cand. 264 (`crude oil`) | `CONTEXT_REQUIRED` | **`SUPERSEDED`** | no requiere | NULL | **`ALREADY_SUPERSEDED`** |
| #422 | cand. 265 (`oil and gas`) | `CONTEXT_REQUIRED` | **`SUPERSEDED`** | no requiere | NULL | **`ALREADY_SUPERSEDED`** |
| #491 | cand. 272 (`pipeline`) | `MAP_TO_EXISTING` → #2890 `oleoducto / oil pipeline` | `PENDING_APPLY` | no requiere | NULL | `READY_TO_APPLY` |
| #492 | cand. 266 (`exploration`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) tras la anulación de TASK-0006C | NULL | `READY_TO_APPLY` |
| #493 | cand. 267 (`upstream`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) tras la anulación | NULL | `READY_TO_APPLY` |
| #494 | cand. 268 (`midstream`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) tras la anulación | NULL | `READY_TO_APPLY` |
| #495 | cand. 269 (`downstream`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) tras la anulación | NULL | `READY_TO_APPLY` |
| #629 | cand. 270 (`refinery`) | `CREATE_NEW` ES `refinería` / EN `refinery` — grupo `043fce22…` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) | NULL | `READY_TO_APPLY` |
| #630 | cand. 271 (`refinería`) | `CREATE_NEW` ES `refinería` / EN `refinery` — grupo `043fce22…` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) | NULL | `READY_TO_APPLY` |
| #631 | relación 61 | `REJECT` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) | NULL | `READY_TO_APPLY` |
| #632 | relación 62 | `REJECT` | `PENDING_APPLY` | **CONFIRMADA** por #3 vía UI (`http`) | NULL | `READY_TO_APPLY` |
| **#1688** | cand. 263 (`petroleum`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | no requiere (**revisión humana nueva**, `human_reviewer` vía `http`) | NULL | `READY_TO_APPLY` |
| **#1689** | cand. 264 (`crude oil`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | no requiere (**revisión humana nueva**) | NULL | `READY_TO_APPLY` |
| **#1690** | cand. 265 (`oil and gas`) | `CONTEXT_REQUIRED` | `PENDING_APPLY` | no requiere (**revisión humana nueva**) | NULL | `READY_TO_APPLY` |

> Corrección de una fila desactualizada (ronda de TASK-0006D): hasta entonces esta tabla seguía
> describiendo #492–#495 como «anulada → pendiente de confirmación humana», que era el estado **durante**
> TASK-0006C. El dueño las confirmó por la UI el 2026-10-02 (07:36:55–07:37:52) y el audit de cierre
> `5947549221` ya lo registraba; la tabla simplemente no se había actualizado. El veredicto no cambió.

**#420/#421/#422 ya NO están en la cola de aplicación:** quedaron `SUPERSEDED` en TASK-0006E, íntegras y
auditables, y sus candidatos 263/264/265 volvieron a la cola de **revisión** humana — donde el dueño las
re-revisó, produciendo #1688/#1689/#1690. **Las 12 vivas están `READY_TO_APPLY`, ninguna aplicada.**

Los candidatos 263/264/265 tienen ahora **dos** propuestas cada uno: la supersedida (histórica) y la
viva. El índice único parcial lo permite porque sólo cuenta `PENDING_APPLY` — y sigue impidiendo una
tercera: ningún candidato tiene más de un `PENDING_APPLY`.

La cola viva **no tiene nada obsoleto ni bloqueante**, así que ya se cumple la condición para que
TASK-0007 pueda abrirse. Lo único que falta es la **autorización humana de ejecución**, separada por
diseño — no un cambio de código ni otra verificación.

**Las 8 confirmaciones humanas existentes llevan canal `http`; ninguna lleva `console`,** y **cero**
propuestas quedan esperando confirmación, así que la acción «Confirmar decisión preparada» ya no se
muestra en ninguna fila. Las 12 propuestas son descubribles en el resource; sigue sin existir ninguna
acción de APPLY/Publicar.

Las 12 son **registros congelados protegidos**: ningún paso de código, test o despliegue puede
mutarlos, borrarlos ni re-congelarlos sin autorización de limpieza separada y explícita.

**#629 y #630 son UN grupo bilingüe**: `apply()` (en una tarea futura separadamente autorizada) creará
**UN** solo concepto canónico y adjuntará los dos términos en una transacción — nunca un concepto por
candidato.

**La #491 la congeló el humano con la UI mejorada mientras se implementaba esta corrección**, y es la
validación real del objetivo de TASK-0006A: `pipeline` era justamente el término polisémico que
originó la tarea. El revisor creó dos conceptos específicos (`oleoducto / oil pipeline` #2890 y
`gasoducto / gas pipeline` #2891, vía el CRUD administrativo de conceptos, fuera de C2) y mapeó el
término a uno de ellos, en lugar de forzarlo contra una opción inadecuada. Esta sesión no tocó nada
de eso. Detalle en la sección 10 del audit.

**Invariantes del estado vivo:**

| Tabla | Valor | Nota |
|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | sin cambios |
| `taxonomy_concept_relations` | 2 | sin cambios |
| `taxonomy_term_concepts` | 142 | sin cambios — **nada publicado** |
| `taxonomy_canonical_concepts` | **81** | era 79; +2 conceptos creados por el humano, ninguno publicado en `taxonomy_term_concepts` (0 filas cada uno) |
| `taxonomy_term_cpv_relations` | 9749 | sin cambios |
| `taxonomy_reviewed_proposals` | **12** | 4 sin requisito + 4 confirmadas + 4 nuevas pendientes de confirmar |
| Propuestas aplicadas | **0** | ningún APPLY ocurrió nunca |

**Siguen NO autorizados:** APPLY/publicación, producción, merge a `main`. TASK-0007 (APPLY) sigue sin
abrir. Las tres decisiones humanas de gobernanza que bloqueaban TASK-0006 llegaron en el comentario
`5936206843` y quedaron representadas en TASK-0006B (ver arriba).

## TASK-0005 (cerrada, histórico)

**UI de revisión humana C2 en Filament + hardening del trigger de despliegue**
(Issue #2 comentario `5914793857`). Abierta desde HEAD `63cf811`.

**Estado: CLOSED / APPROVED** (comentario `5928773263`, HEAD revisado
`dbd410a3b5603bba8acc48091ebb0601f411f3bd`). Ambas correcciones del re-audit `5917275454` quedaron
`PASS`; evidencia de tests y despliegue aceptada; invariantes y gates heredados confirmados sin
invalidar.

## Riesgo adyacente registrado para una tarea futura (bloqueante antes de producción)

El comentario de cierre confirmó la observación que esta sesión registró por cuenta propia y le puso
un gate explícito: editar endpoints/tipo de una relación ya `approved` puede mutar taxonomía
publicada, porque el guard de `TaxonomyConceptRelation::booted()` solo revalida cuando el status
**pasa a** `approved` (`isDirty('status')`). Es **preexistente** y quedó fuera de las dos correcciones
pedidas, así que no bloqueó el cierre de TASK-0005 — pero el orquestador indicó que **DEBE**
resolverse antes de habilitar edición administrativa general de relaciones publicadas o antes de
cualquier rollout a producción de esta UI de gobernanza de taxonomía. No implementado en esta ronda:
no fue pedido y haría falta una tarea propia.

## Historial del re-audit `5917275454` (ambas correcciones, ahora PASS)

Los dos hallazgos corregidos fueron:

1. **Fuga de autorización entre tipos de origen** en las propuestas revisadas: la policy usaba OR,
   así que quien veía candidatos podía abrir propuestas de relación y viceversa, y el listado no
   estaba filtrado. Ahora `view()` resuelve por `proposal_type` y `getEloquentQuery()` filtra las
   filas por los tipos que el usuario puede ver (lo que también cierra la URL directa, porque
   `ViewRecord` resuelve contra ese mismo query). Super-admin sin bypass, por el modelo de permisos
   normal.
2. **Edit/Delete legacy sobre relaciones en revisión C2**: un revisor podía mutar o borrar una
   relación candidata al margen del ciclo inmutable, destruyendo evidencia en lugar de producir un
   REJECT congelado. Nuevo predicado `isUnderC2Review()` usado por `canEdit()`/`canDelete()` (cierra
   acción **y** ruta `/edit` con 403) más un guard `deleting` a nivel de modelo. El CRUD
   administrativo se conserva acotado a filas cuyo ciclo ya terminó.

**Tests del re-audit: 138 passed, 1 failed** (el fallo es el gap local preexistente de `ext-intl`,
verde en staging). Incluye 4 tests preexistentes de `TaxonomyConceptRelationValidationTest`
actualizados porque codificaban el comportamiento que la corrección 2 pidió cerrar — cada uno
preservando la propiedad que protege, documentado en el archivo. Invariantes 10/2/142/79/9749/0 sin
cambios.

Detalle completo de ambas correcciones en la sección 10 del audit. Estado de la ronda 1
(implementación, hardening del workflow, despliegue y smoke iniciales) en
[`audit/phase5_task0005_c2_review_ui_2026-09-30.md`](../../audit/phase5_task0005_c2_review_ui_2026-09-30.md);
texto verbatim de la tarea en
[`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md).

Resumen de lo entregado:

- **A/B — UI de freeze:** una acción única `freezeReview` por resource (candidatos: `MAP_TO_EXISTING`
  / `CREATE_NEW` / `CONTEXT_REQUIRED` / `REJECT`; relaciones: `PUBLISH_RELATION` / `REJECT`), cableada
  exclusivamente a `ReviewedProposalService::freeze()`. Cada decisión exige input humano explícito;
  `CREATE_NEW` no tiene fallback implícito al nombre sugerido por el Builder (la sugerencia se muestra
  solo como evidencia). Las acciones legacy `approve`/`reject`/`resolveNewConcept` fueron removidas.
- **C — visibilidad:** nuevo `TaxonomyReviewedProposalResource` de solo lectura (List + View, sin
  páginas de create/edit/delete) con origen, decisión, revisor, timestamps, referencia de
  autorización, entorno objetivo, fingerprints y estado de ejecución.
- **D — frontera de autorización:** cero botón/ruta de Apply/Publish alcanzable por un revisor.
  `apply()` sigue siendo un paso separado, solo por servicio/artisan.
- **E — guardas legacy intactas:** ninguna guarda de TASK-0004 fue debilitada;
  `CandidateConceptApprovalService` sigue sin poder publicar rodeando C2.
- **F/H — tests:** 24/25 en la UI nueva (el único fallo es el gap local preexistente de `ext-intl`,
  verde en staging) + 99/99 sin regresiones en los archivos relacionados no editados. Todo con
  fixtures desechables dentro de `DatabaseTransactions`, en entorno local seguro — **no** se reusó el
  procedimiento de contenedor efímero sobre los bind mounts de staging en vivo.
- **G — hardening del deploy:** `paths-ignore` (`docs/**`, `audit/**`, `**.md`) en
  `deploy-contabo.yml`, con la semántica todo-o-nada y los casos representativos documentados en el
  propio workflow. `tests/**` NO se ignora, por decisión deliberada y conservadora.
- **Invariantes:** 10/2/142/79/9749/0 verificados antes de empezar, después de los tests y después del
  despliegue. **Los 10 candidatos y 2 relaciones reales quedaron intactos** — ningún
  freeze/apply/reject/context-resolve corrió contra ellos.

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondiciones verificadas: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`); TASK-0004 / Phase C2
sigue `CLOSED/APPROVED` con despliegue de staging `PASS` (comentarios `5914592664` / `5914676402`).

## TASK-0004 (cerrada, histórico)

**Phase C2: Reviewed Immutable Payload Application** (Issue #2 comentario `5886148283`).
**Implementación C2: CLOSED/APPROVED. Despliegue a staging + validación: PASS** (comentario
`5914592664`, confirmando la ronda 6; HEAD `63cf811` aceptado como checkpoint documental en
`5914676402`).

Comentarios del hilo completo (todos con texto verbatim en
[`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md)):
`5890113782`, `5890195271`, `5892711739`, `5909267134`, `5913324183`, `5913574545`, `5914592664`.

## Ronda 7 (comentario `5914592664`) — confirmación, sin cambios de código

El comentario confirma `PASS` para el despliegue/validación de staging de la ronda 6 (ningún cambio
adicional de código ni de evidencia requerido) y dos instrucciones para más adelante, ninguna abierta
todavía:

1. **Hardening operacional del procedimiento de test-en-staging** (no reabre TASK-0004): antes de
   volver a correr la suite completa contra el host compartido de staging, hace falta un camino de
   ejecución de tests que no pueda escribir en los bind mounts de la app en vivo (`bootstrap/cache`,
   `storage`, etc.), forzar `APP_ENV=testing` a nivel de proceso/contenedor, no correr scripts de
   `package:discover` contra el filesystem del contenedor que sirve tráfico, y agregar guardas de
   smoke-test antes/después + limpieza automática de contenedores efímeros. **No implementado en esta
   ronda** - es trabajo operacional para cuando se vuelva a necesitar correr tests en staging, no algo
   pedido para ejecutar ahora.
2. **Próxima fase funcional (wiring de la UI de revisión humana C2 en Filament):** el propio
   comentario dice explícitamente que solo se abre "awaiting/opened only by explicit orchestrator
   instruction" - **todavía no está abierta**. El usuario, en su mensaje de esta ronda, instruyó
   exactamente lo mismo: detenerse y esperar esa apertura formal, sin actuar sobre los 10
   candidatos/2 relaciones reales.

**Ninguna acción de código, despliegue, ni de servidor se tomó en esta ronda** - es puramente de
reconocimiento/registro del comentario y actualización de este documento.

## Resumen de la ronda 6 (comentario `5913574545`) — despliegue a staging + validación

Detalle completo (checkpoint pre-despliegue, despliegue, validación 1-9, incidente y corrección,
análisis de los 3 fallos): `audit/phase5_staging_deployment_2026-09-30.md`.

- **Despliegue:** ya había ocurrido automáticamente vía el workflow existente de GitHub Actions
  (dispara en cada push a `feature/upgrade-filament-v3`) - verificado que el run para HEAD `4f1b02e`
  completó con éxito antes de iniciar cualquier validación.
- **Checkpoint pre-despliegue:** todos los invariantes de DB coincidieron exactamente (10/2/142/79/
  9749/0), sin migraciones pendientes, target de DB confirmado como la misma instancia compartida de
  Supabase, `ext-intl` confirmado cargado en el servidor.
- **Incidente real (causado y corregido en esa ronda):** un contenedor efímero de pruebas (necesario
  para tener PHPUnit disponible, ausente en la imagen `--no-dev` de staging) sobrescribió, vía un
  bind mount compartido (`bootstrap/cache/`), la caché de auto-discovery de paquetes del contenedor
  REAL que sirve tráfico - staging quedó caído (HTTP 500) unos minutos. Diagnosticado y corregido de
  inmediato (regenerar la caché desde el propio `vendor/` del contenedor real) - el usuario fue
  informado de forma transparente e inmediata y autorizó explícitamente la corrección antes de que se
  ejecutara. Staging restaurado y verificado en HTTP 200. Ningún código, dato, ni fila real fue
  tocado por el incidente ni su corrección. El comentario `5914592664` confirmó que esto NO invalida
  la implementación C2, pero exige el hardening operacional listado arriba antes de reutilizar el
  procedimiento.
- **Suite completa de taxonomía en el servidor real (`ext-intl`, HEAD `4f1b02e`): 188/191 PASS (605
  assertions), 275.45s.** El test bloqueado en TODAS las rondas anteriores por el gap de `ext-intl`
  ahora pasa limpio. `ReviewedProposalServiceTest` (evidencia C2 directa): **41/41 PASS**, sin
  ninguna excepción.
- **Los 3 fallos restantes** tienen causa raíz precisa e identificada (precedencia de `APP_ENV` entre
  la variable de entorno real del contenedor y el `<env>` no forzado de `phpunit.xml`) - ninguno es
  una regresión de C2/taxonomía, los 3 están en archivos de TASK-0003 no tocados por ninguna ronda de
  TASK-0004. El comentario `5914592664` confirmó explícitamente esta lectura.
- **Invariantes de DB después de toda la suite: sin cambios** (10/2/142/79/9749/0) - los 10
  candidatos/2 relaciones reales permanecieron intactos durante todo el despliegue y validación.
- **Búsqueda:** re-verificado sobre el código REALMENTE DESPLEGADO que `BuildEmpresaSearchDocuments`
  no lee ninguna tabla de candidatos/propuestas. Worker verificado accesible (health check público,
  sin tokens). No se re-corrió la regresión de 32 queries (heredada, no invalidada).

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | CORRECTIONS_REQUIRED (narrow, ronda 3) | mismo archivo, sección "Re-audit" |
| TASK-0004 (ronda 3, correcciones A/B/C) | CORRECTIONS_REQUIRED (final semantic defects, ronda 4) | mismo archivo, sección "Re-audit — comentario `5892711739`" |
| TASK-0004 (ronda 4, defectos 1/2) | IMPLEMENTATION PASS / ENVIRONMENT_GATE_PENDING (ronda 5) | mismo archivo, sección "Re-audit — comentario `5909267134`" |
| TASK-0004 (ronda 5, evidencia de entorno) | DEPLOYMENT AUTHORIZED (ronda 6) | mismo archivo, sección "Re-audit — comentario `5913324183`" |
| TASK-0004 (ronda 6, despliegue a staging + validación) | PASS WITH FOLLOW-UP HARDENING (ronda 7) | mismo archivo, sección "Autorización de despliegue — comentario `5913574545`"; detalle completo en `audit/phase5_staging_deployment_2026-09-30.md` |
| TASK-0004 (ronda 7, confirmación PASS) | STANDING_BY | mismo archivo, sección "Confirmación de despliegue + hardening pendiente — comentario `5914592664`" |
| TASK-0004 (ronda 8, checkpoint documental `63cf811`) | CLOSED | mismo archivo; sin acción de código (el comentario `5914676402` instruyó esperar la apertura formal de TASK-0005) |
| TASK-0005 (ronda 1, implementación + hardening + staging) | CORRECTIONS_REQUIRED (ronda 2) | [`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md); detalle en `audit/phase5_task0005_c2_review_ui_2026-09-30.md` |
| TASK-0005 (ronda 2, correcciones 1 y 2) | **CLOSED / APPROVED** (comentario `5928773263`) | mismo archivo, sección "Re-audit — comentario `5917275454`"; detalle en la sección 10 del audit |
| TASK-0006A (rondas 1–2, explorador de conceptos) | **CLOSED / APPROVED** (comentario `5933152293`) | [`tasks/0006a-concept-explorer.md`](tasks/0006a-concept-explorer.md); detalle en `audit/phase6_task0006a_concept_explorer_2026-10-01.md` |
| TASK-0006 (revisión humana de la cola) | **CLOSED / PASS** (comentario `5947549221`) — su compuerta `5934324928` quedó resuelta por las decisiones humanas de `5936206843` | [`tasks/0006-queue-human-review.md`](tasks/0006-queue-human-review.md); detalle en la sección 10 de `audit/phase6_task0006_queue_review_2026-10-01.md` |
| TASK-0006B (confirmación humana + bilingüe) | **CLOSED / PASS** (comentario `5947549221`); ronda 1 `CORRECTIONS_REQUIRED` por `5938949812`, ronda 2 `CODE PASS` por `5939882569` | [`tasks/0006b-human-confirmation.md`](tasks/0006b-human-confirmation.md); detalle en `audit/phase6_task0006b_human_confirmation_2026-10-01.md` |
| TASK-0006C (reparación de procedencia de confirmación) | **CLOSED / PASS** (comentario `5947549221`); diseño `PASS FOR IMPLEMENTATION` en `5939882569`, autorización del dueño en `5939903005` | mismo archivo de tarea; diseño en [`designs/0006c-confirmation-provenance-correction.md`](designs/0006c-confirmation-provenance-correction.md); detalle en la §11 del audit |
| TASK-0006D (preflight + UX de candidatos + diseño de obsoletas) | **CLOSED / PASS** (comentario `5954835892`); ronda 1 `CORRECTIONS_REQUIRED` por `5952211890`, resuelto en la ronda 2 — resuelve además el seguimiento abierto `5947407519` | [`tasks/0006d-preapply-preflight.md`](tasks/0006d-preapply-preflight.md); diseño en [`designs/0006d-stale-proposal-supersession.md`](designs/0006d-stale-proposal-supersession.md); detalle en `audit/phase6_task0006d_preapply_preflight_2026-10-02.md` |
| TASK-0006E (ciclo `SUPERSEDED` + supersesión autorizada de #420–#422) | **READY_FOR_REVIEW** (comentario `5955148859`, autorización explícita del dueño) | [`tasks/0006e-stale-supersession.md`](tasks/0006e-stale-supersession.md); detalle en `audit/phase6_task0006e_supersession_2026-10-02.md` |

# TASK-0027 — CIRA: módulo web de evaluación institucional para la Cámara (campaña, 8 pruebas guiadas, votos y panel)

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6852945146`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6852945146) (2026-11-13 12:30Z), asignación expresa del propietario |
| ID | TASK-0027: siguiente libre verificado (`tasks/`, `current_task.md`, ramas remotas, `git log --all`, handoff). No reutiliza TASK-0013 ni TASK-0026 |
| Estado | ~~ASSIGNED~~ → ~~IN_PROGRESS~~ (12:40Z) → **BLOCKED_PENDING_DB_GATE** (~13:50Z): candidato aislado `5e6e3fc` probado, sin desplegar → **IN_PROGRESS (despliegue autorizado: `6855056483` + `6855171480`)** (~14:10Z) |
| Prioridad | Aceptación del buscador CIRA por la Cámara. Las pruebas manuales del propietario sobre cuatro empresas son paralelas y no forman parte de esta tarea |
| Autoriza | Implementar en aislamiento el módulo (enlace desde CIRA Test, 8 pruebas guiadas, registro de evaluaciones vinculado a búsquedas y votos, panel interno, informe y pruebas). Desplegar código en staging **solo** dentro del alcance, después del gate de 24 h de TASK-0026 y con preflight PASS |
| Gate especial de BD | Las migraciones nuevas solo se diseñan y prueban en un clon aislado. Ejecutarlas en la BD compartida requiere una autorización expresa aparte. Si el despliegue ejecutaría `migrate --force` con migraciones nuevas, **STOP antes del push** |
| No autoriza | Algoritmo o ranking de CIRA, embeddings, índices de búsqueda, taxonomía o datos de empresas, publicación de candidatos, n8n, producción, `main`, Cloudflare, HSTS, roles o permisos globales, secretos, *force-push*, cambios de otras tareas; ejecutar la batería de las cuatro empresas |
| Convivencia con TASK-0026 | TASK-0026 sigue DEPLOYED_AWAITING_24H (hasta el 2026-11-14 11:43:38Z): no se interrumpe su monitorización ni se mezclan commits, migraciones o evidencia. La promoción de CIRA se planifica después de su gate |

## Resultado (2026-11-13 ~13:50Z): **BLOCKED_PENDING_DB_GATE**: candidato aislado, **sin desplegar**

El candidato está terminado y probado. Para desplegarlo hacen falta dos cosas: que cierre el gate de 24 h de TASK-0026 y una
**autorización expresa aparte del propietario** para ejecutar la migración nueva en la BD compartida (el workflow ejecuta
`php artisan migrate --force`). No se hizo push a `feature/upgrade-filament-v3` ni se desplegó nada.

| SHA | Valor |
|---|---|
| BASE | `6e7f525` (staging vivo, TASK-0026) |
| TESTED = REVIEW | **`5e6e3fc`**, rama `feat/cira-evaluation-module` (*fast-forward* limpio sobre `6e7f525`) |
| Runner | `acc9da6` (`infra/contabo-test-runner`): guion y semilla de la E2E; `APP_URL` y `APP_TRUSTED_HOSTS` del servidor de la E2E (SEC-03) |
| DEPLOY | ninguno |

**Cambios exactos** (36 archivos, +3321, solo del módulo; no toca el buscador, el ranking, embeddings, índices, la taxonomía
ni los datos de empresas):
- **Migración nueva** `2026_11_13_130000_create_cira_evaluation_tables`:
  - tablas `cira_eval_campaigns`, `cira_eval_invitations`, `cira_evaluations` y `cira_eval_test_decisions`;
  - FK, CHECK y unicidad, y el trigger `cira_evaluations_keep_evidence` que vuelve inmutable la instantánea;
  - RLS activado y REVOKE a los roles de la API.
- **Migración de TASK-0013** `2026_11_06_120000_create_cira_search_log_and_feedback_tables`: copia exacta, porque ya está
  registrada en la BD compartida (lote 61). `migrate` la omite.
- **Configuración** `config/cira_evaluation.php`: las 8 pruebas CPV-01..08 y los catálogos.
- **Código:**
  - modelos `App\Models\Cira\*` (los de log y votos, de solo lectura) y el cast `PgBigintArray`;
  - el servicio `CiraEvaluationService`;
  - el trait `AuthorizesCiraEvaluator`;
  - las páginas Filament `evaluacion-cira`, `/evaluar`, `/guia` e `/informe`;
  - los recursos `CiraEvalCampaignResource` (con invitaciones) y `CiraEvaluationResource`;
  - la política `CiraEvaluationPanelPolicy`, con exclusión de Shield.
- **CIRA Test** (`public/cira-test/index.html`): enlace «Evaluar esta búsqueda» por respuesta y enlaces a la guía y a «Mis
  pruebas». Sin cambios en el webhook ni en los votos.
- **Pruebas:** `tests/Feature/Cira/CiraEvaluationModuleTest.php` (23).
- **Guías:** `docs/manual_usuario/guia_evaluacion_cira.md` para la Cámara (35–50 min, permite guardar por partes) y
  `manual_administrador.md` §12.
- **Especificación, decisiones de UX y modelo de datos:** [`audit/TASK0027_CIRA_EVALUATION_MODULE.md`](../../../audit/TASK0027_CIRA_EVALUATION_MODULE.md).

**Pruebas:**

| Prueba | Resultado |
|---|---|
| Módulo + inventario de superficie (`5e6e3fc`) | OK, 24 pruebas y 410 aserciones (`20261113T134219Z-5e6e3fc0288c`) |
| Suite completa (`5e6e3fc`) | **OK, 826 pruebas y 8969 aserciones** (`20261113T134329Z-5e6e3fc0288c`), incluidas SEC-01 y SEC-03 |
| E2E de escritorio y Pixel 5 (`5e6e3fc`) | **40/40** (`20261113T134020Z-5e6e3fc0288c`). Recorrido: CIRA Test → búsqueda → 👍 → evaluar → login → borrador → doble clic → reabrir → enviar → guía con 8 pruebas → 403 del evaluador en el panel → panel (seudónimo, hash verificado, HTML como texto) → informe |
| Ensayo de la migración en el clon de la BD compartida (respaldo de TASK-0026, ICU `en-US`, ACL de Supabase) | Subida ×2 idempotente: 4 tablas, 11 FK, RLS 4/4, 0 privilegios de la API, trigger activo; 88 tablas existentes idénticas; comportamiento correcto con FK reales; bajada → datos y esquema idénticos; nueva subida OK |
| Antes y después | 13 flujos CIRA de n8n con la misma versión (solo `GET`); staging en `6e7f525`, 200 antes y después; BD compartida sin cambios |

**Incidentes de ejecución (sin efecto en staging ni en la BD compartida):**
- La primera ejecución del ensayo en el clon dio «no» en la identidad de datos sin detalle, y no se reprodujo en las
  ejecuciones 2 y 3.
- La diferencia de esquema se debía solo a las líneas `\restrict` de `pg_dump` 17.6; se corrigió el filtro.
- La E2E destapó tres cosas:
  - la ruta sugerida `/cira-test/evaluacion` choca con la carpeta estática, así que no hay rutas públicas nuevas y se enlaza
    directo al panel;
  - el servidor de la E2E del runner necesitaba `APP_URL` y `APP_TRUSTED_HOSTS` por SEC-03;
  - la barra lateral tapaba el teléfono.
  Todo se corrigió antes de `5e6e3fc`.

**Gate de BD pendiente (requiere autorización expresa aparte):**
- **Diff:** solo la migración `2026_11_13_130000`, sola en un lote nuevo. `2026_11_06_120000` ya está en el lote 61.
  - Crea 4 tablas vacías, 1 función y 1 trigger.
  - No toca tablas existentes, salvo las FK entrantes hacia `cira_search_log` (ON DELETE SET NULL), `users` (SET NULL) y las
    tablas nuevas.
- **Impacto:** sin escrituras en datos existentes; bloqueo breve de DDL. Las tablas no quedan expuestas en la API REST (RLS +
  REVOKE).
- **Plan de despliegue**, después del PASS de T+24 h de TASK-0026:
  1. respaldo lógico nuevo y restauración ensayada en un clon ICU;
  2. `migrate --pretend` en el clon;
  3. *fast-forward* de `feature/upgrade-filament-v3` `6e7f525` → `5e6e3fc` y el workflow;
  4. verificar que el lote nuevo contiene solo `2026_11_13_130000`, más RLS, REVOKE y el trigger;
  5. crear la campaña e invitar a un evaluador de prueba;
  6. monitorizar.
- **Rollback:**
  - `migrate:rollback --step=1`, si ese lote sigue siendo el último, o si no, el SQL de bajada ensayado (DROP de las 4
    tablas y de la función) más borrar su fila de `migrations`;
  - para el código, *fast-forward* inverso con la imagen previa.
  - No hay datos que perder salvo evaluaciones creadas después.

## Autorizaciones posteriores a la entrega (ciclo de despliegue, misma tarea)

| Fecha | Comentario | Efecto |
|---|---|---|
| 2026-11-13 13:58Z | [`6855056483`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6855056483) | **Autoriza el DDL** de `2026_11_13_130000_create_cira_evaluation_tables` en la BD compartida y el despliegue de `5e6e3fc` solo en staging. **No** levanta el gate de 24 h de TASK-0026 |
| 2026-11-13 14:02Z | [`6855171480`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6855171480) | **Excepción controlada al gate de 24 h de TASK-0026** para desplegar hoy, solo en staging. Exige: preflight reforzado PASS (incluida la monitorización parcial de TASK-0026); respaldo restaurable; rollback probado; solo la migración `2026_11_13_130000`; pruebas posteriores; nueva monitorización de 24 h. Si falla cualquier control, STOP. Sin producción ni `main` |

Estado: ~~BLOCKED_PENDING_DB_GATE~~ → **IN_PROGRESS (despliegue)**, 2026-11-13 ~14:10Z. La ventana de 24 h de TASK-0026 queda
**interrumpida por excepción expresa**, no PASS. El antecedente se conserva.

<details><summary>Texto íntegro de <code>6855056483</code></summary>

# AUTORIZACIÓN EXPRESA DEL PROPIETARIO — TASK-0027: migración Supabase y despliegue en staging (CONDICIONADA A TASK-0026)

**Fuente:** instrucción explícita del propietario del 2026-11-13: «autorizo la migracion en la base de datos en supabase y desplegar a staging la aterea de evaluacion de los resultados del buscador para poder indicar hoy miso a la camara que realice dichas pruebas».

Queda **AUTORIZADA** para TASK-0027 la migración exacta `2026_11_13_130000_create_cira_evaluation_tables` sobre la BD Supabase compartida de desarrollo/staging y el despliegue exclusivo a staging del candidato `feat/cira-evaluation-module` @ `5e6e3fc`, sujeto a los controles y alcance de la entrega [6854941089](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6854941089). Esta autorización levanta **solo el gate de consentimiento de DDL** de TASK-0027.

**NO SE LEVANTA el gate temporal ni de seguridad de TASK-0026.** TASK-0026 permanece `DEPLOYED_AWAITING_24H`; su T+24h no puede cerrarse antes del **2026-11-14 11:43:38Z**. La intención del propietario de invitar hoy a la Cámara no constituye por sí sola una dispensa explícita de ese gate. **PROHIBIDO desplegar TASK-0027 hoy o interrumpir/solapar la observación**, salvo nueva instrucción expresa del propietario que reevalúe ese riesgo y documente la decisión. No hay autorización para producción ni `main`.

## Instrucción para ejecución posterior al gate
1. **STOP ahora**: registrar la autorización en la fuente persistente, mantener candidato y BD sin cambios; no ejecutar DDL ni push/deploy mientras TASK-0026 no tenga PASS documentado. No iniciar polling, automatizaciones ni ejecutor autónomo.
2. Una vez obtenido y documentado PASS real de TASK-0026 T+24h (no basta el transcurso del tiempo), comprobar rama, HEAD, estado limpio, BASE `6e7f525`, candidato `5e6e3fc` y ausencia de despliegues o migraciones concurrentes. Si cambia BASE o aparece una nueva migración, **STOP** y solicitar revisión.
3. Respaldo nuevo de BD compartida, restauración verificada en clon, rollback ensayado y `migrate --pretend` para confirmar **solo** `2026_11_13_130000_create_cira_evaluation_tables` en lote nuevo; confirmar que migración de TASK-0013 ya figura aplicada. Si preflight no es PASS, **STOP**.
4. Desplegar únicamente el código candidato autorizado en staging mediante fast-forward limpio, verificando que el workflow `migrate --force` ejecuta exclusivamente la migración autorizada; comprobar 4 tablas, FK, RLS/REVOKE, trigger, roles y accesos, sin alteraciones de CIRA/n8n/ranking/taxonomía ni de datos preexistentes.
5. E2E real con evaluador de prueba: CIRA Test → búsqueda → 👍/👎 → «Evaluar esta búsqueda» → autenticación/invitación → 8 guías → guardar/reanudar → panel privado e informe; validar escritorio y móvil y registrar URL efectiva, invitación y procedimiento para la Cámara (sin publicar tokens ni datos privados). Confirmar accesibilidad antes de invitar al cliente.
6. Documentar en `docs/orquestador/tasks/0027-cira-evaluation-module.md`, `SESSION_RESUME.md`, `current_task.md` y `audit/` el estado, BASE/TESTED/DEPLOY/HANDOFF, respaldo, resultados, regresión, monitorización y rollback. Publicar en Issue #2 `DEPLOYED` o `BLOCKED` con evidencia. STOP, sin producción ni `main`.

**Prioridad de negocio:** habilitar cuanto antes la evaluación institucional de CIRA por la Cámara, pero sin presentar como disponible una función todavía no desplegada ni comprometer el gate de seguridad existente.

</details>

<details><summary>Texto íntegro de <code>6855171480</code></summary>

# DECISIÓN EXPRESA DEL PROPIETARIO — EXCEPCIÓN CONTROLADA AL GATE T+24h DE TASK-0026 PARA DESPLEGAR TASK-0027 HOY

**Autorización textual del propietario (2026-11-13):** «Autorizo expresamente la excepción al gate de observación de 24 horas de TASK-0026 para desplegar hoy TASK-0027 exclusivamente en staging. Exijo preflight reforzado PASS, respaldo restaurable, rollback probado, ejecución exclusiva de la migración 2026_11_13_130000, pruebas posteriores y nueva monitorización de 24 horas. Si falla cualquier control, STOP. No autorizo producción ni main. Publica esta decisión en Issue #2.»

**Esta decisión modifica expresamente el gate temporal** de los comentarios [6855056483](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6855056483) y [6852945146](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6852945146), **sin borrar el antecedente ni declarar TASK-0026 PASS**. La excepción se limita a la convivencia de TASK-0026 y TASK-0027 en **staging**, no exime ningún otro control de seguridad. TASK-0027 tiene candidato aislado probado `feat/cira-evaluation-module` @ `5e6e3fc`, BASE staging reportada `6e7f525`, entrega [6854941089](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6854941089).

## Ejecución autorizada, condicionada a gates técnicos estrictos
1. Antes de editar o ejecutar: seguir `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md`, y TASK-0027/TASK-0026 como referencias directas. Verificar `git status -sb`, rama, HEAD, worktrees, remoto, workflow, migraciones pendientes, staging real, cambios preexistentes y actividad concurrente. No reutilizar TASK-0026 ni crear otro identificador para este ciclo de despliegue de TASK-0027; registrar la nueva decisión en la tarea existente preservando historial.
2. **Preflight reforzado TASK-0026:** capturar evidencia de la monitorización parcial hasta este momento (salud HTTP, logs, errores, ACL y `security_invoker` de 13 vistas, regresión de consumidores). Si hay error nuevo, anomalía no explicada, monitorización incompleta que impida evaluar salud o drift, **STOP**. Registrar que la ventana original T+24h quedó **interrumpida por excepción expresa**, nunca PASS retroactivo.
3. **Preflight de TASK-0027:** verificar que `5e6e3fc` sigue siendo fast-forward limpio desde HEAD real de `feature/upgrade-filament-v3`, que el diff corresponde exclusivamente al módulo y que el lote de migración contendrá **solo** `2026_11_13_130000_create_cira_evaluation_tables`. La migración `2026_11_06_120000` de TASK-0013 debe figurar ya aplicada. Si hay otros commits/migraciones, divergencia o datos modificados de forma inesperada, **STOP** sin rebase/force ni sustituciones improvisadas.
4. **BD Supabase compartida:** respaldo nuevo íntegro con hash verificable, restauración ensayada en clon compatible (incluyendo ICU/ACL/FK), rollback probado, conteos y huellas antes/después, `migrate --pretend`, RLS/REVOKE y trigger verificados. Solo con todos los controles PASS se autoriza el DDL exacto de TASK-0027. No alterar registros existentes ni datos de empresas.
5. **Despliegue:** promover exclusivamente `5e6e3fc` a staging mediante fast-forward limpio; verificar que `migrate --force` ejecuta exclusivamente `2026_11_13_130000`. Validar 4 tablas, 11 FK según entrega, función/trigger, ACL, 13 vistas SEC-01, controles SEC-03, y que CIRA, n8n, ranking, embeddings, índices y taxonomía permanecen intactos. Si hay fallo, STOP, ejecutar reversión solo según procedimiento ensayado y documentar impacto real.
6. **Pruebas posteriores y gate de apertura a la Cámara:** ejecutar regresión funcional, E2E de escritorio y móvil, búsqueda real desde CIRA Test, 👍/👎, vinculación de log, autenticación e invitación, ocho guías, borrador/reanudación, envío, panel `super_admin`, informe y pruebas negativas de permisos. Usar cuenta sintética autorizada; no publicar datos privados, credenciales ni enlaces de invitación en GitHub. **No comunicar que la campaña está disponible hasta que todo esto sea PASS**.
7. **Nueva monitorización de 24 horas** desde la hora real de despliegue de TASK-0027: controles diferenciados para seguridad SEC-01 de TASK-0026 y funcionalidad/seguridad del módulo TASK-0027; registrar explícitamente la interrupción de la ventana anterior, nueva hora de inicio y final mínimo. No cerrar TASK-0026 ni TASK-0027 como completadas antes de cumplir controles correspondientes. Registrar alertas, criterios de rollback y responsable de seguimiento; **no reactivar el ejecutor ni polling automático**.
8. Persistir decisión, evidencia y estados en `docs/orquestador/tasks/0026-*.md`, `docs/orquestador/tasks/0027-*.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md`, `audit/` y handoff. Publicar en Issue #2 el resultado `DEPLOYED_AWAITING_24H` o `BLOCKED/ROLLED_BACK`, con BASE/TESTED/DEPLOY/HANDOFF, run, backup y pruebas; **STOP** al terminar.

**Límites absolutos:** no producción, no `main`, no otras migraciones, no n8n, no cambios del algoritmo/ranking de CIRA, embeddings, taxonomía, permisos globales, secretos, Cloudflare/HSTS, operaciones destructivas ni force-push. Si cualquier control falla, STOP y solicitar nueva decisión; la urgencia comercial no sustituye el preflight.

**Prioridad de negocio:** habilitar cuanto antes la campaña de evaluación institucional de la Cámara, sin afirmar despliegue antes de su verificación.

</details>

## Anexo: texto íntegro de la fuente

<details><summary><code>6852945146</code></summary>

# ASIGNACIÓN EXPRESA DEL PROPIETARIO — NUEVA TAREA INDEPENDIENTE: campaña web de evaluación institucional de CIRA

**Autorización del propietario (2026-11-13):** «Sí, autorizo crear y publicar en Issue #2 una nueva tarea independiente para implementar en staging el módulo web de evaluación de CIRA para la Cámara, con las ocho pruebas guiadas, enlace desde CIRA Test, registro de votos y resultados, panel de seguimiento y pruebas técnicas. Sin modificar el algoritmo de CIRA, producción ni main».

**PRIORIDAD FUNCIONAL:** aceptación del buscador CIRA por la Cámara Petrolera. Las pruebas manuales del propietario sobre INELECTRA, DAYCO TELECOM, SERVI COMPRESORES y DATA POWER DEAR son **paralelas y fuera del camino crítico**: no incluirlas como requisito para abrir la campaña ni desviar la tarea hacia cambios de taxonomía o ranking.

## 0. Protocolo y aislamiento
1. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md`, y solamente las referencias directas de esta asignación: `docs/orquestador/tasks/0013-*` (CIRA), `docs/orquestador/tasks/0026-sec01-t24-02-staging-deploy.md` (monitorización/gates) y los archivos de código relevantes de CIRA Test/feedback. Comprobar `git status -sb`, rama, HEAD, remotos y cambios existentes antes de editar. No perder trabajo previo.
2. Comprobar identificador consecutivo disponible en `docs/orquestador/tasks/` y **crear nueva TASK**; no reutilizar TASK-0013 ni TASK-0026, no inventar número sin inspección. Registrar este comentario como fuente y materializar `ASSIGNED` → `IN_PROGRESS` en los archivos versionados.
3. TASK-0026 permanece `DEPLOYED_AWAITING_24H`: no interrumpir su monitorización, no declarar su cierre y no mezclar commits, migraciones ni evidencia de seguridad. Si la nueva tarea amenaza su observación o hay otro deploy concurrente, STOP y reportar bloqueo; planificar la promoción de CIRA después del gate T+24h de TASK-0026.

## 1. Producto: página de evaluación para la Cámara
- Incorporar desde la interfaz existente **CIRA Test** un enlace visible «Evaluar esta búsqueda» junto a los resultados, y acceso a «Guía de evaluación» / «Mis pruebas». Definir la ruta real a partir de la arquitectura existente; `/cira-test/evaluacion` es ruta sugerida, no un supuesto sobre rutas ya implementadas. Respetar responsive móvil/escritorio, español claro y accesibilidad.
- Implementar **8 pruebas guiadas** con instrucciones breves, ejemplos, progreso, posibilidad de reanudar, búsquedas libres y posibilidad de volver a CIRA:
  CPV-01 búsqueda por actividad conocida; CPV-02 sinónimos/vocabulario sectorial; CPV-03 empresa por nombre; CPV-04 capacidad específica frente a sector general; CPV-05 búsqueda negativa (evitar falsos positivos); CPV-06 relevancia/orden de los 5 primeros; CPV-07 actividad transversal y clasificación; CPV-08 explicación de coincidencia respaldada por datos.
- El evaluador elige la búsqueda real (no se generan resultados ficticios), valora «Correcta / Parcial / Incorrecta», registra empresa(s) faltantes, resultado(s) sobrantes, motivo/comentario y observación opcional. Guardar automáticamente fecha, identificador de campaña, prueba, evaluador autorizado pseudonimizado, `search_log_id`, texto de consulta, resultados e IDs/posiciones/versión del motor disponibles; conservar una instantánea inmutable o referencia estable para que cambios posteriores de ranking no distorsionen la evidencia. Mostrar claramente los campos no disponibles sin fabricarlos.
- **Integración con 👍/👎 existente:** investigar y reutilizar `cira_search_log` y `cira_search_feedback`, con su semántica y claves actuales. Vincular votos a empresa/posición/búsqueda; no duplicar votos ni crear dos fuentes de verdad. Si un voto no puede correlacionarse, mostrarlo como no vinculado, sin atribuirlo a una búsqueda equivocada. No alterar la lógica de puntuación ni aprender automáticamente de los votos.
- Formulario sencillo, validaciones, confirmación de guardado, manejo de fallos y recuperación ante recarga/doble clic; idempotencia de envíos y protección contra datos inválidos, CSRF, XSS, inyección y acceso horizontal. No exponer claves de servicio, datos privados ni evaluaciones de otros usuarios. Acceso limitado a evaluadores invitados/autorizados, acorde con la autenticación existente; si se requiere un enlace firmado, expiración y revocación; no inventar un nuevo sistema de identidad sin justificarlo.
- Panel interno **solo para roles autorizados**: campañas, pruebas completadas/pendientes, evaluadores, búsquedas y votos, empresas faltantes/sobrantes, explicaciones, comentarios y clasificación de hallazgos (datos, taxonomía/sinónimos, indexación, ranking, explicación, otros). Filtros, detalle y trazabilidad de cada evaluación. Informe consolidado de aceptación y prioridades de mejora; exportación opcional, **nunca Excel como interfaz principal**. No hacer público el panel ni datos personales.
- Separar claramente **resultado técnico** de **juicio sectorial**; permitir a la Cámara aprobar, observar o rechazar resultados, sin que la UI declare automáticamente aceptado todo el buscador.

## 2. Diseño y persistencia
- Inspeccionar implementación real del módulo CIRA, rutas, componentes, controladores, modelos, permisos, registros y tablas existentes antes de diseñar. Proponer esquema mínimo para campañas/pruebas/evaluaciones **solo si no existe solución equivalente**; claves foráneas, unicidad, auditoría y RLS/permisos adecuados. Diseñar migraciones versionadas y rollback ensayable, sin romper `cira_search_log` ni `cira_search_feedback`.
- **GATE ESPECIAL DE BD COMPARTIDA:** la BD Supabase de staging también sirve a desarrollo. Esta autorización permite diseñar y probar migraciones en **clon aislado**, pero **no autoriza por sí sola ejecutar nuevas DDL/migraciones sobre la BD compartida** ni operaciones de datos masivas. Antes de desplegar un esquema nuevo, presentar diff, impacto, backup/restauración/rollback y pedir autorización expresa separada del propietario. Tampoco se autoriza modificar n8n ni el algoritmo de CIRA.
- El despliegue de código en staging está autorizado en principio **solo dentro del alcance**, después de TASK-0026 y de preflight PASS, pero si el workflow ejecuta `php artisan migrate --force` y hay migraciones nuevas o ajenas, **STOP antes del push/deploy** hasta el gate de BD. No utilizar force-push, no integrar cambios de otras tareas, no modificar `main` ni producción.

## 3. Pruebas y criterios de aceptación
- Tests automatizados de rutas, permisos por rol, acceso anónimo no autorizado, IDOR, CSRF, validaciones, unicidad/idempotencia, vínculo exacto con `search_log_id`, votos preexistentes, concurrencia, reanudación, informes, consultas sin resultados, búsquedas sin log, latencia/error de CIRA, XSS en comentarios y preservación de resultados originales.
- E2E de escritorio y móvil: desde CIRA Test → búsqueda → 👍/👎 → evaluar → guardar → reabrir → panel interno; sin transcripción manual ni pérdida de contexto; probar las 8 guías. Contrastar estado de antes/después: búsqueda, ranking, respuestas, n8n y taxonomía sin cambios. Suite completa de regresión y evidencia de no afectación de SEC-01/SEC-03.
- Entregar una **guía breve para la Cámara**, con acceso, pasos, criterios de valoración, ejemplos de comentarios útiles y duración estimada de 35–50 minutos; permitir guardar por partes. No fijar nombres de empresas ni resultados esperados sin respaldo de la Cámara.
- Resultado verificable: campaña creada en staging, acceso de evaluador comprobado, 8 casos navegables, votos vinculados sin duplicación, persistencia y reanudación, panel privado funcional, informe consolidado, pruebas y rollback PASS. Si faltan permisos o gate de DB, entregar candidato aislado + `BLOCKED_PENDING_DB_GATE`, no fingir que está desplegado.

## 4. Límites, evidencia y handoff
- **Prohibido:** cambios de algoritmo/ranking, embeddings, índices de búsqueda, taxonomía o datos de empresas, publicación de candidatos, producción, `main`, n8n, infraestructura Cloudflare, HSTS, roles/permisos globales o secretos; tampoco ejecutar la batería de las cuatro empresas como parte de esta tarea.
- Guardar especificación funcional, decisiones de UX, modelo de datos, pruebas, capturas/evidencia sanitizada y resultados en documentos versionados de la nueva TASK y `audit/`. Actualizar `current_task.md`, `SESSION_RESUME.md` y `audit/orchestrator_handoff.json` conservando antecedentes y distinguiendo BASE/TESTED/REVIEW/HANDOFF/DEPLOY SHA. En Issue #2 entregar `READY_FOR_REVIEW` (o `BLOCKED`) con enlaces, pruebas, gate pendiente y cambios exactos; STOP al finalizar, sin arrancar la siguiente tarea.

**Prioridad de decisión:** experiencia simple y evidencia útil para que la Cámara valide CIRA. Las pruebas internas de empresas se realizarán paralelamente por el propietario y no bloquean este módulo.

</details>

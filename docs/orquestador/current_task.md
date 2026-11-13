# Tarea actual (checkpoint 2026-11-11, rama `fix/sec-01-r4-01-role-editor-preserve`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## 2026-11-11 17:17Z: decisiones del propietario sobre la consolidación de pendientes (`6802438608`) — solo registro documental

No cambia la tarea activa ni el propósito de ninguna tarea, y no asigna implementación. El detalle está en el addendum de
[`PENDIENTES_CONSOLIDADO_2026-11-11.md`](PENDIENTES_CONSOLIDADO_2026-11-11.md) (corte `f6e3134`).

| Tarea o entrega | Declaración del propietario | Sigue pendiente |
|---|---|---|
| TASK-0017 (SEC-03) | Autoriza en alcance P1–P5 con pruebas aisladas, sin despliegue automático; P1 antes de P2 | Materializar la tarea de ejecución; revisión de la auditoría |
| SEC-01 R3, R4 y R4-01 | Autoriza revisión final, integración y despliegue en staging, con respaldo, regresión y verificación de preservación de permisos; no producción ni `main` | Asignación de ejecución. Hasta verificar R4-01, no guardar roles en el editor de staging |
| TASK-0012 | Recibió correos de verificación y recuperación; autoriza pruebas controladas en staging con cuentas y buzones de prueba | Evidencia formal de todos los flujos, incluido RIF y los controles de SEC-03 |
| TASK-0013 | UAT declarada: explicación de coincidencias y votos. Búsqueda y ranking sin validación del cliente; mejoras adicionales a fase 2 | Validación del cliente. Pedir a la Cámara que revise sectores principales y «X» antes de cambiar datos |
| TASK-0014 | Búsqueda probada en parte; diccionario sin probar. Pide configurar y probar la búsqueda semántica en staging | UAT del diccionario; configuración semántica (asignación) |
| TASK-0015 | UAT declarada: cambios de código de categoría | Revisión técnica; comentario de entrega en el Issue #2 |
| TASK-0016 | UAT declarada: configuración de IA y análisis de solicitudes | Revisión técnica |
| *Hotfix* de paginación (`b048df0`, Issue `6794806339`) | UAT declarada: paginación sin «Todos» | No tiene archivo de tarea; aquí no se le asigna identificador |
| Descargas (`ce70b7f`), perfil completo en PDF (`514fa6e`), secciones del PDF (`87dd997`), Inicio (`0f8bdad`) | UAT declarada | Caché de Cloudflare `/pdf/*` y `/xls/*`, sin comprobar |
| Producción | Plan técnico comparativo, checklist y ensayo aislado; credenciales y n8n solo en planificación | Asignación del plan. Gate: seguridad crítica y alta cerrada, UAT completa, aceptación de la Cámara y autorización explícita |

«UAT declarada» es la declaración del propietario en `6802438608`. No es aprobación de seguridad, integración ni despliegue, y
ningún estado de abajo pasa por eso a APPROVED, DEPLOYED o CLOSED. El bloque de TASK-0013 de abajo es del 2026-11-06: los
pasos 2 a 4 cambiaron después el ranking y están en vivo (`tasks/0013`).

## 2026-11-13 10:55Z: **TASK-0025** — SEC-01: corrección candidata de F-SEC01-T24-02 (vistas de `public`) — **IN_PROGRESS** (`6850596236`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0025-sec01-t24-02-public-views-hardening.md`](tasks/0025-sec01-t24-02-public-views-hardening.md) |
| Autoriza | Análisis, migración versionada y pruebas en entornos desechables; **no** DDL en la BD compartida ni despliegue |
| En espera | R-06 (R3-02/R3-03) hasta revisar esta prioridad; F-SEC01-T24-01 abierta y contenida; R-04 con su propio gate |
| Staging vivo | `270e07d` (sin cambios) |

## 2026-11-13 10:39Z: TASK-0024 — **APPROVED**, solo diagnóstico y planificación (`6850519341`, ratificado en `6850596236`)

Cierres R-01/R-02/R-03 aceptados; R-04 con gate propio (no autorizado); R-05 verificada con dos hallazgos abiertos; la prueba
roja de reproducción no es PASS. T24-02 es potencial, no P0 por sí sola, y pasa a TASK-0025. T24-01 sigue abierta y no es
prioritaria. R-06 queda en espera.

## 2026-11-13 09:50Z: **TASK-0024** — SEC-01: diagnóstico y plan de cierre de las reservas de TASK-0023 — ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (~10:30Z, `6848913937` + decisiones `6849259532`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0024-sec01-t23-reservations-diagnosis.md`](tasks/0024-sec01-t23-reservations-diagnosis.md); diagnóstico en [`../security/SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md`](../security/SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md) |
| Tipo | Solo análisis y pruebas aisladas: sin escrituras reales, despliegue, migraciones ni cambios de FK en staging |
| Decisiones vigentes | R-01 UAT confirmada por el propietario; R-02 no restaurar; R-03 conservar las filas inertes (riesgo aceptado); R-04 remediar con plan revisado y gate aparte; R-05 verificar; R-06 R3-02/R3-03 en tarea independiente posterior |
| Resultado | R-01/R-02/R-03 cerradas. R-04 demostrada en copia aislada (restauración limpia, rollback exacto), pendiente de gate. R-05: límite de R4 interno (0 escritores sin guarda); hallazgos nuevos F-SEC01-T24-01 (borrado de una cuenta `super_admin` desde la lista; latente) y F-SEC01-T24-02 (vistas legibles por `anon` en la Data API; no reproducido). Pruebas en `audit/task-0024-r05-writer-paths` @ `8e8b80c` |
| Staging vivo | `270e07d` (sin cambios) |
| Siguiente | revisión; decisiones sobre T24-01/T24-02 y el gate de R-04; después, tarea independiente de R-06 |

## 2026-11-13 09:14Z: TASK-0023 — **APPROVED con reservas** (`6848907151`)

Aprobada en su alcance (despliegue en staging `270e07d`), sin cerrar SEC-01 ni autorizar producción o `main`; basada en la
evidencia documentada, no en una UAT independiente. Reservas: UAT visual pendiente; R1 sin restauración automatizada y R2
sin tocar; las 6 filas `*_shield::role` no se borran sin autorización; 2 huérfanas de `contact_empresa` como deuda
preexistente; límites de R4 como riesgo residual; SEC-01 abierta. Las reservas pasan a TASK-0024.

## 2026-11-12 17:45Z: **TASK-0023** — SEC-01: desplegar en staging la corrección de F-SEC01-T21-01 — ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (~18:00Z, `6831152066`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0023-sec01-t21-01-staging-deploy.md`](tasks/0023-sec01-t21-01-staging-deploy.md) |
| Staging vivo | ~~`71e8a6d`~~ → **`270e07d`** (workflow `43274064073`, *fast-forward*); HSTS 1 día |
| Resultado | Alcance, respaldo (`sec01-t23-20261112T174040Z`), restauración aislada (FK con `NOT VALID`), regresión (93/93 y 798/798) y validación sintética en vivo: PASS. Roles reales idénticos a la línea base |
| Límites | R1/R2 sin tocar; filas `*_shield::role` sin borrar; sin producción, `main` ni HSTS |
| Siguiente | revisión del propietario; UAT visual opcional del editor, sin guardar roles reales |

## 2026-11-12 17:36Z: TASK-0022 — **APPROVED** con alcance limitado (`6831144754`)

Aprobada como entrega de código y análisis; no implica despliegue (pasa a TASK-0023). Recuperación: **sin** ejecución
automatizada ni inserciones; R1 y R2 no se modifican; el propietario podrá restaurar `view_any_user` en R1 a mano si lo
necesita. No borrar las 6 filas `*_shield::role` sin autorización aparte.

## 2026-11-12 16:30Z: **TASK-0022** — SEC-01: corregir F-SEC01-T21-01 y evaluar la recuperación de permisos de octubre — ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (~17:05Z, `6829151868`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0022-sec01-t21-01-role-section-and-permission-recovery.md`](tasks/0022-sec01-t21-01-role-section-and-permission-recovery.md) |
| Rama de trabajo | `fix/sec-01-t22-role-permission-key` (desde staging `71e8a6d`, worktree aparte): TESTED `4bdec36`, REVIEW `270e07d` |
| Resultado | Parte A: sección «Role» con los 6 `*_role` reales en solo lectura; fails-before `23b5c0d`; enfocadas 28/28; suite 798/798. Parte B: 128 vínculos siguen faltando (109 ocultos por el defecto, 19 visibles); recomendación: como máximo 1 (R1 ← `view_any_user`), por script (i) o a mano (ii) |
| Staging | **sin cambios** (`71e8a6d`); 0 escrituras reales |
| Siguiente | revisión; gate de despliegue aparte; decisión sobre el vínculo de R1 |

## 2026-11-12 16:17Z: TASK-0021 — **APPROVED con reservas** (`6829143372`)

UAT visual del propietario en el navegador, sin guardar roles reales. Reservas: F-SEC01-T21-01 se corrige en tarea nueva;
la recuperación de permisos de octubre se desea solo si coste y riesgo son bajos, sin restauración ciega ni masiva; no
marcar las seis casillas `*_shield::role`; no restaurar administración de roles a actores distintos de `super_admin`;
SEC-01 sigue abierta. Ambas decisiones pasan a TASK-0022.

## 2026-11-12 12:11Z: **TASK-0021** — SEC-01: revisión de R4-01 e integración/despliegue de R3+R4+R4-01 **solo en staging** — ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (~12:55Z, `6823247114`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0021-sec01-r3-r4-r401-staging-deploy.md`](tasks/0021-sec01-r3-r4-r401-staging-deploy.md) |
| Rama de trabajo | `integrate/sec-01-r3-r4-r401-staging` (desde staging `9d0c24d`; solo los 12 commits de código y pruebas de R3/R4/R4-01, más 2 de pruebas de la tarea) |
| Staging vivo | ~~`9d0c24d`~~ → **`71e8a6d`** (workflow `43220318614`); SEC-03 intacta, HSTS 1 día |
| Resultado | gates 1–4 PASS; suite 794/794; respaldo y restauración ensayada (FK con `NOT VALID`); validación sintética en vivo PASS; residual F-SEC01-T21-01 (Baja) |
| Límites | sin producción, `main`, Cloudflare/HSTS, n8n, taxonomía ni cambios a roles reales; no restaurar permisos perdidos |
| Siguiente | revisión del propietario; UAT del editor de roles (sin guardar roles reales); F-SEC01-T21-01 y la recuperación de permisos, decisiones aparte |

## 2026-11-12 11:42Z: TASK-0020 — **APPROVED con reservas** (`6822634878`)

Aprobada la ejecución de las pruebas operativas en staging. **Sin tarea activa nueva.** Reservas:
- verificación del buzón nuevo no ejecutada en staging (no cuenta como UAT);
- origen de la tarea: el chat, sin comentario previo de asignación;
- deuda de UX en la pantalla de verificación;
- vinculación automática del personal en `Empresa::create`, para revisión independiente;
- FK preexistente con filas huérfanas.

Detalle en [`tasks/0020-sec03-staging-operational-tests.md`](tasks/0020-sec03-staging-operational-tests.md). SEC-01
detenida; HSTS, `main` y producción sin autorización.

## 2026-11-12 ~11:25Z: TASK-0020 — SEC-03: pruebas operativas en staging — READY_FOR_REVIEW (instrucción del propietario en el chat)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0020-sec03-staging-operational-tests.md`](tasks/0020-sec03-staging-operational-tests.md) |
| Resultado | parte 2 (adversarial) **PASS**; parte 1 (funcional, con cuentas sintéticas y Turnstile resuelto por el propietario) **PASS**, salvo abrir el enlace de verificación del buzón nuevo (no ejecutado) |
| Datos | cuentas y empresa sintéticas creadas y borradas por id; conteos en la línea base |
| Staging | sin cambios (`9d0c24d`, HSTS 1 día) |
| Siguiente | revisión; cierre de SEC-03, etapas de HSTS y producción son decisiones aparte del propietario |

## 2026-11-12 10:33Z: TASK-0019 — **APPROVED** (`6821212610`), limitada a staging

Aprobada la integración, el despliegue y la validación de SEC-03 P1–P5 en staging (`9d0c24d`, HSTS `max-age=86400`).
**Sin tarea activa nueva.** Siguen abiertos y sin asignar:
- las pruebas funcionales de correo/RIF, Turnstile y cambio de correo con buzones sintéticos;
- la prueba adversarial del borde y de los cupos;
- los aumentos de HSTS;
- el riesgo de restauración por la FK preexistente;
- SEC-01 y producción.

Ver el veredicto en [`tasks/0019-sec03-staging-deploy.md`](tasks/0019-sec03-staging-deploy.md).

## 2026-11-12 ~10:05Z: TASK-0019 — READY_FOR_REVIEW, **DESPLEGADA EN STAGING** (`9d0c24d`)

| Campo | Valor |
|---|---|
| BASE / TESTED = REVIEW = desplegado | `b048df0` / `9d0c24d` (*fast-forward*, solo SEC-03) |
| Workflow | `43196418453` success; servidor verificado en `9d0c24d` |
| Preflight | respaldo en el servidor (`/root/backups/sec03-20261112T095103Z`), restauración aislada con 0 diferencias de conteo, regresión 767/767 |
| Verificado en staging | F-SEC03-03 (IP real hasta PHP, IP efectiva = cliente) y F-SEC03-06 («Always Use HTTPS»; HSTS `max-age=86400` solo en staging); P1 (enlaces con el host de staging) |
| Sin ejercer en staging | rechazo de Host ajeno y suplantación por el borde (no autorizados); P3 y P4 (correo y escritura de cuentas) |
| Gates | sin producción, `main` ni SEC-01; HSTS siguiente etapa, `includeSubDomains` y `preload` solo con aprobación nueva |

La sección siguiente es el estado al materializar la tarea, sin cambios.

## Nueva, 2026-11-12: TASK-0019 — SEC-03: despliegue de P1–P5 solo en staging — IN_PROGRESS (`6820224859`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0019-sec03-staging-deploy.md`](tasks/0019-sec03-staging-deploy.md) (comentario fuente íntegro) |
| Código | `fix/sec-03-p1-p5` @ `9d0c24d` (TASK-0018, **APPROVED** como implementación aislada en `6820214362`) |
| Staging al empezar | `b048df0` |
| Gates | preflight (respaldo, restauración ensayada, regresión del candidato) antes de desplegar; HSTS solo tras los gates A–C; sin producción ni `main` |

TASK-0019 no cambia TASK-0018.

## 2026-11-12: TASK-0018 — **APPROVED** como implementación aislada (`6820214362`)

El propietario aprobó el código y las pruebas aisladas de `9d0c24d`. No cierra F-SEC03-03 ni F-SEC03-06. Declara activado «Always Use HTTPS» en Cloudflare (verificado por TASK-0019).

## 2026-11-12: TASK-0018, ciclo de corrección (`6819392897`) — READY_FOR_REVIEW, SIN DESPLEGAR

| Campo | Valor |
|---|---|
| Revisión | `6819392897` (propietario): CORRECTIONS_REQUIRED acotado a P2, P4 y P5, en la misma tarea |
| Rama / BASE del ciclo / TESTED_HEAD = REVIEW_HEAD | `fix/sec-03-p1-p5` / `cb3fff4` / **`9d0c24d`** |
| Matriz | P2 **PASS** en código (tránsito de cabeceras NO VERIFICADO); P4 **PASS**; P5 **PASS de código** (HTTPS efectivo pendiente) |
| Defectos corregidos | P2: proxies de confianza reducidos al gateway exacto (antes `172.16.0.0/12`); P4: tokens borrados antes del `UPDATE`. Ambos demostrados con el código anterior |
| Pruebas | Sec03 51/51; enfocada 111/111; suite completa **767 tests, 8102 aserciones, OK** (run `20261112T091647Z-9d0c24d613fb`) |
| Siguiente paso | revisión humana; despliegue y verificaciones de infraestructura solo con autorización |

Detalle en el addendum del 2026-11-12 de [`tasks/0018-sec03-p1-p5-remediation.md`](tasks/0018-sec03-p1-p5-remediation.md). La sección de abajo es la entrega del 2026-11-11, sin cambios.

## Nueva, 2026-11-11: TASK-0018 — SEC-03: correcciones P1–P5 — READY_FOR_REVIEW, SIN DESPLEGAR (`6802857575`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0018-sec03-p1-p5-remediation.md`](tasks/0018-sec03-p1-p5-remediation.md) (matriz por hallazgo, configuración, plan de infraestructura; comentario fuente íntegro) |
| Rama / worktree | `fix/sec-03-p1-p5` (pusheada, no despliega) / `PerfilAfiliadosCPV-wt-sec03-fix` |
| BASE / TESTED_HEAD / REVIEW_HEAD | staging `b048df0` (+ pruebas de TASK-0017 `8a4ed35`) / `cb3fff4` / `cb3fff4` |
| Pruebas | runner aislado: Sec03 37/37; suite completa **753 tests, 8030 aserciones, OK** (run `20261111T180350Z-cb3fff4ebac0`); los 7 oráculos de TASK-0017 pasan sin cambios |
| Resultado | F-SEC03-01..05 corregidos en candidato; F-SEC03-06 en parte (HSTS de 1 día; redirección apagada hasta verificar el túnel) |
| Gates | sin merge, sin despliegue, sin cambios de Cloudflare, nginx ni túnel. **Siguiente paso:** revisión; después, si se autoriza, despliegue en staging y las verificaciones de la tarea, §5 |

TASK-0018 no cambia TASK-0017 ni TASK-0012.

## Nueva, 2026-11-11: TASK-0017 — SEC-03: recuperación de cuentas, tokens y confianza en Host/proxies (auditoría) — READY_FOR_REVIEW (`6797800863`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0017-sec03-account-recovery-host-trust-audit.md`](tasks/0017-sec03-account-recovery-host-trust-audit.md) (estado, resultado, evidencia; comentario fuente íntegro) |
| Informe | [`audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md`](../../audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md); registro en `docs/security/SECURITY_STATUS.md` (F-SEC03-01..06) |
| Objetivo / rama de pruebas / REVIEW_HEAD | staging `b048df0` / `audit/sec-03-account-recovery` (worktree `PerfilAfiliadosCPV-wt-sec03`, solo pruebas) / `8a4ed35` |
| Resultado | **sin toma de cuentas confirmada**; 6 hallazgos confirmados. El de mayor riesgo, F-SEC03-01 (host de enlaces), está mitigado hoy por el ingreso del túnel. F-SEC03-03: límites por IP globales |
| Gates | solo auditoría: sin correcciones, despliegue, merge ni cambios de infraestructura. **Siguiente paso:** revisión y, si se desea, una autorización nueva para P1–P5 |

TASK-0017 no cambia las tareas de abajo.

## Nueva, 2026-11-11: TASK-0016 — Análisis IA de solicitudes de categorías CPV — READY_FOR_REVIEW, EN STAGING (`6791532623`; addendum del propietario en el chat)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0016-category-request-ai-analysis.md`](tasks/0016-category-request-ai-analysis.md) (descubrimiento, diseño, evidencia; comentario fuente íntegro) |
| Rama de código / BASE / REVIEW_HEAD | `feat/category-request-ai-analysis` (worktree `PerfilAfiliadosCPV-wt-ia`, sin *upstream*) / `d8e2aae` (staging) / `893351f` (con el addendum; antes `2405b67`) |
| Pruebas | suite completa `893351f`: **713 OK** (antes `2405b67`: 705 OK) |
| Proveedor | se configura en Taxonomía CPV > Configuración del análisis con IA (Anthropic con SDK oficial, u OpenAI); clave cifrada y nunca visible; presupuesto diario. Sin configurar = desactivado |
| Staging | `feature/upgrade-filament-v3` `d8e2aae..893351f`, run `42999603780` success; migraciones `2026_11_11_*` aplicadas por el workflow (lote 62); solicitudes reales sin cambios. **Siguiente paso:** UAT del propietario |
| Gates | sin merge a `main`, sin producción, sin n8n, sin cambios de taxonomía; el gasto de API empieza solo cuando el propietario configure la clave y ejecute un análisis |

TASK-0016 no cambia las tareas de abajo, que siguen en revisión.

Hay dos tareas abiertas en sesiones separadas, por decisión del propietario (2026-11-09). En la sesión de TASK-0014
se abrió además TASK-0015.

## Abierta, sesión de TASK-0014: TASK-0015 — Edición de categoría: jerarquía visible y cambio de código validado y auditado — READY_FOR_REVIEW, EN STAGING

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0015-category-code-change-validation.md`](tasks/0015-category-code-change-validation.md) |
| Fuente | pedidos del propietario en el chat, 2026-11-09, incluida la extensión a Familias y Grupos (sin comentario en el Issue #2 todavía) |
| Rama / BASE / REVIEW_HEAD | `feat/category-code-change` / `a8020cf` / `d8e2aae` |
| Pruebas | runner aislado, suite completa en `d8e2aae`: **OK (690 tests, 7662 assertions)**; staging 200 antes y después |
| Staging | `feature/upgrade-filament-v3` en `d8e2aae` (runs `42603503471` y `42605041183`, success), por instrucción del propietario en el chat |
| Siguiente paso | UAT del propietario en staging |

## Abierta, sesión nueva: TASK-0014 — Coherencia de búsqueda de taxonomía y asociación de términos desde la categoría — READY_FOR_REVIEW, EN STAGING (`6736283026`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0014-taxonomy-search-coherence-term-linking.md`](tasks/0014-taxonomy-search-coherence-term-linking.md) (comentario fuente íntegro, reproducción, diseño, evidencia y limitaciones) |
| Fuente | Issue #2 `6736283026` (orquestador, a pedido del propietario, 2026-11-09) |
| Rama / BASE / REVIEW_HEAD | `feat/taxonomy-search-coherence` (worktree `PerfilAfiliadosCPV-wt-servicios`, pusheada) / `90044f3` (staging) / `a8020cf` |
| Reproducción | las dos opciones de «balancín» son las de la consulta parcial «balan»/«balanc»: `CPV-29.04.02G` «Balancín» (correcta) y `CPV-27.03.19S` «Perforación Bajobalanceada (UBD)» (subcadena, ruido); semántica apagada en staging |
| Pruebas | runner aislado, suite completa en `a8020cf`: **OK (679 tests, 7542 assertions)**; staging 200 antes y después |
| Staging | desplegada el 2026-11-09 por instrucción del propietario en el chat: `feature/upgrade-filament-v3` `90044f3..a8020cf`, workflow `42600556875` success, verificado en `a8020cf` |
| Siguiente paso | UAT del propietario en staging |
| Gates | sin producción, sin publicación masiva de relaciones, sin cambios en datos reales; despliegue a staging solo con autorización explícita |

## Abierta, sesión actual: TASK-0013 — Calibración del buscador CIRA: lista de referencia, medición y registro de búsquedas y votos — READY_FOR_REVIEW (`6684941317`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0013-search-calibration-feedback.md`](tasks/0013-search-calibration-feedback.md) (decisiones, entregables, evidencia; comentario fuente íntegro) |
| Fuente | Issue #2 `6684941317`: registro publicado por el agente a pedido explícito del propietario en el chat (2026-11-06) |
| Rama / BASE / REVIEW_HEAD | `fix/sec-01-r4-01-role-editor-preserve` / `8e22d0c` / `bc8212b` |
| Pruebas | runner aislado `20261106T123818Z-bc8212b42e08`: `CiraSearchLogMigrationTest` **OK (7 tests, 77 assertions)**; staging 200 antes y después |
| Medición base | `docs/uat/runs/2026-11-06_base.json`: ruido conocido 48/66 en el top 5, MRR 0,742, 36/49 relevantes conocidas en el top 10 |
| Gates | migración `cira_search_log`/`cira_search_feedback` **aplicada en la base compartida el 2026-11-06** (lote 61, RLS activado), con autorización del propietario en el chat; registro de búsquedas y votos 👍/👎 **activos en staging** desde el 2026-11-06, con explicación por resultado (informe §7 y §9); sin cambios de ranking; sin merge ni producción |
| Pendiente antes de producción | rotar `N8N_API_KEY`, `CPV_GITHUB_TOKEN` y `CPV_MCP_TOKEN` (expuestas en la salida de una sesión local el 2026-11-06); corregir dos hallazgos de seguridad del flujo n8n |

**Paso 1 (asignación `6686193637`): READY_FOR_REVIEW.**
- Qué se hizo: la consulta completa en el agente de n8n, probada en la copia aislada `3PtTduMeifEe2fg9`, que quedó desactivada. Después, con la aprobación del propietario en el chat, v2 se promovió al flujo de staging `zbVLoCdR09IA9yQK` (`versionId` `279690f8…`), que el propietario aplicó y quedó verificado (informe §6).
- Dónde está: informe en `docs/uat/n8n/INFORME_TASK0013_PASO1.md`; sección «Paso 1» en el archivo de la tarea.
- Regresión congelada: `DEBUG_TOKEN` rotado por el propietario. La línea base de 32 consultas se tomó el 2026-11-06 y la segunda corrida no dio cambios.

TASK-0012 sigue igual que abajo: desplegada en staging y con la UAT pendiente.

## TASK-0012 — Recuperación de acceso por correo y RIF — DESPLEGADA EN STAGING; UAT PENDIENTE (`6602168914`, autorización de despliegue del propietario)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0012-account-recovery.md`](tasks/0012-account-recovery.md) (diagnóstico, decisiones, evidencia, pendientes; comentario fuente íntegro) |
| Fuente | Issue #2 `6602168914` (propietario, 2026-11-03) |
| Rama / BASE / REVIEW_HEAD | `fix/sec-01-r4-01-role-editor-preserve` / `73b5f2d` / `b1b9146` |
| Pruebas | antes `2737f27`: 12 de 13 fallan; suite completa `b1b9146`: **672 OK** |
| Decisión RIF | elegibilidad demostrable (R3): afiliado por nombre de rol, verificado, sin otros roles ni permisos directos, ≤3 empresas; internas excluidas; correo real pendiente de autorización |
| Staging | `feature/upgrade-filament-v3` @ `06155ce`; Deploy a Contabo run `41630703105` success; login y dos páginas de recuperación HTTP 200 (GET, solo lectura) |
| Gates | sin merge a `main`, sin producción, sin cambios en cuentas ni datos; prueba de correo real pendiente de autorización específica |

Debajo sigue el checkpoint de TASK-0011, sin cambios salvo su estado: el propietario la aprobó en `6440840198`.

## TASK-0011 — UX perfil de empresa y servicios — **APPROVED** (`6440840198`, 2026-10-27); antes READY_FOR_REVIEW (`6438570942`)

| Campo | Valor |
|---|---|
| Archivo | [`tasks/0011-ux-company-profile-services.md`](tasks/0011-ux-company-profile-services.md) (diagnóstico, decisiones, evidencia, pendientes) |
| Fuente | Issue #2 `6438570942` (propietario, 2026-10-27); valoración UX `20261027T0834Z` |
| Rama / BASE / REVIEW_HEAD | `fix/sec-01-r4-01-role-editor-preserve` / `65b858c` / `c3bce84` |
| Causa (Servicios) | «Desvincular» se quitó en `8352cad`, pero el guardado seguía exigiendo desvincular; además el sector principal deshabilitado no se envía y contaba como «fuera»; y era obligatorio estando deshabilitado. Staging: 194/406 empresas sin principal, 195 con servicios fuera de sector |
| Pruebas | antes `0f99b9c`: 10 fallos esperados; suite completa `c3bce84`: **658 OK** |
| Gates | sin merge, sin despliegue, datos reales solo lectura |

Lo que sigue debajo es el checkpoint anterior (2026-10-21 ~16:40Z), sin cambios: F-SEC01-R4-01 sigue
READY_FOR_REVIEW y sin desplegar.

## F-SEC01-R4-01 — READY_FOR_REVIEW — MANUAL (`6320241495`); sin desplegar, sin restaurar

| Campo | Valor |
|---|---|
| Rama / BASE / TESTED | `fix/sec-01-r4-01-role-editor-preserve` / `f41bcb6` / `24413d5` |
| Causa | Shield `afterSave`/`afterCreate` sincronizan solo lo enviado (desvincula lo no mostrado; `CheckboxList` acepta nombres forjados) |
| Código | RoleResource publicado en la app; `RolePermissionSync` (enviado ∩ mostrado ∪ ocultos actuales, fila bloqueada); transacción en las páginas de rol |
| Pruebas | antes (`44da9a3`): fallan 5 de 6; enfocadas 74 OK; suite completa **636 OK** |
| Recuperación | solo propuesta: ≤1 vínculo (lista de usuarios del rol ocupado, si el propietario lo confirma); 0 en el rol sin usuarios; nunca permisos de Role. Delta exacto en `%USERPROFILE%\.cpv-restricted\sec-01\r4\` |
| Informe | `audit/security_sec01_r4_01_2026-10-21.md` |

## CPV-SEC-01-R4-ROLE-ADMIN — código ACEPTADO (`6319407886`, `97737ba`), sin desplegar; CONTAINMENT_VERIFIED — MANUAL (`6319443716`)

| Campo | Valor |
|---|---|
| Regla | administrar roles es exclusivo de quien tiene hoy `super_admin` (propietario, 2026-10-21) |
| Contención en vivo | **Aplicada a mano por el propietario y VERIFICADA** en solo lectura (6319443716): 0 asignaciones fuera de super_admin, 0 permisos directos, continuidad 4/4 PASS, caché `file` coherente, gate fresco 0/325. Antes: ~~**BLOQUEADA**: el clasificador de permisos del ejecutor rechazó la escritura; sin cambios (alerta `6318733204`). Verificación previa: 2 roles, 12 asignaciones, 0 permisos directos; continuidad de super_admin con 4 cuentas~~ |
| Colateral | **F-SEC01-R4-01**: el editor de roles quita al guardar los permisos que no muestra (116 ocultos; se perdieron 115 en el rol sin usuarios y 1 de gestión de usuarios en el rol con 1 usuario). Decisión del propietario; no guardar super_admin en el editor. Diferencia exacta en `%USERPROFILE%\.cpv-restricted\sec-01\r4\` (fuera de git) |
| Rama / BASE / TESTED | `fix/sec-01-r4-role-administration` / `66f5ce8` (R3 aceptado) / `b51fa3e` |
| Código | `RolePolicy` (super_admin, consulta fresca, más el permiso) y `PrivilegedRoleDefinitionGuard` (identidad del rol privilegiado) |
| Pruebas | antes (`4953be6`): fallan 4 de 5; suite completa `b51fa3e` **629 OK** |
| Ayuda | `docs/manual_usuario/manual_administrador.md` §9.1 |
| Informe | `audit/security_sec01_r4_2026-10-21.md` |

## CPV-SEC-01-R3 — aceptado (`6318588005`), NO desplegado (`6316775771`)

| Campo | Valor |
|---|---|
| Rama / BASE / TESTED | `audit/sec-01-r3-company-boundaries` / `eb03b4c` / `e95169c` (suite completa **624 OK**) |
| Fails-before | `audit/sec-01-r3-fails-before` @ `9fb0469` (solo evidencia): fallan las 3 pruebas de re-autorización |
| Corregido | F-SEC01-R3-01: `EditEmpresa` y sus 10 gestores re-autorizan en cada petición (`EmpresaAccess`, `ReauthorizesEmpresaAccess`) |
| Registrado | R3-02 (permisos de módulo en acciones propias), R3-03 (categoría inactiva por id forjado), **R3-04 (Alta, alerta: editor de roles)**, Q-05, Q-06 |
| Matrices | 7 gestores; búsquedas en Customer, TaxonomyCandidateTerm y Role (no buscable) |
| Cobertura restante | 14 relation managers (recuento; 13 según el denominador anterior); 9 búsquedas |
| Restringido | sonda y salida en `%USERPROFILE%\.cpv-restricted\sec-01\r3\` (fuera de git) |
| Informe | `audit/security_sec01_r3_2026-10-21.md`; registro `docs/security/SECURITY_STATUS.md` |

## CPV-SEC-01-R1-R2-DEPLOY — desplegado, **UAT del propietario PASS** (`6316265832`, registrado en `6316775771`)

| Campo | Valor |
|---|---|
| Aceptado / desplegado | `cf01e9c3e30415a34ccda60b312a993c1388167d` (aceptación `6310331211`, FINAL R2 REVIEW) por push normal fast-forward desde `87dd997` |
| Workflow | run `39602544527` success, 12:48–12:50Z |
| Runtime | checkout `cf01e9c` (versionados limpios), imagen `081c35c5715d`, debug y mantenimiento apagados, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`) → `latest`, `git reset --hard 87dd997`, `docker compose up -d app` (no hizo falta) |
| Misma conexión | cuenta y rol/pivote → `pgsql` (antes y después) |
| Solo lectura | `docs/deploy/smoke_sec01_readonly.php` 17/17 (antes 3/17); export 25/25, 0010C 18/18, perfil 13/13; HTTP: login 200, `/form` 404 |
| Sin probar en staging | escrituras de cuentas, rollback, clic de suplantación; evidencia = suite aislada `f4dd8fb` (611 OK) |

**UAT del propietario (solo con sus cuentas habituales; no intentar escalar privilegios en cuentas reales):**
1. Como super admin: **Usuarios** muestra la lista y la acción «Impersonate» sigue disponible.
2. Con una cuenta de staff que no es super admin (si la tiene): la acción «Impersonate» ya no aparece.
3. Editar el nombre de una cuenta de prueba designada y guardar: el cambio queda.
4. `https://pruebas.camarapetrolera.app/form` → «Página no encontrada».

## CPV-SEC-01-R2-ACCOUNT-PATH — aceptada (`6310331211` FINAL), desplegada arriba (corrección de atomicidad R2-REV-05)

| Campo | Valor |
|---|---|
| Fuente | sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION» de `6310331211` |
| Arreglo | `UsesAccountWriteTransaction` en `CreateUser` y `EditUser`: `hasDatabaseTransactions()` = true solo en esas páginas; begin/commit/rollback en las conexiones de `User` y del modelo de rol (pivote). Guarda previa a la sincronización intacta; comentarios «ya atómico» corregidos |
| Pruebas | `AccountWriteAtomicityTest` (5). Antes `309146b`: 3/5 fallan. Después `f4dd8fb`: enfocadas **45 OK** (1.058); suite completa **611 OK** (7.042), staging 200 antes/después |
| REVIEW_HEAD | el commit de docs sobre `f4dd8fb` (ver el comentario de entrega) |

### Pausa anterior (`6313400617`), ya superada

Pausa por cuota. Entrega previa `6311569828`.

| Campo | Valor |
|---|---|
| REVIEW_HEAD | `51a923ee0e8ea3fb8c9b0566bd53de4ca6e0f078` (runtime = `e7ba59d`; encima solo docs) |
| Probado | suite completa `6bf8582aa8e7155c10ca560fb6089d7b5bef73ef` (606 OK); enfocadas `e7ba59d9deb35198bc9fb3fac857255ebaa9e0e2` (22 OK). Los informes son evidencia de ejecución del desarrollador, no una revisión independiente |
| Estado | segunda revisión: **CAMBIOS SOLICITADOS**, no aceptada, sin desplegar |
| Próxima corrección (acotada) | activar explícitamente las transacciones en `CreateUser` **y** `EditUser` sobre la conexión real de cuentas/pivote; pruebas de inyección de fallo con rollback comprobado antes del teardown; conservar la autorización previa a la sincronización |
| Fuente | **solo** la sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION» de `6310331211` |
| Por qué | Filament v3.3.55 (fijado) deja las transacciones del panel desactivadas por defecto y este candidato no las activó; la afirmación «ya atómico» era incorrecta (retirada en el registro) |
| Progreso revisado que se mantiene | guarda previa a la sincronización; pruebas de rutas con canario y texto de PDF; matriz independiente de alto valor con fallo ante errores inesperados; registro acumulativo |
| Cobertura y decisiones | sin re-inventariar: conteos existentes; F-02 editor de roles / F-03 / F-06 del propietario (ver registro) |

| Campo (entrega anterior) | Valor |
|---|---|
| Base / rama | `0139e95` (F-01 congelado) / `fix/sec-01-account-path-boundaries` |
| Fix (R2-REV-01) | concesión de roles autorizada ANTES de sincronizar (`PrivilegedRoleGuard` en `saveRelationshipsUsing`); apertura de cuenta privilegiada bloqueada en `authorizeAccess`; `/form` removido (F-04); método redundante quitado (F-05 no reproducido) |
| Pruebas | antes (`3e311f8`, enfoque viejo): la prueba de frontera de persistencia falla (el rol se escribía y se retiraba); después (`e7ba59d`): enfocadas 22 OK; suite completa `6bf8582` **606 OK** (7.016) |
| Traversal (R2-REV-02) | canario fuera de raíz; `{empresa}` todo 404 con capa registrada, `secciones` perfil completo con texto de PDF decodificado; sin hallazgos |
| Matriz (R2-REV-03) | oráculo de negocio allow/deny en entradas de alto valor; 5xx inesperado falla salvo JoinViews |
| Registro | `docs/security/SECURITY_STATUS.md` (R2-REV-04) |
| F-01 | **sigue SIN desplegar** (`87dd997`); riesgo vivo persiste |
| Decisiones | F-02 editor de roles / F-03 / F-06 del propietario; lote de relation-managers/búsqueda pendiente |
| Informe | `audit/security_sec01_r2_2026-10-21.md` |

## CPV-SEC-01 R1 — F-01 aceptado (`6293112309` / `6293203444`), sin desplegar

| Campo | Valor |
|---|---|
| Base | `4a56faf` (runtime `87dd997` + docs del plan) |
| Ramas | `audit/security-access-01` (pruebas, informe, checkpoint) ⊃ `fix/sec-01-impersonation-gate` (`613c359`: solo un super admin puede suplantar) |
| Pruebas | antes del arreglo (`ab0464b`): 1 falla esperada; después (`7d8531e`): suite completa **593 OK**; enfocadas `4819877`: 25 OK |
| Cobertura | 131 rutas, 30 recursos, 29 páginas, 151 componentes; matriz de 9 actores × 88 entradas (792 pedidos, 0 violaciones) |
| Registro | F-01 corregido; F-02/F-03 (datos de roles) y F-06 son decisiones del propietario; F-04/F-05 para la próxima ronda (`audit/security_sec01_2026-10-20.md`) |
| Restringido | evidencia cruda y mecanismos fuera de git, en el almacenamiento local del ejecutor; entrega a pedido por canal privado |

Gerencia (`planning/management-taxonomy`) en espera hasta aceptar la seguridad; no se promueve esa rama.

## CPV-PDF-OPERATION-SECTION — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6288459314`)

| Despliegue | Valor |
|---|---|
| Desplegado | `87dd997` por push normal (fast-forward desde `514fa6e`); workflow `39405700502` success |
| Runtime | checkout `87dd997`, imagen `f91e97322189`, mantenimiento apagado, `app.debug=false`, `mail.default=log`, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-opsection-514fa6e` (`7a557f27edff`) → `latest`, `git reset --hard 514fa6e`, `docker compose up -d app` (no hizo falta) |
| Smokes | perfil 13/13 (11 secciones), descargas 25/25, 0010C 18/18, selector y manual OK; HTTP público OK (`audit/runs/pdf_operation_section/deploy/`) |

**UAT del propietario (solo su empresa):**
1. **Empresas** → **Elegir secciones** → **Solo las secciones que elija**: 11 secciones marcadas, cada una con su descripción (Recursos, Presencia y Gestión nombran sus bloques).
2. Deje solo **Datos generales y contactos** → **Descargar PDF**: trae datos generales y contactos, **sin** «Operación en Venezuela».
3. Deje solo **Operación en Venezuela**: trae solo facturación, empleados (rango y total) y estatus, sin datos generales ni contactos; el encabezado sigue con nombre y RIF.
4. Marque las dos: cada bloque una vez. **Perfil completo (PDF)**: todo, con Operación después de Contactos.
5. Manual → **Descargar el perfil**: la tabla de las 11 secciones coincide con el selector.

### Revisión (`6286644396`, aceptada en `6288445203`)

| Campo | Valor |
|---|---|
| Rama / BASE | `fix/pdf-operation-section` / `9253968` (runtime idéntico a `514fa6e`) |
| Cambio | `operacion` es una sección propia, tras `generales` (11 en total); `SECTION_DESCRIPTIONS` en el selector y en el manual; límite de texto derivado de las claves |
| TESTED_HEAD | `3ffa85d`: enfocadas **35 OK** (2.615 aserciones); las 11 opciones solas, ambas, las 11 y por defecto (`audit/runs/pdf_operation_section/`) |
| Informe | `audit/pdf_operation_section_2026-10-20.md` |

## CPV-COMPLETE-COMPANY-PDF — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6286221372`)

| Despliegue | Valor |
|---|---|
| Desplegado | `514fa6e` por push normal (fast-forward desde `ce70b7f`); workflow `39390032508` success |
| Runtime | checkout `514fa6e`, imagen `7a557f27edff`, mantenimiento apagado, `app.debug=false`, `mail.default=log`, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-dossier-ce70b7f` (`aa44b1884b93`) + `git reset --hard ce70b7f` (no hizo falta) |
| Smokes | perfil completo 13/13, descargas 25/25, 0010C 18/18; HTTP público OK. Evidencia `audit/runs/complete_company_pdf/deploy/` |

**UAT del propietario (solo su empresa):**
1. **Empresas** → en su fila, **Perfil completo (PDF)** → se descarga `perfil-<RIF>.pdf` con todas las secciones.
2. Revise que **cada página** tenga la franja amarilla con logos, el título, el nombre y el RIF, y abajo «Página N de M». Revise que sus secciones (datos, recursos, experiencia, presencia, gestión, categorías CPV) muestren lo registrado, «Marcado no aplica por el afiliado» o «No hay información registrada».
3. **Elegir secciones** (ícono junto al botón) → **Solo las secciones que elija** → deje dos → **Descargar PDF** → `perfil-<RIF>-parcial.pdf`, que lista las secciones incluidas y omitidas.
4. Marque su fila → arriba, **Exportar listado**: es solo la tabla, no el perfil.
5. La ficha de la empresa → **Descargar perfil completo (PDF)**.

## Antes del despliegue (aceptado en `6286080370`)

| Campo | Valor |
|---|---|
| Autorización | `6270558322` (propietario, 2026-10-19 15:11Z): perfil completo visible y por defecto, secciones opcionales; **no** despliegue |
| Rama / BASE | `fix/complete-company-pdf` / `37503c8` (checkpoint sobre el runtime desplegado `ce70b7f`) |
| Causa | la columna «PDF» era `IconColumn::make('')`: estado null, **ningún ícono visible**. Lo único visible era el exportador de la tabla (listado). Además, `->spa()` convertía el enlace directo en `wire:navigate` y no descargaba |
| Arreglo | acciones de fila «Perfil completo (PDF)» y «Elegir secciones» (antes de las columnas), «Descargar perfil completo (PDF)» en la ficha, «Exportar listado» rotulado aparte; `pdf.blade.php` reparado (mismo diseño, sin consultas, «Sí/No», No aplica por sub-tipo, Categorías CPV, contactos, experiencia y presencia leídas de los modelos); `?secciones=` con lista blanca |
| TESTED_HEAD | `88713a70171ca80903fedac74cd4507498ad8718`: suite completa **582 OK**, enfocadas 109 OK, navegador 16/16 |
| Informe | `audit/complete_company_pdf_2026-10-19.md`; evidencia `audit/runs/complete_company_pdf/` (PDF sintéticos, texto, páginas PNG) |
| PDF-01 (`6272035810`) | encabezado por página. La evidencia publicada en `034cf11` tenía el encabezado en las 7 páginas (bytes, texto por página, logos, píxeles): no se reprodujo. Defecto real hallado: nombre de 100 caracteres → la franja crecía hasta ~104 pt; y una descripción más larga que una página dejaba la página 1 casi en blanco. Ambos corregidos en `pdf.blade.php`. Prueba nueva por página. TESTED_HEAD `0392a14`, enfocadas 34 OK (1.288 aserciones). Evidencia `audit/runs/complete_company_pdf/pdf01/` |
| Siguiente acción | esperar la instrucción de despliegue del propietario; no tocar el candidato. La observación original de PDF-01 fue retirada por el revisor (no es un defecto) |

## CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6265502896`)

| Campo | Valor |
|---|---|
| Promoción | el **propietario** hizo el fast-forward de `feature/upgrade-filament-v3` a `ce70b7f` |
| Por qué no el agente | su push fue **rechazado por el clasificador de permisos** (`Production Deploy`). No hubo pérdida de acceso y no se rodeó (sin SSH, dispatch ni otra vía); credenciales y permisos sin cambios |
| Workflow | run `39249485948`, `success`, head `ce70b7f`, 12:06Z |
| Estado vivo (~12:20Z, solo lectura) | checkout `ce70b7f` limpio; `app` (imagen `aa44b1884b93`) y `nginx` arriba; mantenimiento apagado; sin migraciones pendientes; `app.debug=false`; `mail.default=log` |
| Smoke | `docs/deploy/smoke_export_access_readonly.php` **25/25**: rutas, middleware del panel + `EnsureEmailIsVerified`, `EmpresaPolicy::export`, autoload de modelos, invitado → login, `/pdf` y `/xls` solo redirigen, URL desconocida → 404 «Página no encontrada», sin verificar → pantalla de verificación 200, verificado sin acceso → mismo 404 «Archivo no disponible» (HTML y JSON), 0 consultas a vistas de reporte, 0 correos. Usuarios en memoria, ids falsos (`999999999`, `abc`), transacción READ ONLY. `smoke_task_0010c_readonly.php` **18/18** |
| HTTP público | `/admin/login` 200; `/no-existe` 404; `/pdf/…` y `/xls/…` → 302 a la ruta protegida → 302 al login |
| Recuperación | `perfilafiliados-app:pre-exportaccess-0f8bdad` (imagen de `0f8bdad`) + `git reset --hard 0f8bdad` |
| CDN | reglas de Cloudflare **no** inspeccionadas; las cabeceras nuevas no purgan copias viejas. Revisar y, si corresponde, purgar `/pdf/*` y `/xls/*` es acción del propietario |

**Guía de UAT del propietario:**
1. Entrar al panel, ir a Empresas y usar «PDF» de **su** empresa: abre `perfil-<RIF>.pdf`.
2. En una ventana privada, abrir `/admin/descargas/empresas/abc/pdf`: lleva al login. Ya con sesión, la misma dirección muestra «Archivo no disponible»; `/no-existe` muestra «Página no encontrada».
3. Una cuenta sin verificar (solo una de prueba designada) ve «Verifique su correo electrónico».
4. «No aplica» y el primer guardado: solo sobre datos de prueba designados, nunca sobre registros de clientes.
5. **Sin correo:** un afiliado nuevo no puede verificarse solo hasta activar el correo, en una ronda aparte.

## Bloqueo previo en la misma tarea (resuelto por el propietario)

| Campo | Valor |
|---|---|
| Autorización | `6265502896` (propietario, 2026-10-19 10:51Z): desplegar `ce70b7f` a staging (aceptado en `6265458863`) |
| Verificado antes | staging `0f8bdad`, checkout limpio, contenedores arriba, **sin migraciones pendientes**, sin workflow en curso (último run `38873194879` success); `ce70b7f` es fast-forward de `0f8bdad`; el diff no toca `database/` |
| Recuperación | `perfilafiliados-app:pre-exportaccess-0f8bdad` (= imagen en ejecución `37e023fd6830`), creada antes de promover |
| Bloqueo | el clasificador de permisos rechazó el push a `feature/upgrade-filament-v3` (`Production Deploy`). No se rodeó; informado en `6266745548` |
| Comando del propietario | `git push origin ce70b7f3345f5d196e2e0190356ed8a6e2e091b4:refs/heads/feature/upgrade-filament-v3` (fast-forward, sin `--force`); ejecutado por el propietario |

## CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES — aceptado (`6265458863`), sin desplegar

| Campo | Valor |
|---|---|
| Autorización | `6264837159` (propietario, 2026-10-19 10:12Z): reparación + 404 útil + comprobar la pantalla de verificación; **no** despliegue |
| Rama / BASE | `fix/export-access` / `dc7972821ad9d826a7d3922f67c63aea1019bec6` (revisión sobre el runtime aceptado `edf20ed`) |
| Runtime observado | staging `0f8bdad`, todavía con las rutas abiertas; `app.debug=false`; detrás de Cloudflare |
| TESTED_HEAD | `8e01cacf0a9be978244bd32daf08f7355a70caaf` |
| Reglas | invitado → login; sin verificar → verificación; afiliado con `view_empresa` y vinculado → su empresa; lo demás → el mismo 404. No hay excepción para administradores ni `export_any_empresa` |
| Pruebas | `ExportAccessTest` (8) + enfocadas 147 OK + suite completa 576 OK + navegador 42/42, todo en `8e01cac` |
| Informe | `audit/export_access_repair_2026-10-19.md`; evidencia `audit/runs/export_access_repair/` |
| Manual | `#descargar-perfil` (nuevo), errores frecuentes; nota `docs/deploy/DESCARGAS_PERFIL.md` |
| Siguiente acción | revisión manual; desplegar requiere autorización. Después de desplegar, el propietario evalúa la purga de Cloudflare |

## Pendientes fuera de esta ronda

- correo/SMTP: ~~sin envío, un afiliado nuevo no puede verificarse solo~~ → `smtp` en staging desde el 2026-10-26 y correos recibidos según el propietario (`6802438608`); falta evidencia formal de cada flujo;
- `JoinViews`: su vista falla con empresas incompletas, y su acción Pdf no existe;
- datos históricos de `assets` (solo propuesta);
- el backlog de `SESSION_RESUME.md` §5.

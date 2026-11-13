# TASK-0025 — SEC-01: corrección candidata de F-SEC01-T24-02 (vistas de `public` con derechos del propietario)

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6850596236`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6850596236) (2026-11-13 10:43Z): veredicto de TASK-0024 y asignación prioritaria. Veredicto previo [`6850519341`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6850519341) |
| ID | TASK-0025: siguiente libre verificado (`tasks/`, `current_task.md`, ramas remotas, `git log --all`, handoff). No reutiliza TASK-0024 |
| Estado | ~~IN_PROGRESS~~ (2026-11-13 ~10:55Z) → **READY_FOR_REVIEW** (~11:25Z) |
| Autoriza | Análisis, código y migración versionada, y pruebas en entornos desechables |
| No autoriza | Aplicar DDL a la BD compartida o a staging, desplegar, cambiar la Data API, RLS, grants reales, credenciales o n8n, producción, `main`, *force-push*, HSTS, Cloudflare, nginx, túnel, taxonomía, roles reales ni las filas huérfanas. Usar claves reales o leer datos reales por la API requiere gate |
| Rama | Código y pruebas aislados en una rama propia desde staging `270e07d`; la rama de runtime (`feature/upgrade-filament-v3`) y `main` no se tocan. Registros en `fix/sec-01-r4-01-role-editor-preserve` |
| En espera | R-06 (R3-02/R3-03) hasta la revisión de esta prioridad. F-SEC01-T24-01 abierta y contenida: no conceder `delete_user`/`delete_any_user` fuera de `super_admin` |

## Criterios (del comentario fuente)

1. Inventario reproducible de las 13 vistas: definición, propietario, `reloptions`, `security_invoker`, `security_barrier`,
   privilegios efectivos de `anon`, `authenticated` y `service_role`, tablas y columnas subyacentes, dependencias, PII y
   alcance real por REST/GraphQL. Sin publicar filas, identificadores personales, claves, URLs sensibles ni secretos.
2. Permisos de tablas base, RLS y políticas, y consumidores (Laravel, PostgREST, frontend, n8n y otros); configuración de
   esquemas expuestos leída sin modificarla.
3. Explotación solo en un clon aislado con credenciales sintéticas: fails-before de la lectura indebida y controles positivos.
4. Mitigación mínima por vista (preferir `security_invoker`; considerar `REVOKE` o mover la vista), sin conceder permisos
   adicionales a tablas base.
5. Migración idempotente, reversible, acotada y versionada, con pruebas de los 13 objetos, de la falta de acceso indebido, de
   las consultas legítimas, del rollback y de la compatibilidad, en un clon desechable con esquema saneado.
6. Fails-before, seguridad, regresión y suite completa en aislamiento. Si una integración depende del comportamiento inseguro:
   alternativas y BLOCKED.
7. Matriz por vista, exposición demostrada frente a potencial, TESTED_HEAD, REVIEW_HEAD, evidencia y procedimiento de
   despliegue (respaldo, rollback, verificación, conteos y monitorización).
8. READY_FOR_REVIEW o BLOCKED y STOP.

## Resultado (2026-11-13)

Detalle, matriz de las 13 vistas y procedimiento de despliegue:
[`docs/security/SEC01_TASK0025_VISTAS_PUBLIC.md`](../../security/SEC01_TASK0025_VISTAS_PUBLIC.md).

| Campo | Valor |
|---|---|
| BASE | `270e07dcda8c61840c569b5c85cbad471ce9bf82` (staging; verificado en servidor y remoto, árbol limpio) |
| Fails-before | `56375f5f21edc1fdeed405278545f8a678d459a0` (prueba + fixture): run `20261113T105506Z-56375f5f21ed`, **5/5 en rojo** por la razón correcta |
| **TESTED_HEAD = REVIEW_HEAD** | `6e7f52546a29f223fa924c0c897592223c55360c` (`fix/sec-01-t25-public-views-hardening`; *fast-forward* de `270e07d`; 3 archivos: migración, prueba y fixture) |
| Pruebas del candidato | run `20261113T105643Z-6e7f52546a29`: **OK (5 tests, 48 assertions)** |
| Suite completa | run `20261113T110734Z-6e7f52546a29`: **OK (803 tests, 8562 assertions)**. La primera pasada (`20261113T105754Z`) tuvo 1 error ajeno: `CompanyDossierPdfTest` usa un código aleatorio `CPV-9xx` que chocó con el fixture |
| Clon REST aislado | `/root/backups/sec01-t25-rest-clone-20261113T111025Z`: antes, `anon` lee por REST filas sintéticas de 6 vistas (incluido el contacto); después, 401/403 en las 13; `service_role` y la huella del propietario sin cambios; `down()` restaura exactamente |
| BD compartida / staging | **sin cambios**: solo lecturas de catálogo en transacción `READ ONLY` |
| Consumidores | solo Laravel (como propietario); n8n no usa estas vistas en Supabase (lectura `GET` de 49 flujos) |

**Gate:** el despliegue normal ejecuta `php artisan migrate --force`, así que integrar `6e7f525` en la rama de runtime aplica
el DDL a la BD compartida. Requiere autorización explícita.

**Incidencias de ejecución** (scripts del ejecutor, sin efecto en staging): la extracción del SQL de la migración con una
expresión regular en línea falló por el escapado y se rehízo con un script; en el script del clon, una sustitución automática
perdió la cabecera `Authorization` y se corrigió antes de ejecutarlo.

**STOP:** R-06 en espera; F-SEC01-T24-01 abierta y contenida; R-04 con su propio gate.

## Anexo: texto íntegro de la fuente

<details><summary><code>6850596236</code></summary>

# VEREDICTO TASK-0024 + NUEVA ASIGNACIÓN PRIORITARIA — F-SEC01-T24-02 (vistas Supabase)

**Decisión expresa del propietario, 2026-11-13:** priorizar inmediatamente la verificación y corrección de vistas Supabase sobre F-SEC01-T24-01; el permiso `delete_user` se concede actualmente solo a roles super_admin. Fuentes: entrega TASK-0024 [6850303475](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6850303475), diagnóstico `docs/security/SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md`, veredicto ya publicado [6850519341](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6850519341).

## A. Veredicto
**TASK-0024 APPROVED, exclusivamente diagnóstico y planificación** (se ratifica `6850519341`). R-01/R-02/R-03 cerradas; R-04 remediación pendiente de gate; R-05 verificada con hallazgos nuevos; R-06 sigue abierta. Staging runtime `270e07dcda8c61840c569b5c85cbad471ce9bf82` sin cambios según entrega. HEAD documental `0c8031fbc8a75296609280c4c833249a291fd572` modifica solo handoff. No reabrir TASK-0024 ni alterar su propósito. Registrar este veredicto en checkpoints versionados.

**F-SEC01-T24-01** queda abierta, latente y **no prioritaria** dado que `delete_user` no está concedido a roles no privilegiados según evidencia; no conceder `delete_user` ni `delete_any_user` fuera de super_admin mientras siga abierta. No declarar corregido.

## B. Asignación nueva y específica — T24-02
**Objetivo:** verificar exposición efectiva y preparar **corrección segura de las 13 vistas `public`** con derechos de propietario y SELECT para `anon`, incluida la vista con datos de contactos, sin romper consultas legítimas ni integraciones. El propietario solicita corregirlas; esta asignación autoriza **análisis, código/migración versionada y pruebas en entorno desechable**, **NO aplicar DDL a BD compartida/staging, ni desplegar** sin gate posterior explícito.

**Materialización:** seguir `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md` y solo TASK-0024 + referencias directas. Comprobar rama, HEAD, worktree, remotos y cambios preexistentes antes de editar; verificar el siguiente ID consecutivo libre real y materializar **una tarea nueva** con el alcance de este comentario, sin reutilizar TASK-0024. Aislar trabajo de código y pruebas de la rama de runtime; no cambiar `feature/upgrade-filament-v3` ni `main`.

### Fase 1 — Evidencia y análisis de impacto (lecturas seguras)
1. Obtener inventario reproducible de las 13 vistas: esquema, nombre, owner, definición, `reloptions`, `security_invoker`, `security_barrier`, privilegios efectivos `has_table_privilege` para `anon`, `authenticated`, `service_role`, tablas/columnas subyacentes, dependencias y vistas encadenadas; confirmar qué vistas contienen PII y cuáles son realmente alcanzables por REST/GraphQL. **No publicar filas, identificadores personales, claves, URLs sensibles ni secretos.**
2. Auditar permisos efectivos de tablas subyacentes, RLS y políticas, rutas usadas por Laravel, PostgREST, frontend, n8n y cualquier cliente autorizado; detectar dependencia de vistas como `postgres`/service_role/anon. Leer configuración de esquemas expuestos sin modificarla. El hecho de que `anon` pueda consultar una vista no prueba por sí solo una fuga explotable; la clave anon/publishable puede ser pública legítimamente, pero debe aplicarse mínimo privilegio.
3. Verificación de explotación **solo en clon aislado con credenciales sintéticas**; si se requiere usar clave real o consultar datos reales por API, detenerse y pedir gate, sin extraer PII. Producir prueba fails-before de lectura indebida y controles positivos de lectura autorizada.

### Fase 2 — Corrección candidata aislada
4. Elegir por vista la mitigación mínima: preferir `security_invoker=true` en PostgreSQL 15+ cuando las políticas RLS de tablas base y permisos subyacentes sean correctos; considerar `REVOKE SELECT` de `anon`/`authenticated` o mover la vista fuera de esquemas expuestos cuando corresponda. No aplicar `security_invoker` ciegamente a todas: analizar vistas anidadas, grants, invocador, vistas usadas por aplicación y compatibilidad. Evitar conceder permisos adicionales a tablas base para hacer pasar las pruebas.
5. Crear migración **idempotente, reversible, acotada y versionada**, y pruebas automatizadas que validen los 13 objetos, ausencia de acceso indebido como `anon`/`authenticated`, preservación de consultas legítimas de aplicación y servicios, rollback y comportamiento en PostgreSQL/Supabase compatibles. Probar migración + rollback en clon desechable construido a partir de esquema saneado; nunca alterar la BD compartida.
6. Ejecutar fails-before, pruebas de seguridad, regresión funcional y suite completa en aislamiento. Registrar resultados exactos y diff mínimo. Si alguna vista requiere tratamiento diferente o una integración depende del comportamiento inseguro, documentar alternativas y **BLOCKED** antes de cambios vivos.

### Fase 3 — Entrega y gate
7. Entregar matriz por cada una de las 13 vistas (nombre, datos, grants, consumidor, riesgo, cambio propuesto, prueba antes/después), informe de exposición demostrada vs potencial, TESTED_HEAD, REVIEW_HEAD, evidencia y procedimiento de despliegue con backup, rollback, verificación post-cambio, conteos y monitorización. Actualizar `docs/security/SECURITY_STATUS.md`, tarea, checkpoints y `audit/orchestrator_handoff.json` conservando antecedentes.
8. Publicar **READY_FOR_REVIEW** (o **BLOCKED**) en Issue #2 y **STOP**. La aplicación de migración en staging/BD compartida, cualquier cambio de Data API, RLS, grants reales, credenciales, n8n, producción o `main` requiere autorización posterior **explícita y separada**. Sin force-push, sin HSTS/Cloudflare/nginx/túnel, sin cambios de taxonomía, roles reales o filas huérfanas.

**Orden posterior:** revisar y aprobar el candidato T24-02; solo después decidir gate de ejecución. R-06 (R3-02/R3-03) mantiene su autorización anterior para candidato independiente, pero queda en espera por esta prioridad. F-SEC01-T24-01 permanece abierta y contenida por asignación de permisos actual; R-04 pendiente de gate propio. **No iniciar tareas adicionales por iniciativa propia.**

</details>

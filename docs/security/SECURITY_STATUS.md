# Informe acumulativo de seguridad — Perfil de Afiliados CPV

**Fecha de actualización:** 2026-11-13 (SEC-01 / TASK-0025 `6850596236`: corrección candidata de F-SEC01-T24-02 en aislamiento, sin aplicar, READY_FOR_REVIEW; TASK-0024 APPROVED `6850519341`; antes, SEC-01 / TASK-0024 `6848913937` + decisiones `6849259532`: diagnóstico de las reservas de TASK-0023, READY_FOR_REVIEW; hallazgos nuevos F-SEC01-T24-01 y F-SEC01-T24-02; TASK-0023 APPROVED con reservas `6848907151`; antes, 2026-11-12: SEC-01 / TASK-0023 `6831152066`: corrección de F-SEC01-T21-01 desplegada en staging `270e07d`, READY_FOR_REVIEW; TASK-0022 APPROVED `6831144754`; antes, SEC-01 / TASK-0022 `6829151868`: corrección de F-SEC01-T21-01 sin desplegar y evaluación de la recuperación de permisos, READY_FOR_REVIEW; TASK-0021 APPROVED con reservas `6829143372`; antes, SEC-01 / TASK-0021 `6823247114`: R3+R4+R4-01 desplegados en staging `71e8a6d`, READY_FOR_REVIEW; antes, SEC-03 / TASK-0020 APPROVED con reservas `6822634878`; pruebas operativas en staging; antes, TASK-0019 APPROVED `6821212610`; despliegue en staging `6820224859`; antes, el mismo día, TASK-0018 ciclo de corrección `6819392897` y APPROVED `6820214362`; antes, 2026-11-11: TASK-0018 y TASK-0017; la anterior fue 2026-10-21) (Europe/Madrid).  
**Estado:** revisión en curso; no constituye certificación de seguridad.  
**Programa:** CPV-SECURITY-AND-RELIABILITY.  
**Rama de mantenimiento inicial:** `audit/security-access-01`.  
**Plan:** [SECURITY_REVIEW_PLAN.md](../plans/SECURITY_REVIEW_PLAN.md).

Este registro resume cada ronda, sus hallazgos, correcciones, aceptación y despliegue. Los informes de ronda conservan la evidencia original; este documento no los sustituye ni debe borrar sus conclusiones anteriores.

## 1. Resumen ejecutivo

- Las descargas de perfil de empresa recibieron controles de sesión, verificación, autorización por empresa y respuestas privadas en una ronda previa al programa SEC.
- SEC-01 ronda 1 identificó una vulnerabilidad de privilegios y entregó una reparación aceptada para preparar su despliegue. **Aceptada no significa desplegada.**
- **2026-10-21:** las reparaciones de R1 (F-SEC01-01) y R2 (F-SEC01-02a, F-SEC01-04, atomicidad R2-REV-05) están **desplegadas en staging (`cf01e9c`) y verificadas con comprobaciones de solo lectura**. Esto no cierra SEC-01 ni el programa: siguen abiertos F-02 (editor de roles/configuración), F-03, F-06, la cobertura de relation managers/búsqueda y SEC-02..05.
- **2026-10-21, UAT del propietario: PASS** para esa entrega. **R3** (relaciones de empresa) corrige en candidato la re-autorización después de montar (F-SEC01-R3-01), sin desplegar. **Alerta: F-SEC01-R3-04**: un camino de privilegios que queda abierto se salta las guardas desplegadas; la mitigación inmediata es una acción del propietario sobre los datos de roles.
- **2026-10-21, R3 aceptado (NO desplegado). R4:** el propietario fijó que administrar roles es **exclusivo de super_admin**. La contención en datos vivos quedó **bloqueada** por el clasificador del ejecutor (sin cambios; **el riesgo vivo sigue**). La reparación de código está lista para revisión, sin desplegar.
- **2026-10-21, R4 código aceptado en 6319407886 (candidato `97737ba`, NO desplegado). Contención manual: confirmada por el propietario (6319443716) y VERIFICADA en solo lectura:** 0 permisos de administración de roles fuera de super_admin, continuidad de super_admin PASS y caché coherente. **Efecto colateral (F-SEC01-R4-01):** al guardar, el editor de roles quitó además permisos que no muestra; requiere decisión del propietario.
- **2026-10-21, F-SEC01-R4-01 (6320241495):** corrección del editor lista para revisión (TESTED `24413d5`, sin desplegar) y propuesta de recuperación de permisos **solo propuesta**: no se cambió ningún dato vivo.
- **2026-11-11, SEC-03 / TASK-0017 (6797800863), auditoría de recuperación de cuentas, tokens y Host/proxies sobre staging
  `b048df0`. NO se confirmó toma de cuentas:** la vinculación del token se mantiene en todo lo probado. Confirmados
  F-SEC03-01..06 (host de enlaces tomado de la petición, mitigado por el túnel; IP de cliente colapsada en el gateway;
  cupos consumidos sin Turnstile; verificación heredada al cambiar el correo; HTTP sin redirección ni HSTS). Sin
  correcciones ni despliegue: la propuesta espera autorización.
- **2026-11-11, decisiones del propietario (`6802438608`), solo registro.** Autoriza en alcance:
  - desarrollar P1–P5 de SEC-03 con pruebas aisladas, sin despliegue automático. P1 protege hosts y URL antes de confiar
    en cabeceras para P2;
  - la revisión final, integración y despliegue **en staging** de R3+R4+R4-01, con respaldo previo, regresión y
    verificación de preservación de permisos. No autoriza producción ni `main`.

  Nada de esto se ha ejecutado: cada parte requiere materializar su tarea. Los estados de §4 no cambian. Hasta verificar
  R4-01 en staging, no guardar roles en el editor.
- **2026-11-11, SEC-03 / TASK-0018 (`6802857575`), correcciones P1–P5 en candidato (NO desplegadas).** F-SEC03-01..06
  corregidos en código en la rama `fix/sec-03-p1-p5` (TESTED/REVIEW `cb3fff4`): enlaces con la raíz de `APP_URL` y
  `TrustHosts`; IP real solo desde pares de confianza, sin `X-Forwarded-Host`; Turnstile antes de los cupos; correo
  nuevo sin verificar; HSTS de 1 día y redirección a https apagada. Suite completa 753/753. La aplicación efectiva
  requiere despliegue autorizado y verificaciones de infraestructura (ver §5).
- **2026-11-12, TASK-0018, ciclo de corrección (`6819392897`): P2, P4 y P5 PASS en código, sin desplegar.** Dos defectos
  demostrados y corregidos: la confianza por defecto en `172.16.0.0/12` incluía las redes de otros sitios del mismo
  host (ahora, el gateway exacto); y el borrado de tokens después del `UPDATE` podía dejar tokens del correo anterior
  vivos (ahora, antes). P5 sin defecto de código. TESTED/REVIEW `9d0c24d`; suite completa 767/767. F-SEC03-03 y
  F-SEC03-06 siguen pendientes de validación efectiva; ningún hallazgo se cierra por pruebas aisladas.
- **2026-11-12, TASK-0018 APPROVED (`6820214362`)** como implementación aislada (`9d0c24d`).
- **2026-11-12, SEC-03 / TASK-0019 (`6820224859`): P1–P5 DESPLEGADOS EN STAGING** (`9d0c24d`, workflow `43196418453`), con
  respaldo y restauración ensayada. F-SEC03-03 **verificado en staging**: `CF-Connecting-IP` llega hasta PHP y la IP
  efectiva es la del cliente. F-SEC03-06 **verificado en staging**: «Always Use HTTPS» activo y HSTS `max-age=86400`
  solo en el hostname de staging. Producción sin autorización; SEC-03 no se cierra globalmente.
- **2026-11-12, TASK-0019 APPROVED (`6821212610`), limitada a staging.** Los estados de F-SEC03-01..06 en §4 no cambian.
  Siguen abiertas, y cada una requiere asignación propia:
  - las pruebas funcionales de correo/RIF, Turnstile y cambio de correo con buzones sintéticos;
  - la prueba adversarial del borde y de los cupos;
  - cualquier aumento de HSTS.

  También sigue abierto el riesgo de restauración por la FK preexistente.
- **2026-11-12, SEC-03 / TASK-0020 (instrucción del propietario en el chat): pruebas operativas en staging, PASS.**
  - **Adversariales:** el borde bloquea Hosts ajenos y un `CF-Connecting-IP` enviado por el cliente; la app ignora
    `X-Forwarded-Host`, `X-Forwarded-For` y `True-Client-IP` falsos; los cupos por origen quedan separados entre clientes.
  - **Funcionales:** con cuentas sintéticas, el correo real trae el host de staging; el RIF manda un mensaje por buzón; un
    Turnstile fallido no consume el cupo por correo; el cambio de correo anula la verificación y los tokens anteriores.
  - **No ejecutado:** abrir el enlace de verificación del buzón nuevo.
  - **Datos:** los sintéticos se borraron y los conteos volvieron a la línea base.
- **2026-11-12, TASK-0020 APPROVED con reservas (`6822634878`).** Reservas:
  - verificación del buzón nuevo no ejecutada en staging (no cuenta como UAT);
  - origen de la tarea: el chat, sin asignación previa en el Issue #2;
  - deuda de UX en la pantalla de verificación (no es vulnerabilidad);
  - vinculación automática de 4 usuarios del personal en `Empresa::create`, para revisión independiente;
  - FK preexistente con filas huérfanas.

  No autoriza cerrar todos los riesgos del proyecto ni pasar a producción.
- Quedan decisiones sobre permisos y asociaciones de usuarios, hipótesis por reproducir y superficies todavía no probadas.
- La siguiente ronda asignada verifica creación/edición de cuentas, concesión de privilegios y límites de acceso a archivos.
- Gerencia y nuevos reportes permanecen en espera del cierre acordado de seguridad.
- **2026-11-12, SEC-01 / TASK-0021 (`6823247114`): R3+R4+R4-01 integrados y desplegados SOLO en staging (`71e8a6d`,
  workflow `43220318614`), READY_FOR_REVIEW.** Revisión de R4-01 PASS, con prueba a la escala histórica y fails-before. El
  candidato tiene solo los 12 commits de código y pruebas sobre `9d0c24d`, y SEC-03 queda intacta. Respaldo y restauración
  ensayada (FK preexistente con `NOT VALID`). Suite completa 794/794. En vivo, con datos sintéticos en una transacción revertida:
  el editor anterior quitaba 60 de 60 ocultos y el nuevo conserva 60 de 60; administran roles 4/4 `super_admin` y 0/364 otros.
  Staging ya tenía R3 a medias desde `7831e01` (1 de 11 componentes); ahora 11/11. Residual: **F-SEC01-T21-01** (Baja,
  §4). SEC-01 sigue abierto; producción y `main`, sin autorización.
- **2026-11-12, TASK-0021 APPROVED con reservas (`6829143372`):** corregir F-SEC01-T21-01 en una tarea nueva; recuperar
  los permisos de octubre solo si coste y riesgo son bajos, sin restauración ciega.
- **2026-11-12, SEC-01 / TASK-0022 (`6829151868`): F-SEC01-T21-01 corregido en candidato, SIN DESPLEGAR; recuperación solo
  evaluada.** La sección «Role» muestra los 6 `*_role` reales en solo lectura (clave de entidad `role`), así que el editor no
  puede darlos a otro rol ni quitárselos a `super_admin`. Fails-before `23b5c0d`; suite 798/798 en `4bdec36`. Recuperación:
  los 128 vínculos perdidos siguen faltando (109 ocultos por el defecto y 19 visibles); recomendación: como máximo 1.
  Ninguna escritura real.
- **2026-11-12, TASK-0022 APPROVED (`6831144754`)** como entrega de código y análisis. Recuperación: sin ejecución
  automatizada; R1 y R2 no se modifican; `view_any_user` en R1 queda a decisión manual del propietario.
- **2026-11-12, SEC-01 / TASK-0023 (`6831152066`): F-SEC01-T21-01 DESPLEGADO EN STAGING (`270e07d`, workflow `43274064073`),
  READY_FOR_REVIEW.**
  - Push *fast-forward* desde `71e8a6d`.
  - Preflight: respaldo y restauración aislada (FK con `NOT VALID`); suite 798/798.
  - En vivo, con datos sintéticos en una transacción revertida: la sección «Role» pasa de 6 `*_shield::role` a los 6 `*_role`
    reales en solo lectura; ocultos conservados; sin concesión ni forjados.
  - Roles reales idénticos a la línea base. SEC-03 intacta (HSTS 86400, 301, Host ajeno 400).
- **2026-11-13, TASK-0023 APPROVED con reservas (`6848907151`)**, en su alcance de staging; SEC-01 sigue abierta.
- **2026-11-13, SEC-01 / TASK-0024 (`6848913937`, decisiones `6849259532`): diagnóstico de reservas, READY_FOR_REVIEW, sin
  escrituras en staging.**
  - R-01, R-02 y R-03 cerradas por decisión o aceptación del propietario.
  - R-04: remediación de las 2 huérfanas demostrada en copia aislada (restauración limpia, rollback exacto), pendiente de gate.
  - R-05: el límite de R4 es interno (0 escritores sin guarda). Hallazgos nuevos **F-SEC01-T24-01** (latente) y
    **F-SEC01-T24-02** (no reproducido).
  - R-06: R3-02/R3-03, en tarea independiente posterior.
  - Detalle en [`SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md`](SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md).
- **2026-11-13, TASK-0024 APPROVED (`6850519341`, ratificado en `6850596236`)**, solo como diagnóstico y planificación.
- **2026-11-13, SEC-01 / TASK-0025 (`6850596236`): corrección candidata de F-SEC01-T24-02, READY_FOR_REVIEW, sin aplicar ni
  desplegar.**
  - Inventario de las 13 vistas: solo Laravel las consume, como propietario.
  - Exposición demostrada en un clon REST aislado (PostgREST, credenciales sintéticas); en vivo, potencial.
  - Migración `security_invoker` + REVOKE a `anon`/`authenticated` en `fix/sec-01-t25-public-views-hardening` @ `6e7f525`;
    fails-before 5/5 en rojo y candidato 5/5 en verde.
  - Aplicarla exige un gate: el despliegue ejecuta `migrate --force` sobre la BD compartida.
  - Detalle en [`SEC01_TASK0025_VISTAS_PUBLIC.md`](SEC01_TASK0025_VISTAS_PUBLIC.md).

## 2. Estado de versiones y evidencia

| Concepto | Referencia | Qué demuestra |
|---|---|---|
| Rama de despliegue observada al actualizar este informe | `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4` | Referencia remota; no es una comprobación independiente del contenedor vivo |
| Última entrega de despliegue conocida | Issue #2, comentario 6288589926; run 39405700502 | Despliegue de la selección de secciones; controles de descarga previos incluidos |
| Candidato de seguridad revisado | `0139e954ec22f0a6bfa2377968a6a3af86c15874` | Reparación F-SEC01-01 aceptada en 6297739082 |
| Despliegue de F-SEC01-01 | ~~NO ACREDITADO / PENDIENTE~~ → **DESPLEGADO Y VERIFICADO** (2026-10-21), en `cf01e9c` | Autorización 6316265832; run 39602544527 success; comprobaciones de solo lectura en §5 «Despliegue SEC-01 R1+R2» |
| Ronda 2 — candidato | `fix/sec-01-account-path-boundaries` (TESTED `f4dd8fb`, enfocadas y suite completa; antes REVIEW_HEAD `51a923e`, suite `6bf8582`, enfocadas `e7ba59d`) | Segunda revisión: CAMBIOS SOLICITADOS (atomicidad, R2-REV-05), corregidos; **aceptada** en 6310331211 (FINAL) y **desplegada** en `cf01e9c` (2026-10-21) |
| Despliegue de R2 (F-SEC01-02a, F-04, R2-REV-05) | ~~NO ACREDITADO / PENDIENTE~~ → **DESPLEGADO Y VERIFICADO** (2026-10-21), en `cf01e9c` | R2 y R2-REV-05 aceptadas en 6310331211 («FINAL R2 REVIEW — ACCEPTED FOR DEPLOYMENT PREPARATION»); verificación limitada a lo listado en §5 |
| Runtime de staging tras el despliegue | `cf01e9c3e30415a34ccda60b312a993c1388167d`, imagen `081c35c5715d`; recuperación `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`, la imagen de `87dd997`) | SSH de solo lectura, 2026-10-21 ~12:52Z |

Cada cambio de estos estados necesita SHA y fuente. No inferir despliegue a partir de un commit en una rama de trabajo.

## 3. Cronología de rondas

| Ronda | Alcance | Corrección / resultado | Revisión y estado |
|---|---|---|---|
| Antecedente: acceso a exportaciones | Descargas por empresa, verificación, permisos, errores y caché | Rutas protegidas, alcance por empresa, identificador validado, XLS limitado y respuestas privadas; rutas antiguas redirigen | Aceptación 6265458863, candidato `ce70b7f`; despliegue comunicado en 6267092324. El residuo de caché previo no quedó acreditado como eliminado |
| SEC-01 R1 | Inventario efectivo, matriz de actores y primeras pruebas de acciones | Una reparación de privilegios; resto de hallazgos registrado | Entrega 6294285848; revisión 6297739082: reparación aceptada, SEC-01 continúa abierto |
| SEC-01 R2 — cuentas y archivos | Inyección de privilegios, creación/edición de usuarios, traversal y expectativas de pruebas | Reparación de concesión de roles (faceta de cuentas de F-02), F-04 retirado, F-05 no reproducido, traversal sin hallazgos. BASE `0139e95`, TESTED/REVIEW ver §5. Suite completa verde | Instrucción 6310273754; **revisión 6310331211 (R2-REV): CAMBIOS SOLICITADOS**, corregidos en la entrega 6311569828; **segunda revisión (misma 6310331211, sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION»): CAMBIOS SOLICITADOS**; pausa por cuota (6313459342); corrección R2-REV-05 entregada después en la misma rama; no aceptada |
| Despliegue SEC-01 R1+R2 (2026-10-21) | F-SEC01-01 + F-SEC01-02a + F-SEC01-04 + R2-REV-05 a staging | `87dd997` → `cf01e9c` (fast-forward, push normal); run 39602544527 success (12:48–12:50Z) | Autorización 6316265832; aceptación 6310331211 (FINAL R2 REVIEW). Desplegado y verificado con comprobaciones de solo lectura; UAT del propietario pendiente. SEC-01 sigue abierto |
| UAT de la entrega desplegada (2026-10-21) | `cf01e9c` | OWNER_UAT: **PASS** (confirmación del propietario, registrada en 6316775771) | Cierra la espera de UAT de esa entrega; SEC-01 sigue abierto |
| SEC-01 R3 — relaciones de empresa, búsquedas, permisos | 7 gestores de relación de Empresa, 3 búsquedas, propuestas F-02/F-03/F-06 | F-SEC01-R3-01 corregido; R3-02/R3-03/R3-04 confirmados en aislamiento y registrados. BASE `eb03b4c`, TESTED `e95169c`, suite 624 OK | Instrucción 6316775771; ~~listo para revisión~~ → **aceptado en 6318588005 (REVIEW_HEAD `66f5ce8`), NO desplegado**. **Alerta al propietario: R3-04** |
| SEC-01 R4 — administración de roles (2026-10-21) | Regla del propietario: administrar roles es exclusivo de super_admin. A) contención en datos vivos; B) reparación de código | A) ~~**BLOQUEADA**: el clasificador de permisos del ejecutor rechazó la escritura; sin cambios en vivo (alerta 6318733204)~~ → **aplicada a mano por el propietario** (confirmación 6319443716) y **verificada en solo lectura** (§5). B) `RolePolicy` + `PrivilegedRoleDefinitionGuard`; BASE `66f5ce8`, TESTED `b51fa3e` | Instrucción 6318625636; ~~código listo para revisión~~ → **código aceptado en 6319407886 (REVIEW_HEAD `97737ba`)**; **sin desplegar** |
| SEC-02 general | Reportes, archivos y caché | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-03 general | Identidad, sesiones y entradas | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-03 / TASK-0017 (2026-11-11) | Recuperación de cuentas (correo y RIF), tokens, Host/proxies, verificación y cambio de correo, sobre staging `b048df0` | Solo auditoría y pruebas: 13 controles descartan la toma de cuentas en el alcance probado; F-SEC03-01..06 confirmados (ver §4). BASE `b048df0`, TESTED `8a4ed35` (rama `audit/sec-03-account-recovery`) | Instrucción 6797800863; **READY_FOR_REVIEW, sin correcciones ni despliegue**. SEC-03 sigue abierto |
| SEC-03 / TASK-0018 (2026-11-11) | Correcciones P1–P5 de F-SEC03-01..06 | Implementación y pruebas aisladas: 7 oráculos de TASK-0017 en verde sin cambiarlos, 13 controles verdes, 17 pruebas nuevas; suite completa 753 tests, 8030 aserciones, OK. BASE `b048df0` (+ pruebas `8a4ed35`), TESTED `cb3fff4`, REVIEW `cb3fff4` (rama `fix/sec-03-p1-p5`) | Instrucción 6802857575; **READY_FOR_REVIEW, corregido en candidato, sin despliegue** ni cambios de infraestructura |
| SEC-03 / TASK-0018, ciclo de corrección (2026-11-12) | Revisión técnica acotada de P2, P4 y P5 | P2: lista exacta de proxies; P4: borrado de tokens antes del `UPDATE`; 14 pruebas nuevas; defectos demostrados con el código anterior (run `20261112T092358Z-a45af63e14a3`: 2 fallos esperados). Suite completa 767 tests, 8102 aserciones, OK. BASE `cb3fff4`, TESTED `9d0c24d`, REVIEW `9d0c24d` | Revisión 6819392897 (CORRECTIONS_REQUIRED) → **READY_FOR_REVIEW**, sin despliegue |
| SEC-03 / TASK-0019 (2026-11-12) | Integración y despliegue de P1–P5 solo en staging; verificación de cabeceras; HSTS gradual | Preflight PASS (respaldo, restauración aislada 88/88 tablas con 0 diferencias, regresión 767/767 sobre el candidato). Despliegue *fast-forward* `b048df0..9d0c24d`, workflow `43196418453` success. Gates A–D PASS; HSTS `max-age=86400` en staging. BASE `b048df0`, TESTED = REVIEW = DESPLEGADO `9d0c24d` | Instrucción 6820224859; READY_FOR_REVIEW; desplegado en staging → **APPROVED** (`6821212610`, limitado a staging); SEC-03 sigue abierto |
| SEC-03 / TASK-0020 (2026-11-12) | Pruebas operativas en staging: adversariales (borde, cabeceras, cupos) y funcionales (correo, RIF, Turnstile, cambio de correo) con cuentas sintéticas | Parte 2 PASS; parte 1 PASS salvo abrir el enlace de verificación del buzón nuevo (no ejecutado). Datos sintéticos creados y borrados por id; línea base restaurada. Staging `9d0c24d`, sin cambios de código ni de configuración | Instrucción del propietario en el chat; READY_FOR_REVIEW → **APPROVED con reservas** (`6822634878`) |
| SEC-01 / TASK-0021 (2026-11-12) | Revisión final de R4-01; integración y despliegue en staging de R3+R4+R4-01 | Gates 1–4 PASS. BASE `9d0c24d`, TESTED/REVIEW/desplegado `71e8a6d`, workflow `43220318614`; respaldo `sec01-t21-20261112T123419Z`; suite 794/794; validación sintética en vivo PASS. Residual F-SEC01-T21-01 | Asignación `6823247114`; READY_FOR_REVIEW → APPROVED con reservas (`6829143372`) |
| SEC-01 / TASK-0022 (2026-11-12) | Corregir F-SEC01-T21-01; evaluar la recuperación de permisos de octubre | BASE `71e8a6d`; TESTED `4bdec36`, REVIEW `270e07d` (`fix/sec-01-t22-role-permission-key`); fails-before `23b5c0d`; enfocadas 28/28; suite 798/798. **Sin desplegar**; 0 escrituras reales | Asignación `6829151868`; READY_FOR_REVIEW → APPROVED (`6831144754`) |
| SEC-01 / TASK-0023 (2026-11-12) | Despliegue en staging de la corrección de F-SEC01-T21-01 | BASE `71e8a6d`; TESTED/REVIEW/desplegado `270e07d`, workflow `43274064073`; respaldo `sec01-t23-20261112T174040Z`; restauración aislada PASS; 93/93 y 798/798; validación sintética en vivo PASS; roles reales idénticos | Asignación `6831152066`; READY_FOR_REVIEW → **APPROVED con reservas** (`6848907151`) |
| SEC-01 / TASK-0024 (2026-11-13) | Diagnóstico y plan de cierre de las reservas de TASK-0023 (solo análisis) | Staging `270e07d` sin cambios; 3 sondas de solo lectura; ensayo aislado de R-04 PASS; pruebas `audit/task-0024-r05-writer-paths` @ `8e8b80c` (42: 41 verdes y 1 roja esperada, la reproducción de F-SEC01-T24-01) | Asignación `6848913937` + decisiones `6849259532`; READY_FOR_REVIEW → **APPROVED** (`6850519341`) |
| SEC-01 / TASK-0025 (2026-11-13) | Corrección candidata de F-SEC01-T24-02 (13 vistas de `public`) | BASE `270e07d`; fails-before `56375f5` (5/5 en rojo); TESTED/REVIEW `6e7f525` (`fix/sec-01-t25-public-views-hardening`), 5/5 en verde; clon REST aislado PASS; suite completa 803/803 (8562 aserciones). **Sin aplicar** en la BD compartida | Asignación `6850596236`; READY_FOR_REVIEW |
| SEC-04 | Dependencias, integraciones e infraestructura | Pendiente | No cerrado |
| SEC-05 | Regresión consolidada y riesgos residuales | Pendiente | No cerrado |

## 4. Registro de hallazgos

Las severidades son las comunicadas por la ronda, sujetas a revisión. Una configuración observada o una hipótesis no equivale a una explotación demostrada.

| ID | Ronda / severidad | Hallazgo resumido sin mecanismo sensible | Confirmación | Corrección / decisión | Estado y siguiente paso |
|---|---|---|---|---|---|
| F-SEC01-01 | R1 / Alta | Capacidad de suplantación disponible a un actor sin autoridad suficiente | Reproducido en aislamiento | Restricción de la capacidad en servidor al rol autorizado; un archivo de runtime | Reparación aceptada en `0139e95`; **desplegada y verificada (solo lectura) en `cf01e9c`, 2026-10-21** |
| F-SEC01-02 | R1 / Alta, configuración | Delegación de administración de privilegios que requiere revisión | Permiso y acceso al editor observados; no confundir con demostración de toda cadena de escalación | Propuesta de mínimo privilegio; sin cambios en roles compartidos | **Parcial.** Faceta de escritura de cuentas corregida en R2 (ver F-SEC01-02a). El editor de roles (RoleResource) y la configuración de autoridad de concesión siguen como decisión del propietario. **R3 (2026-10-21): demostrado en aislamiento como F-SEC01-R3-04 (Alta, alerta), un camino que se salta las guardas desplegadas**; propuesta en el informe R3. El hallazgo NO se cierra |
| F-SEC01-02a | R2 / Alta | Un actor con gestión de usuarios podía otorgarse o asignar el rol con privilegios al crear/editar una cuenta, o editar una cuenta privilegiada | Reproducido en aislamiento; prueba de frontera de persistencia (el rol prohibido nunca se escribe en el pivote) | Autorización en servidor ANTES de la sincronización de la relación de roles (`PrivilegedRoleGuard` en `saveRelationshipsUsing`); bloqueo de apertura de cuenta privilegiada en `authorizeAccess` | Corregido en candidato R2; aceptado (6310331211 FINAL); **desplegado y verificado (solo lectura) en `cf01e9c`, 2026-10-21**; atomicidad solo para la conexión única actual |
| F-SEC01-03 | R1 / Baja–media, configuración | Permisos de escritura en catálogos globales para un rol de afiliación | Hipótesis derivada de permisos; no ejecutada | Confirmar necesidad y efecto antes de cambiar | ~~Pendiente de prueba y decisión~~ → **R3: efecto confirmado en aislamiento** (sonda restringida: el rol de afiliado puede abrir el alta de los dos catálogos). Propuesta: solo cambio de datos de roles (quitar esos dos permisos de alta); decisión del propietario |
| F-SEC01-04 | R1 / Baja | Superficie pública de prueba sin función de negocio vigente | Alcanzabilidad confirmada por inventario | Retirada (ruta, clase y vista) con regresión | **Corregido en candidato R2**; desplegado y verificado en `cf01e9c` (2026-10-21: `/form` → 404, ruta y clase ausentes) |
| F-SEC01-05 | R1 / Media provisional | Posible exposición excesiva de datos mediante una acción de cuentas | **No reproducido en el alcance probado** (el único método invocable devolvía datos al framework, no al navegador; el caso de contraseña vacía ya lo maneja el formulario) | Se retiró el método redundante; edición con contraseña vacía conserva el hash (probado) | Negativo acotado; no refutado globalmente. El indicio de campo de verificación (F-SEC01-07) permanece para SEC-03 |
| F-SEC01-06 | R1 / Regla de negocio | Autoridad para desvincular a otros usuarios de una empresa sin definición suficientemente explícita | Comportamiento observado | Definir autoridad de gestión de usuarios por empresa | Decisión pendiente; no modificar vínculos reales |
| F-SEC01-07 | R1 / Indicio | Campo de verificación de cuenta susceptible de asignación a nivel de modelo | No demuestra acceso desde una petición manipulada | Probar escritores reales de cuenta y campos protegidos | R2; no declarar bypass sin reproducción |
| F-SEC01-08 | R1 / Informativa provisional | Archivos históricos de diagnóstico en el repositorio | Existencia observada; entrega web no comprobada | Evaluar exposición, contenido y saneamiento | SEC-04; no afirmar ausencia de secretos por coincidencia de palabras |
| R-SEC01-01 | R1 / Fiabilidad | Formularios de empresa fallan si no hay ciudades | Confirmado en aislamiento; antecedente conocido | Reparación independiente pendiente | No cerrado |
| Q-SEC01-01 | Revisión R1 / Calidad de evidencia | Parte de la matriz usa el propio gate como expectativa | Confirmado por revisión del test | Añadir expectativas de negocio independientes | R2 |
| **F-SEC01-R3-01** | R3 / Alta | La página de edición de empresa y sus gestores de relación no volvían a comprobar el acceso después de abrirse: una página abierta (o una copia guardada) seguía escribiendo después de perder el vínculo o el permiso de edición | Reproducido en aislamiento (falla antes en `9fb0469`) | Re-autorización en cada petición (`hydrate`) y al montar; 404 | **Corregido en candidato R3** (`e95169c`); sin desplegar → **2026-11-12, TASK-0021: desplegado en staging** (`71e8a6d`). Antes, staging solo tenía el trait desde `7831e01` (TASK-0011), usado por 1 de 11 componentes; ahora 11/11, verificado en vivo |
| F-SEC01-R3-02 | R3 / Media | Algunas acciones de módulos de empresa no aplican el permiso de escritura propio del módulo | Confirmado en aislamiento (sonda restringida, 2 módulos) | Autorizar esas acciones con el permiso del módulo; corrección pequeña con regresión | Registrado; siguiente ronda (el presupuesto de R3 se usó en R3-01) |
| F-SEC01-R3-03 | R3 / Baja | El alta de categorías de una empresa acepta, por id forjado, una categoría inactiva | Confirmado en aislamiento (sonda restringida) | Filtrar por categoría activa en el servidor; corrección pequeña con regresión | Registrado; siguiente ronda |
| **F-SEC01-R3-04** | R3 / **Alta, alerta al propietario** | Un titular no super admin del permiso de edición de roles puede volverse privilegiado y saltarse las dos guardas desplegadas (suplantación y concesión de roles), porque el editor de roles no protege la definición del rol privilegiado | Confirmado en aislamiento (sonda restringida). Roles reales tienen ese permiso (instantánea restringida): **riesgo vivo** | Mitigación inmediata (dato de roles, acción del propietario): quitar los permisos de gestión de roles a todo rol que no sea super admin. Regla de código: solo un super admin administra roles; definición del rol privilegiado protegida (detalle en notas restringidas) | **Abierto**; forma parte de F-02. **2026-10-21 (R4):** regla del propietario: solo super_admin administra roles. Corregido en candidato R4 (`b51fa3e`), sin desplegar. Contención en datos vivos **bloqueada** (sin cambios); **el riesgo vivo sigue**. **2026-10-21 (posterior):** contención aplicada a mano por el propietario y verificada en solo lectura (0 concesiones fuera de super_admin; caché coherente). Código aceptado (`97737ba`), **sin desplegar**: sigue **abierto** hasta el despliegue → **2026-11-12, TASK-0021: código de R4 desplegado en staging** (`71e8a6d`); verificado en vivo: 4/4 `super_admin` y 0/364 otros usuarios administran roles; contención en datos intacta (0 fuera de `super_admin`). Pendiente de revisión |
| Q-SEC01-R3-05 | R3 / Gobierno | Significado ambiguo del origen «Confirmada» (`validated`): el manual dice que la empresa confirma sugerencias, pero el campo de aprobador describe una aprobación de la Cámara | Lectura de código y manual | Definir la regla | Decisión del propietario; sin cambios |
| Q-SEC01-R3-06 | R3 / Higiene | Archivo `error_log` de PHP versionado en directorios de la aplicación desde el primer commit | Inventario | Revisarlo y retirarlo en una ronda de higiene | Registrado |
| **F-SEC01-R4-01** | R4 / Media, integridad de permisos (disponibilidad) | Al guardar un rol, el editor de roles de Shield sincroniza solo los permisos que muestra. Como la pestaña de permisos personalizados está desactivada, **quita sin avisar** los permisos que no aparecen en el formulario (116 en staging; 73 se usan en el código) | Confirmado en solo lectura tras la contención manual, contra la instantánea restringida del 2026-10-20. ~~el rol sin usuarios perdió 115 permisos ocultos además de los de Role, y el rol con 1 usuario perdió 1 que la app comprueba (gestión de usuarios)~~ → **atribución corregida (6320241495):** el defecto quitó **109 permisos ocultos, todos en el rol sin usuarios**; el rol con 1 usuario no tenía ocultos y no perdió ninguno por el defecto. Además se desmarcaron casillas **visibles** fuera de Role: 6 de gestión de usuarios en el rol sin usuarios y 1 (lista de usuarios) en el rol con 1 usuario. Nada se añadió; super_admin, el resto de roles y las membresías no cambiaron | Decisión del propietario: restaurar o no lo perdido (lista exacta restringida; restaurar requiere aprobación). Antes de volver a guardar un rol en el editor: no guardar super_admin (tiene 116 ocultos) hasta decidir la corrección (mostrar los permisos personalizados o conservar los no mostrados) | **Abierto**; no es una exposición: solo reduce acceso. **2026-10-21:** corrección en candidato (`fix/sec-01-r4-01-role-editor-preserve`, TESTED `24413d5`), **sin desplegar**; propuesta de recuperación preparada (§5), **sin restaurar** → **2026-11-12, TASK-0021: revisado (PASS) y desplegado en staging** (`71e8a6d`); en vivo con rol sintético, antes 0/60 ocultos conservados y después 60/60. Recuperación de lo perdido: sin tocar (decisión aparte). Residual F-SEC01-T21-01 |
| F-SEC01-T21-01 | TASK-0021 / Baja, funcional e higiene | Al publicar el editor de roles en `App\Filament\Resources\Shield`, Shield le da la clave `shield::role`: la sección «Role» muestra 6 casillas `*_shield::role` que no existen ni usa `RolePolicy` | Observado en vivo (solo lectura) tras desplegar `71e8a6d`; no cubierto por las pruebas aisladas | Fijar la clave de entidad en `role` (o equivalente) con una prueba que compare los nombres renderizados con los de `RolePolicy`. Hasta entonces, no marcar esas casillas | ~~**Abierto.** Sin pérdida (los 6 `*_role` de `super_admin` quedan ocultos y se conservan) ni elevación (el editor ya no puede conceder `*_role`); marcarlas crearía nombres inútiles (hoy 0). Corregirlo requiere asignación~~ → ~~**Corregido en candidato, sin desplegar** (TASK-0022, `4bdec36`)~~ → **Corregido y desplegado en staging** (TASK-0023, `270e07d`, 2026-11-12; verificado en vivo con datos sintéticos). Antes: La clave de entidad queda en `role` y la sección muestra los 6 `*_role` reales en solo lectura; la prueba compara los nombres renderizados con `RolePolicy`. Hasta desplegarlo, no marcar esas casillas. El 2026-11-12 a las 16:10Z se crearon las 6 filas `*_shield::role`, sin vínculos (TASK-0022 §3); quedarán inertes. **2026-11-13:** el propietario acepta conservarlas (riesgo residual; `6849259532`) |
| **F-SEC01-T24-01** | TASK-0024 / **Media, latente** (integridad de cuentas privilegiadas) | La acción «Eliminar» de la lista de usuarios solo exige `delete_user` (`UserPolicy::delete` no recibe la cuenta objetivo), así que un usuario sin `super_admin` puede borrar una cuenta `super_admin` y, con ella, su rol privilegiado. La página de edición sí lo impide | **Reproducido en aislamiento** (`8e8b80c`, datos sintéticos; control positivo verde). Hoy ningún rol real no privilegiado tiene `delete_user` ni `view_any_user` (lectura agregada) | Que la política reciba la cuenta objetivo y deniegue si es privilegiada y el actor no es `super_admin`; la prueba de reproducción como fails-before. Mientras tanto, no conceder esos permisos a roles no privilegiados | **Abierto.** Corregirlo requiere asignación |
| **F-SEC01-T24-02** | TASK-0024 / **Alta potencial**, exposición de lectura | Vistas de `public` con derechos del propietario (BYPASSRLS), sin `security_invoker`, que el rol `anon` de la Data API de Supabase (activa) puede leer; una incluye datos de contactos. Las tablas tienen RLS (88/88) sin políticas y deniegan todo a `anon`/`authenticated`; ninguna vista es actualizable ni lee tablas de roles | Catálogo de solo lectura y respuesta 401 de la API sin clave; en vivo no se usó ninguna clave. **Demostrado en un clon REST aislado** (TASK-0025: PostgREST, credenciales sintéticas; `anon` recibe por REST un contacto sintético que RLS le niega en la tabla) | Inventariar dónde está la clave anónima (P0 si salió del equipo); `security_invoker = true` en las vistas, con migración y pruebas, o revocar `anon`/`authenticated`, o dejar de exponer `public` tras revisar las integraciones | ~~**Abierto.** Cambio en la BD compartida (desarrollo local + staging): requiere decisión del propietario~~ → **Corrección candidata, sin aplicar** (TASK-0025, `6e7f525`: `security_invoker` + REVOKE en las 13 vistas; propietario y `service_role` sin cambios). Aplicarla requiere un gate explícito |
| Q-SEC01-02 | Revisión R1 / Calidad de evidencia | Errores inesperados y ciertos accesos legítimos no siempre hacen fallar la matriz | Confirmado por revisión del test | Fallar ante 5xx/excepciones inesperadas, comprobar caminos permitidos y actor privilegiado representativo | **Atendido en R2** |
| RT-PHP-2026-10 | Runtime / Crítica (CVE-2026-109618, CVE-2026-109619, publicados 2026-10-21) | El runtime PHP de la app de staging (8.2.34, php-fpm) está por debajo del parche 8.2.35 | Medido en el contenedor en ejecución (versión CLI y FPM), 2026-10-22 | Solo runtime: rama `security/php-8.2.35-base` @ `1d3a16e` (padre `cf01e9c`), **diff únicamente en `Dockerfile`** (base fijada por digest a 8.2.35). Imagen candidata idéntica a la desplegada salvo PHP; suite aislada 611/7042 en `cf01e9c` con 8.2.35 | **Desplegado** el 2026-10-22: el servidor quedó en `1d3a16e`, con PHP 8.2.35 en CLI y FPM. **Aceptado por el propietario el 2026-10-26** (pruebas manuales PASS). Seguimiento en el repositorio privado de administración (Issue #1, etapa 3). Sin migraciones, código de aplicación ni R3/R4. El runner, con la base fijada a 8.2.35, está integrado en el servidor de pruebas y su rama está pendiente de push del propietario |
| RT-BUILD-2026-10 | Higiene de build / Alta (configuración embebida) | Sin `.dockerignore`, la imagen se construía desde el checkout del servidor e incluía archivos no versionados (`.env`, `.git`). Las imágenes anteriores conservan esa configuración en sus capas | Medido en las capas de la imagen en servicio, 2026-10-26 (solo conteos) | Rama `build/source-only-image` (base `1d3a16e`): `.dockerignore` estricto, más una guarda en el `Dockerfile` que detiene la build si entra un archivo prohibido. En la candidata, 0 nombres prohibidos y 0 valores secretos en capas; código, vendor y lock idénticos a lo desplegado. Ver `docs/deploy/IMAGEN_Y_SECRETOS.md` | **Candidato en revisión, sin desplegar** (2026-10-26). Las imágenes antiguas quedan restringidas al servidor hasta la decisión del propietario sobre su ciclo de vida |
| RT-MAIL-TLS-2026-10 | App / Media, seguridad del transporte de correo | La configuración SMTP de la app (`config/mail.php`, `mailers.smtp.stream.ssl`) declaraba la verificación del certificado y del nombre del servidor desactivada (`verify_peer=false`, `verify_peer_name=false`, `allow_self_signed=true`) | Confirmado en el código. Con Laravel 12.69 y `symfony/mailer` 7.4.17, Laravel no pasa `stream` al transporte, así que el bloque **no tenía efecto**. Lo prueba un control negativo en local, y en vivo el transporte no tiene opciones SSL. La opción que sí desactiva la verificación (`verify_peer` en el primer nivel) nunca estuvo puesta. El relay presenta un certificado válido (TLS 1.3) | Commit `e49cbac` (rama `fix/mail-tls-verify`): quita el bloque y agrega `tests/Feature/Security/MailTransportTlsTest.php`. La prueba vigila la configuración y el transporte, e incluye un control de que detecta `verify_peer=false`. 3/3 en local; sin envío de correo | **Corregido y desplegado** el 2026-10-26 16:41Z en staging, con el workflow de despliegue (ejecución exitosa). Verificación de solo lectura posterior: app con PHP 8.2.35, `.env` ausente de la imagen, configuración en caché sin `stream` ni `verify_peer`, HTTP 200 y 0 errores nuevos. **Pruebas reales de envío con la verificación activa: 6 de 6 recibidas**, confirmadas por el propietario: 3 al buzón del sistema y 3 a buzones externos del propietario, en Gmail y dos dominios propios. **Cerrado.** Registrado por la revisión 6416153795 (repositorio privado de administración) |
| RT-MAIL-2026-10 | Funcionalidad / Correo del sistema | El correo estaba en modo simulado (`log`): la verificación de cuentas y los avisos de solicitudes no salían | — | Activación autorizada por el propietario: transporte `smtp` global con remitente del dominio; el único cambio fue regenerar la caché de configuración (solo cambiaron claves `mail.*`) | **Activado** el 2026-10-26. Una prueba controlada fue aceptada por el proveedor y el propietario confirmó que llegó al buzón. Detalle en `docs/deploy/CORREO_DEL_SISTEMA.md` (rama `build/source-only-image`) |
| F-SEC03-01 | SEC-03 TASK-0017 / Media (defensa en profundidad; Alta si el borde lo permitiera) | El host de los enlaces de recuperación y de verificación se toma de la petición, no de la configuración | **Confirmado en la aplicación** (oráculos aislados); explotación externa **no verificada**: el túnel solo enruta el hostname de staging y el origen no expone HTTP | Propuesta P1: raíz y esquema de URL forzados desde `APP_URL` y `TrustHosts`. Sin implementar | Abierto; decisión y autorización del propietario. **Verificar producción antes de llevar la recuperación allí** → 2026-11-11, TASK-0018: **corregido en candidato** (`cb3fff4`; P1, raíz de `APP_URL`), sin desplegar → 2026-11-12, TASK-0019: **desplegado en staging** (`9d0c24d`) y verificado (enlace generado en el contenedor vivo con el host de staging, https) → 2026-11-12, TASK-0020: verificado con correo real (enlaces con el host de staging) y `X-Forwarded-Host` del cliente ignorado |
| F-SEC03-02 | SEC-03 TASK-0017 / Media (misma causa que 01) | La aplicación acepta peticiones para cualquier host (`TrustHosts` desactivado, panel sin dominio) | Confirmado en la aplicación (200 con host ajeno) | P1 | Abierto → 2026-11-11, TASK-0018: **corregido en candidato** (`cb3fff4`; `TrustHosts`, hosts exactos), sin desplegar → 2026-11-12, TASK-0019: **desplegado en staging**; rechazo de Host ajeno verificado solo en aislamiento (probarlo en staging exige una cabecera manipulada) → 2026-11-12, TASK-0020: verificado en staging: el borde bloquea Hosts ajenos (403/530) y tras el borde la app responde 400 |
| F-SEC03-03 | SEC-03 TASK-0017 / Media, disponibilidad | Detrás de cloudflared y nginx, la aplicación ve a todos los clientes con la IP del gateway: los límites «por IP» de login, recuperación y reset son globales | **Confirmado en staging** (configuración, 503/503 peticiones de 24 h desde una sola dirección privada) y con un oráculo aislado; el bloqueo no se ejerció | P2: IP real desde el gateway confiable; **quitar `X-Forwarded-Host` de las cabeceras confiables** o aplicar P1 antes. Sin implementar | Abierto → 2026-11-11, TASK-0018: **corregido en candidato** (`cb3fff4`; P2, `CF-Connecting-IP` solo desde pares de confianza, XFH descartado), sin desplegar; llegada de la cabecera por el túnel NO VERIFICADA → 2026-11-12, revisión 6819392897: confianza reducida al gateway exacto (antes `172.16.0.0/12`, que incluía redes de otros sitios del host); `9d0c24d`, sin desplegar; **validación efectiva pendiente** → 2026-11-12, TASK-0019: **desplegado y verificado en staging** (tránsito de `CF-Connecting-IP` hasta PHP observado; par = gateway exacto; IP efectiva = cliente). Sin probar activamente: suplantación a través del borde y cupos con dos clientes → 2026-11-12, TASK-0020: verificado adversarialmente: `CF-Connecting-IP` del cliente bloqueado por Cloudflare (403), `X-Forwarded-For`/`True-Client-IP` falsos sin efecto, cupos separados entre dos clientes |
| F-SEC03-04 | SEC-03 TASK-0017 / Baja–media, disponibilidad dirigida | Las solicitudes que no pasan Turnstile consumen igual el cupo por correo y por RIF | Confirmado en aislamiento (correo y RIF) | P3: Turnstile antes de contar los cupos por destinatario. Sin implementar | Abierto → 2026-11-11, TASK-0018: **corregido en candidato** (`cb3fff4`; P3), sin desplegar → 2026-11-12, TASK-0019: **desplegado en staging**; no ejercido allí (Turnstile y correo; sin buzones sintéticos aprobados) → 2026-11-12, TASK-0020: verificado en staging (5 pedidos sin Turnstile: cupo por correo en 0; el pedido válido siguiente, aceptado) |
| F-SEC03-05 | SEC-03 TASK-0017 / Baja | Cambiar el correo de una cuenta conserva `email_verified_at`: el buzón nuevo hereda la verificación y la elegibilidad por RIF | Confirmado en aislamiento (modelo; el escritor es la gestión de usuarios). Concreta el indicio F-SEC01-07 en esta faceta | P4: anular la verificación y los tokens pendientes al cambiar el correo. Sin implementar | Abierto → 2026-11-11, TASK-0018: **corregido en candidato** (`cb3fff4`; P4), sin desplegar → 2026-11-12, revisión 6819392897: borrado de tokens antes del `UPDATE` (orden seguro ante fallos; rollback probado); `9d0c24d`, sin desplegar → 2026-11-12, TASK-0019: **desplegado en staging**; no ejercido allí (exigiría escribir cuentas reales) → 2026-11-12, TASK-0020: verificado en staging (verificación anulada, token del correo anterior borrado, su enlace rechazado, pantalla de verificación). Abrir el enlace del buzón nuevo: no ejecutado |
| F-SEC03-06 | SEC-03 TASK-0017 / Baja–media, transporte | La aplicación responde por HTTP sin redirigir a HTTPS y sin HSTS (las cookies sí llevan `Secure`) | Confirmado en staging con GET inocuos | P5: «Always Use HTTPS» y HSTS en Cloudflare, o redirección en la aplicación; decisión de infraestructura | Abierto → 2026-11-11, TASK-0018: **corregido en parte en candidato** (`cb3fff4`; HSTS 1 día; redirección apagada hasta verificar el túnel), sin desplegar; «Always Use HTTPS» en Cloudflare pendiente de autorización → 2026-11-12, revisión 6819392897: PASS de código (métodos, *query*, destino canónico, sin bucles, HSTS); **HTTPS efectivo pendiente** → 2026-11-12, TASK-0019: **desplegado y verificado en staging** («Always Use HTTPS» activo; HSTS `max-age=86400` sin `includeSubDomains` ni `preload`, solo en el hostname de staging; sin bucles) |

En R2 SÍ se estableció y corrigió un fallo de inyección de roles en cuentas (F-SEC01-02a). NO se estableció traversal de directorios en las superficies probadas (segmento de id numérico y `secciones` con lista blanca): resultado negativo acotado, no certificación general.

### Revisión R2 (6310331211) — correcciones solicitadas y su estado en esta entrega

| ID | Petición | Estado |
|---|---|---|
| R2-REV-01 | Autorizar la concesión de roles ANTES de persistir; no depender de limpieza posterior; conectar o retirar helper muerto; frontera/atomicidad | Hecho: autorización en `saveRelationshipsUsing` antes de `sync()`, dentro de la transacción de Filament; prueba de frontera de persistencia; helper muerto retirado |
| R2-REV-02 | Aserciones de traversal sobre texto de PDF decodificado; respuesta exacta de denegación; retirar objetivos de SO; capa de denegación e integridad del canario | Hecho: `pdftotext` sobre PDFs; 404 exacto «Archivo no disponible» con 0 consultas/render; sin objetivos de SO; capa registrada; canario intacto |
| R2-REV-03 | Expectativas independientes en la matriz; fallar 5xx inesperados (JoinViews contado); actor privilegiado representativo; declarar filas solo-gate | Hecho: expectativas de negocio allow/deny en entradas de alto valor; 5xx inesperado falla salvo JoinViews; barrido completo declarado como caracterización |
| R2-REV-04 | Registro acumulativo con estado; separar aceptado de desplegado; ayuda de administrador | Este documento; comportamiento de staff descrito en §5 R2 |
| R2-REV-05 (segunda revisión) | Habilitar explícitamente transacciones en `CreateUser` y `EditUser` sobre la conexión real de cuentas/pivote; pruebas de inyección de fallo con rollback antes del teardown; conservar la autorización previa a la sincronización | ~~PENDIENTE — no ejecutada~~ (estado al 2026-10-21 ~10:15Z). **Corregida en candidato** (`f4dd8fb`, entrega posterior): transacción explícita por página en ambas, sobre las conexiones de cuenta y pivote; inyección de fallo verificada antes del teardown. ~~No aceptada; sin desplegar~~ → **aceptada** (6310331211 FINAL) y **desplegada** en `cf01e9c` (2026-10-21), verificada en código y configuración; atomicidad solo con una conexión |

**Nota fechada 2026-10-21 (cierre de sesión 6313400617).** La segunda revisión de 6310331211 pide una corrección acotada de atomicidad. Motivo: Filament v3.3.55 (versión fijada) deja las transacciones del panel **desactivadas** por defecto y este candidato no las activa en sus páginas ni en el proveedor del panel. Por eso **se retira** la afirmación anterior de que la reparación «ya era atómica / dentro de la transacción de Filament» (aquí y en §5 R2): era incorrecta. La autorización previa a la sincronización sigue siendo válida y se conserva. La pausa se debe a la cuota de sesión del propietario; la corrección no se retiró y es la próxima tarea de código. Progreso revisado que se mantiene: guarda de privilegios previa a la sincronización; pruebas de rutas con canario y texto de PDF; matriz independiente de alto valor y fallo ante errores inesperados; este registro.

## 5. Correcciones y validación por ronda

### SEC-01 R1

- **Base:** `4a56fafa5117cd6efb739603aa468efbf841cd15`.
- **Reparación:** F-SEC01-01, commit `613c359`, incluida en candidato revisado `0139e954ec22f0a6bfa2377968a6a3af86c15874`.
- **Regresión previa:** falla en el control de suplantación en el commit de pruebas anterior a la reparación.
- **Suite completa:** ejecución del desarrollador en `7d8531e24fcb3c109b6bb93ea0621c691c7e6074`: 593 tests / 6.824 aserciones.
- **Enfocadas:** ejecución del desarrollador en `481987719ce6d8f2e5a9fa0094a450fd1d39c4b9`: 25 tests / 2.487 aserciones.
- **Revisión manual:** diff de aplicación y mecanismo de ejecución de la dependencia fijada en lockfile revisados. No es una ejecución independiente de PHPUnit.
- **Limitaciones:** camino permitido probado mediante el gestor; falta flujo completo de acción/salida en contexto limpio. La matriz no sustituye un oráculo independiente de permisos. Sin comprobación exhaustiva de todas las acciones.
- **Informe:** [security_sec01_2026-10-20.md](../../audit/security_sec01_2026-10-20.md).
- **Despliegue y cierre:** pendientes; no cerrar el hallazgo operativo por aceptación del código.

### SEC-01 R2 — cuentas y archivos (6310273754) + correcciones de revisión (6310331211)

- **Base:** `0139e954ec22f0a6bfa2377968a6a3af86c15874` (candidato F-01 congelado; no modificado).
- **Rama:** `fix/sec-01-account-path-boundaries`.
- **Reparación F-SEC01-02a:** autorización de concesión de roles en el límite de escritura, ANTES de sincronizar la relación (`app/Support/Security/PrivilegedRoleGuard.php` + `saveRelationshipsUsing` en `UserResource`), dentro de la transacción de create/edit de Filament. Apertura de cuenta privilegiada bloqueada en `EditUser::authorizeAccess` (consulta fresca de pivote). Retirado el enfoque previo de limpieza posterior y su helper de snapshot.
- **F-SEC01-04:** retirada la ruta `/form`, su componente y su vista.
- **F-SEC01-05:** no reproducido; método redundante retirado.
- **Regresión antes/después:**
  - antes (enfoque de limpieza posterior): la prueba de frontera de persistencia falla porque el rol prohibido SÍ se escribía en el pivote antes de retirarse;
  - después: el rol prohibido nunca se escribe; enfocadas verdes.
- **Enfocadas:** `e7ba59d9deb35198bc9fb3fac857255ebaa9e0e2` (`AccountPrivilege|PathBoundary|ImpersonationAction|AccessMatrix|SurfaceInventory`): 22 tests. **Suite completa:** ver §entrega.
- **Cambio visible para staff:** al intentar asignar un rol con privilegios sin ser Super Admin, la asignación se descarta y aparece un aviso en la interfaz; no cambia nada para afiliados. No se modificó el manual del afiliado por eso; no existe página de ayuda de administración de usuarios donde documentarlo (se registra aquí).
- **Traversal:** inventario dirigido de sinks; canario fuera de la raíz; el segmento `{empresa}` devuelve 404 y `secciones` cae al perfil completo en todas las variantes; sin lectura fuera de raíz, sin 5xx, sin fuga; canario intacto.
- **Informe:** [security_sec01_r2_2026-10-21.md](../../audit/security_sec01_r2_2026-10-21.md).
- **Estado:** CAMBIOS SOLICITADOS atendidos; no aceptada todavía; sin desplegar.
- **Actualización 2026-10-21 (segunda revisión):** CAMBIOS SOLICITADOS. La frase «dentro de la transacción de create/edit de Filament» de la reparación F-SEC01-02a queda retirada (ver nota fechada en §4): con Filament v3.3.55 las transacciones del panel están desactivadas por defecto y este candidato no las activa. Corrección de atomicidad (R2-REV-05) pendiente, no ejecutada; pausa por cuota. REVIEW_HEAD `51a923e`; sin desplegar.
- **Actualización 2026-10-21 (R2-REV-05 ejecutada, sesión nueva):**
  - **Configuración:** `UsesAccountWriteTransaction` (en `CreateUser` y `EditUser`) habilita las transacciones solo en esas dos páginas (`hasDatabaseTransactions()`; el valor por defecto del panel no cambia). Abre/confirma/revierte en las conexiones reales del modelo `User` (cuenta) y del modelo de rol (pivote `model_has_roles`); hoy son la misma.
  - **Pruebas de inyección de fallo** (`AccountWriteAtomicityTest`), con el estado verificado tras la petición fallida y antes del teardown, más instrumentación (eventos de inicio por conexión, nivel de anidamiento):
    - crear con fallo tras el pivote → no queda cuenta ni vínculo de rol;
    - editar con fallo tras actualizar la cuenta → se restauran nombre, correo y roles previos;
    - operaciones completas → persisten;
    - rol privilegiado forjado → nunca se escribe.
  - **Antes** (`309146b`, solo pruebas): 3 de 5 fallan. **Después** (`f4dd8fb`): ver informe.
  - **Garantía probada:** la reversión ante una excepción dentro de la petición en esas dos páginas. No cubre caídas del proceso o de la base de datos fuera de la transacción.
  - **Estado:** corregida en candidato; no aceptada; sin desplegar.

### Despliegue SEC-01 R1+R2 (6316265832) — 2026-10-21

- **Aceptación:** 6310331211, sección «FINAL R2 REVIEW — ACCEPTED FOR DEPLOYMENT PREPARATION» (REVIEW_HEAD `cf01e9c` aceptado; TESTED `f4dd8fb`). R2 y R2-REV-05 quedan **aceptadas**; la cronología de revisiones anterior se conserva.
- **Autorización:** 6316265832 (propietario), solo para este SHA. No autoriza cambios de roles/permisos ni cierra el programa.
- **Preflight (solo lectura):**
  - rama remota y checkout de staging en `87dd997`, sin cambios en archivos versionados (6 carpetas no versionadas de assets publicados por el propio despliegue, sin código);
  - `cf01e9c` es fast-forward de `87dd997`. El diff de runtime son solo los 9 archivos aceptados (F-01, guarda, páginas, trait, retiro de `/form`), sin `database/`, composer, config, docker ni workflow;
  - sin migraciones pendientes; sin despliegue en curso; app y nginx arriba; debug y mantenimiento apagados;
  - **misma conexión:** cuenta y rol/pivote resuelven ambas a `pgsql`.
- **Recuperación:** imagen en ejecución `f91e97322189` etiquetada `perfilafiliados-app:pre-sec01-87dd997` antes de promover. No hizo falta.
  - Procedimiento: retag de esa imagen a `latest`, `git reset --hard 87dd997` en el checkout y `docker compose up -d app`.
- **Despliegue:** push normal de `cf01e9c` a `feature/upgrade-filament-v3` (sin force). Workflow «Deploy a Contabo» run 39602544527, **success**, 12:48–12:50Z.
- **Post-despliegue, runtime:** checkout `cf01e9c` limpio; imagen `081c35c5715d`; app reiniciada y nginx arriba; sin migraciones pendientes.
- **Post-despliegue, HTTP público (peticiones reales, anónimas):** `/admin/login` 200; `/form` 404; URL desconocida 404; descarga antigua 302 → login.
- **Post-despliegue, smoke de seguridad** `docs/deploy/smoke_sec01_readonly.php`: **17/17**, frente a 3/17 en el mismo runtime antes de promover. Corre en una transacción READ ONLY revertida, con usuarios solo en memoria; no envía peticiones ni escribe nada. Distingue fuente/configuración de ejecución en memoria:
  - *configuración:* debug y mantenimiento apagados; cuenta y pivote en la misma conexión;
  - *fuente:* `User::canImpersonate()` instalado; el campo de roles sincroniza mediante `PrivilegedRoleGuard::authorizedRoleIds` antes del `sync()`; `CreateUser` y `EditUser` usan `UsesAccountWriteTransaction`; la clase `Form` antigua no existe;
  - *ejecución en memoria:* sin roles o con un rol ordinario no se puede suplantar; con super admin sí. Sin actor super admin, el rol privilegiado pedido se quita antes de sincronizar. `hasDatabaseTransactions()` es true en ambas páginas y la transacción cubre exactamente la conexión de la cuenta. No hay ruta `/form`.
- **Smokes previos (solo lectura):** exportación 25/25, 0010C 18/18, perfil completo 13/13.
- **Escrituras y rollback:** NO se probaron en staging (prohibido). La evidencia de atomicidad son las pruebas aisladas de inyección de fallo (`f4dd8fb`: enfocadas 45 OK, suite 611 OK) más la verificación de código y configuración desplegados.
- **Limitación de conexión única:** la atomicidad vale para la configuración actual, con cuenta y pivote en una sola conexión. Abrir y confirmar transacciones en varias conexiones **no** forma una transacción distribuida. Si la configuración separa esas escrituras, hace falta un invariante que falle cerrado o una arquitectura revisada aparte; no se afirma atomicidad entre bases.
- **Resuelto en runtime (dentro de lo comprobado):** F-SEC01-01, F-SEC01-02a, F-SEC01-04, R2-REV-05.
- **Pendiente:** UAT del propietario con sus cuentas, el clic real de suplantación por un super admin y los flujos de escritura en staging. Siguen abiertos F-02 (editor de roles/configuración), F-03, F-06, 20/23 relation managers, la búsqueda global en 12/13 recursos y SEC-02..05. **No se afirma que el sistema sea seguro.**

### Validación del propietario de la entrega desplegada — 2026-10-21

- **OWNER_UAT: PASS.** Fuente: confirmación del propietario en el chat, del 2026-10-21, registrada en el Issue #2 `6316775771`. No se infieren resultados por acción ni de navegador más allá de esa confirmación, y no hubo una prueba de navegador del revisor.
- **Qué cierra:** la espera de UAT de la entrega desplegada (`cf01e9c`: F-SEC01-01, F-SEC01-02a, F-SEC01-04, R2-REV-05). **No cierra SEC-01 ni el programa.**

### SEC-01 R3 — relaciones de empresa, búsquedas prioritarias y decisiones de permisos (6316775771)

- **Rama / BASE:** `audit/sec-01-r3-company-boundaries` desde `eb03b4c` (el runtime es igual al desplegado `cf01e9c`).
- **TESTED_HEAD:** `e95169c`; REVIEW_HEAD en el comentario de entrega.
- **Fails-before:** rama de evidencia `audit/sec-01-r3-fails-before` @ `9fb0469`.
- **Reparación F-SEC01-R3-01 (Alta), corregida en candidato:**
  - `EmpresaAccess::mayEdit()` aplica las condiciones de la propia página de edición, leídas en el momento: autenticado, verificado, vinculado ahora a la empresa y con permiso para editarla;
  - el trait `ReauthorizesEmpresaAccess` repite esa comprobación en cada petición Livewire (`hydrate`) de `EditEmpresa` y de los 10 gestores de relación de Empresa; los gestores también la hacen al montarse;
  - el rechazo es el mismo 404 que da una empresa ajena;
  - antes, una página abierta mientras el usuario estaba vinculado seguía escribiendo después de revocarle el vínculo o el permiso de edición.
- **Pruebas:**
  - antes (`9fb0469`): fallan exactamente las 3 pruebas de re-autorización;
  - después (`e95169c`): suite completa **624 OK** (7.201 aserciones), en el primer intento.
  - Cinco fixtures existentes recibieron el permiso de edición de empresa que exige la página que contiene esas pestañas. Una prueba existente acepta ahora el 404 además del 403 (oráculo R2-REV-03).
- **Matrices:** 7 gestores (permitido / denegado, claves y ids de otra empresa, ids forjados, catálogos compartidos sin cambios, gobierno de la taxonomía, revocación después de montar) y 3 búsquedas (Customer, TaxonomyCandidateTerm, Role). Detalle en el informe.
- **Comportamiento visible:** ninguno para un usuario con acceso. Una página que quedó abierta después de perder el acceso responde «no encontrado» en su siguiente acción. El manual no cambia.
- **Informe:** [security_sec01_r3_2026-10-21.md](../../audit/security_sec01_r3_2026-10-21.md).
- **Estado:** listo para revisión; no aceptado; sin desplegar.
- **Actualización 2026-10-21 (revisión 6318588005):** R3 **aceptado** (REVIEW_HEAD `66f5ce8`), **NO desplegado**.
- **Corrección de cobertura (pedida en 6318588005):** la revocación después de montar se ejecutó en estos casos concretos:
  - con el vínculo revocado: guardado de cabecera en Assets y en Management, borrado en Experiences, Presence y Sustainabilities, quitar en TaxonomyCategories, y actualizar un campo y guardar en la página de la empresa;
  - con el permiso de edición retirado: **solo** el guardado de Management y el borrado de Presence (no Assets, como decía la tabla de R3);
  - con el gestor ligado a otra empresa: los siete, al montarse.

### SEC-01 R4 — administración de roles exclusiva de super_admin (6318625636)

- **Regla del propietario (2026-10-21, vinculante):** solo puede administrar roles un usuario que tenga **hoy** el rol `super_admin`. Ni un permiso genérico ni el acceso a la gestión de usuarios lo exceptúan. Un rol menor no puede alterar la definición del rol privilegiado.
- **A. Contención (datos vivos): BLOQUEADA, sin ningún cambio.**
  - **Qué pasó:** el clasificador de permisos de la plataforma del ejecutor rechazó la única escritura autorizada. No se rodeó. Se alertó en `6318733204`.
  - **Verificación previa** (solo lectura, sobre `cf01e9c`, `pgsql`, guard `web`):
    - conjunto de permisos derivado de Shield y de `RolePolicy`: 12 nombres, 6 existentes;
    - fuera de super_admin: **2 roles, 12 asignaciones** (con 1 y 0 usuarios), **0 permisos directos** y ningún rol con nombre parecido en otro guard;
    - continuidad: el rol super_admin conserva los 6 permisos, y sus **4 cuentas verificadas** administran roles a través de ese rol.
  - **Acción del propietario:** en la app, como super_admin: **Roles** → cada rol que no sea super_admin → desmarcar sus permisos de **Role** → guardar. Alternativas: permitir esta escritura al ejecutor, o ejecutar el script revisado que se entrega por canal privado.
  - **Restringido:** la instantánea previa, el script y el plan inverso están fuera de git. Cualquier restauración requiere aprobación.
- **B. Reparación de código (rama `fix/sec-01-r4-role-administration`, BASE `66f5ce8`, TESTED `b51fa3e`), sin desplegar:**
  - **`RolePolicy`:** todas las habilidades exigen tener super_admin, leído en el momento con una consulta fresca, **y** el permiso explícito. Así, una página abierta antes de perder el rol queda rechazada en su siguiente petición, aunque el usuario conserve permisos directos.
  - **`PrivilegedRoleDefinitionGuard`:** en el límite del modelo, el rol privilegiado no se puede renombrar, cambiar de guard ni eliminar, y ningún otro rol puede tomar su nombre. Sus permisos siguen siendo editables por un super_admin.
  - **Límite preciso (precisión pedida en 6319407886):** protege las escrituras de Eloquent que disparan eventos de modelo. No es una restricción de base de datos y no intercepta SQL directo, actualizaciones o borrados masivos del query builder, `saveQuietly` ni `withoutEvents`.
- **A'. Contención manual: CONFIRMADA por el propietario (6319443716) y VERIFICADA en solo lectura** (2026-10-21 ~15:20Z, runtime `cf01e9c`, transacción `READ ONLY`, sin escrituras ni cambios de caché):
  - **Recalculado, sin reutilizar la instantánea de 12 filas:** 12 nombres derivados de Shield, 6 existentes, 0 comodines.
  - **Fuera de super_admin: 0 asignaciones a roles** (antes 12, en 2 roles) y **0 permisos directos a usuarios**.
  - **Continuidad de super_admin: PASS.** Un rol super_admin con los 6 permisos; sus **4 cuentas verificadas** pasan todas las comprobaciones de administración de roles.
  - **Caché y acceso:** almacén `file`, entrada presente y **coherente con la base** (0 pares fuera de super_admin, 6 en super_admin). Por tanto, los guardados de la interfaz la invalidaron y se reconstruyó. En un proceso nuevo, que es lo que ve la siguiente petición, el gate desplegado da **0 de 325** usuarios que no son super_admin con alguna habilidad de administración de roles. Staging responde 200.
  - **Sin cambios:** 5 roles, 329 membresías, 366 usuarios; super_admin y los otros dos roles intactos.
  - **Efecto colateral:** F-SEC01-R4-01 (§3). La diferencia exacta está en las notas restringidas.
- **Pruebas:**
  - antes (`4953be6`): fallan 4 de 5 pruebas, las 4 protecciones; la vía permitida de super_admin pasa;
  - el oráculo de la matriz (lista de roles) se actualizó a la regla del propietario;
  - suite completa en `b51fa3e`: **629 OK** (7.344 aserciones), en el primer intento.
- **Ayuda de administración:** `docs/manual_usuario/manual_administrador.md` §9.1. El manual del afiliado no cambia.
- **Informe:** [security_sec01_r4_2026-10-21.md](../../audit/security_sec01_r4_2026-10-21.md).
- **Riesgo residual:** ~~**alto y vivo** en staging hasta que se aplique la contención~~ → **contenido en los datos** (verificado). El código de R4 (aceptado, `97737ba`) **no está desplegado**: hasta entonces, si alguien vuelve a otorgar a otro rol los permisos de Role, el camino reaparece. Contención y código son protecciones distintas, y hacen falta las dos. SEC-01 sigue abierto.

### SEC-01 F-SEC01-R4-01 — el editor de roles conserva lo que no muestra (6320241495)

- **Causa exacta (Shield 3.9.10 instalado, sin editar vendor):**
  - `EditRole::afterSave` y `CreateRole::afterCreate` hacen `firstOrCreate` con cada valor enviado y luego `syncPermissions` con **exactamente** esa lista. Lo que el formulario no muestra se desvincula.
  - Las casillas son las opciones de los campos visibles: los prefijos de cada recurso registrado, las páginas y widgets no excluidos, y los personalizados **solo** si esa pestaña está activa. Aquí está desactivada.
  - En staging hay 116 permisos sin casilla. Son nombres de recursos que ya no están registrados en el panel o con otro identificador, `export_*`, `taxonomy_*` y páginas o widgets antiguos. Las políticas de la app usan 73 de ellos.
  - `CheckboxList` no valida los valores contra sus opciones, así que un nombre forjado se creaba y se concedía (tras R4, solo un super_admin llega a esa vía).
  - Activar la pestaña de personalizados **no** basta: mostraría todo permiso que no sea de entidad, y seguiría creando lo forjado.
- **Corrección** (rama `fix/sec-01-r4-01-role-editor-preserve`, desde `f41bcb6`):
  - **Recurso de la app:** `App\Filament\Resources\Shield\RoleResource` es el recurso publicado, la vía que Shield admite. Conserva el formulario, la tabla, la dirección (`shield/roles`) y las etiquetas, y cambia solo las páginas.
  - **Regla de escritura:** final = (enviado ∩ opciones que el formulario muestra ahora, calculadas en el servidor) ∪ (permisos que el rol tiene **ahora** fuera de ese conjunto).
    - Se lee en el momento, con la fila del rol bloqueada.
    - Solo se crea un nombre que el formulario muestra, y con el guard guardado del rol.
  - **Transacción:** el rol y sus permisos son una sola transacción sobre las conexiones de rol y de permiso. La caché de permisos se vacía otra vez después del commit.
  - **Sin cambios:** R4 (solo un super_admin vigente administra roles), la protección de identidad del rol privilegiado y las asignaciones de cuentas.
- **Pruebas** (`RoleEditorPermissionPreservationTest`, 7):
  - el panel sirve solo el editor de la app;
  - guardar sin cambios en un rol común y en el privilegiado;
  - quitar y agregar una casilla visible conservando las ocultas;
  - parte oculta leída en el momento: no se repone lo quitado ni se pierde lo agregado desde que se abrió la página;
  - nombres forjados, ids, nombres ocultos en un campo visible y un campo inventado: nada se crea ni se concede;
  - página abierta como super_admin después de perder el rol;
  - fallo inyectado después de desvincular y vincular: se revierten el rol y cada vínculo;
  - en todas, conjuntos exactos, número de filas `Permission` y membresías.
- **Resultados:**
  - **antes de la corrección** (`44da9a3`, páginas que solo heredan): **fallan 5 de 6**; la revocación ya la cubría R4;
  - **enfocadas** en `24413d5` (seguridad, suplantación, usuarios, exportación, verificación, alta): **74 OK**;
  - **suite completa** en `24413d5`: **636 OK** (7.388 aserciones), en el primer intento.
- **Recuperación: solo PROPUESTA, nada restaurado.** El delta exacto está en notas restringidas.
  - **Rol con 1 usuario** (7 perdidos): 6 de administración de roles, que **nunca** se restauran, y 1 de lista de usuarios (visible). Propuesta: restaurar ese 1 **solo si** el propietario confirma que quitarlo no fue intencional.
  - **Rol sin usuarios** (121 perdidos):
    - 7 de administración de roles: **nunca**;
    - 6 visibles de gestión de usuarios (1 de lectura y 5 de escritura). La escritura permite asignar roles no privilegiados, así que solo se restauran con una intención explícita;
    - 66 ocultos **activos**: CRUD de módulos de empresa y de catálogos geográficos e infraestructura. Escribir en catálogos globales es la clase de riesgo de F-03;
    - 42 ocultos **sin referencia** en el código (`export_*` y páginas o widgets antiguos): no se restauran.
    - Propuesta: **0 ahora**, porque no tiene usuarios. Si el rol vuelve a usarse, se decide por bloques.
  - **Requisitos de cualquier restauración:** aprobación del propietario, **después** de desplegar esta corrección (si no, el siguiente guardado lo vuelve a borrar), con un script revisado y transaccional y no con el editor, y vaciando la caché.
- **Informe:** [security_sec01_r4_01_2026-10-21.md](../../audit/security_sec01_r4_01_2026-10-21.md).

### SEC-03 / TASK-0017 — recuperación de cuentas, tokens y confianza en Host/proxies (6797800863) — 2026-11-11

- **Alcance:** vinculación del token (A/B sintéticas), *Host* y cabeceras de proxy, recuperación por RIF, verificación y
  cambio de correo, CSRF, logs y caché, y configuración real en solo lectura. Objetivo: staging `b048df0`.
- **Método:** lectura de código y del *vendor* fijado (Laravel 12.69.0, Filament 3.3.55); sonda de configuración en el
  contenedor (solo hosts e indicadores); conteos agregados del log de nginx; dos GET inocuos; pruebas en el runner
  aislado. Sin peticiones manipuladas a staging, sin correo, sin cuentas reales y sin producción.
- **Pruebas:** `tests/Feature/Security/Sec03AccountRecoveryControlsTest.php` (13 controles, verdes) y
  `Sec03AccountRecoveryOpenFindingsTest.php` (7 oráculos, rojos en `b048df0` por diseño; pasan cuando se corrija).
  Enfocada `8a4ed35`, run `20261111T141320Z-8a4ed35df5aa`: 20 tests, 13 OK y 7 fallos esperados. Suite completa `8a4ed35`, run
  `20261111T141441Z-8a4ed35df5aa`: 736 tests, 7939 aserciones, solo los 7 fallos esperados.
- **Correcciones:** ninguna. La propuesta P1–P5 está en el informe. **Atención a P2:** confiar en proxies sin quitar
  `X-Forwarded-Host` volvería explotable F-SEC03-01.
- **Despliegue:** ninguno. **Riesgos residuales:** borde de Cloudflare sin prueba activa, producción sin inspeccionar,
  sin correo real y sin medir tiempos.
- **Informe:** [SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md](../../audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md).

### SEC-03 / TASK-0018 — correcciones P1–P5 (6802857575) — 2026-11-11

- **Alcance:** P1–P5 de TASK-0017, solo en aislamiento. Tarea: [0018-sec03-p1-p5-remediation.md](../orquestador/tasks/0018-sec03-p1-p5-remediation.md) (matriz por hallazgo, configuración y plan de infraestructura).
- **Cambios (rama `fix/sec-03-p1-p5`, `93c36f7` código + `cb3fff4` manual):**
  - P1: `URL::formatHostUsing` con la raíz de `APP_URL` (fuera de `local`); `TrustHosts` con hosts exactos.
  - P2: `TrustProxies` confía solo en `TRUSTED_PROXIES` (loopback y bridge de Docker por defecto, sin `*`), toma
    `CF-Connecting-IP` desde esos pares y elimina siempre `X-Forwarded-Host`.
  - P3: cupo por origen → Turnstile → cupo por correo o RIF.
  - P4: un cambio real de correo anula `email_verified_at` y borra los tokens del correo anterior.
  - P5: HSTS `max-age=86400` sin `includeSubDomains` ni `preload`; redirección a https apagada, sin bucles.
- **Regresión antes/después:** en `8a4ed35`, 736 tests con 7 fallos (los oráculos). En `cb3fff4`, Sec03 37/37 (run
  `20261111T180241Z-cb3fff4ebac0`) y suite completa **753 tests, 8030 aserciones, OK** (run
  `20261111T180350Z-cb3fff4ebac0`); los oráculos no se modificaron. Staging 200 antes y después.
- **Efectos legítimos:** un Host distinto de los configurados recibe 404; un correo nuevo debe verificarse; las
  respuestas https llevan HSTS de 1 día.
- **Límites de la evidencia:** sin despliegue. NO VERIFICADO en vivo: llegada de `CF-Connecting-IP` y de
  `X-Forwarded-Proto` por el túnel, borde de Cloudflare y producción. El Host que entrega el túnel se verificó en solo
  lectura (sin `httpHostHeader`; logs con el host canónico).
- **Despliegue:** ninguno. Infraestructura (Cloudflare, nginx, túnel): sin cambios; plan en la tarea, §5.

### SEC-03 / TASK-0018 — ciclo de corrección P2, P4 y P5 (6819392897) — 2026-11-12

- **Veredicto:** P2 **PASS** en código (tránsito real de cabeceras NO VERIFICADO; gate conservado); P4 **PASS**; P5 **PASS
  de código** (F-SEC03-06 sigue pendiente en parte). Detalle: tarea, addendum del 2026-11-12.
- **Defectos demostrados y corregidos (`22800c1`):**
  - P2: el valor por defecto `172.16.0.0/12` confiaba en las redes bridge de otros sitios del host (4 redes; 5
    contenedores ajenos, en solo lectura). Ahora es la lista exacta: loopback y el gateway de la red de la aplicación.
  - P4: los tokens del correo anterior se borraban después del `UPDATE`. Ahora se borran antes: si falla, no cambia
    nada.
- **Prueba de que eran defectos:** con el código anterior, las dos pruebas nuevas correspondientes fallan (run
  `20261112T092358Z-a45af63e14a3`, rama temporal borrada); con la corrección, pasan.
- **Regresión:** `Sec03` 51/51 (los 7 oráculos y 13 controles de TASK-0017 sin modificar); enfocada 111/111; suite
  completa **767 tests, 8102 aserciones, OK** (run `20261112T091647Z-9d0c24d613fb`). Staging 200 antes y después.
- **Despliegue:** ninguno. Infraestructura: sin cambios; solo inspección de solo lectura (redes de Docker, nginx,
  ingreso del túnel).

### SEC-03 / TASK-0019 — despliegue de P1–P5 en staging (6820224859) — 2026-11-12

- **Antecedente:** TASK-0018 APPROVED como implementación aislada (`6820214362`).
- **Preflight:**
  - respaldo en el servidor (`.env`, imagen `pre-sec03-b048df0` y `pg_dump` de `public` con sha256);
  - restauración ensayada en un contenedor aislado: 88/88 tablas y 41 499 filas, con **0 diferencias**. Una FK
    preexistente con 2 filas huérfanas (`contact_empresa_contact_id_foreign`), sin acción;
  - regresión del candidato `9d0c24d`: 51/51, 111/111 y **767/767** (8102 aserciones).
- **Despliegue:** *fast-forward* `b048df0..9d0c24d`, workflow `43196418453` success; verificado en el servidor.
- **Configuración:** solo `HSTS_MAX_AGE` en el `.env` de staging (0 durante los gates, luego 86400). Cloudflare, nginx y
  el túnel sin cambios. HSTS de Cloudflare no se tocó: es de alcance de zona.
- **Verificación efectiva:**
  - cadena de cabeceras observada con peticiones inocuas marcadas (nginx y FastCGI): `CF-Connecting-IP` = cliente,
    `X-Forwarded-Proto: https`, par = gateway, sin `X-Forwarded-Host`;
  - `ip()` efectiva = cliente; enlaces con el host de staging;
  - Gates A–D PASS;
  - 0 respuestas 5xx y 0 errores nuevos tras el despliegue.
- **Sin verificar en staging:** rechazo de Host ajeno y suplantación de cabeceras a través del borde (no autorizados), y
  flujos de correo y RIF (sin buzones sintéticos aprobados).
- **Rollback:** preparado (servidor y `git revert`), no fue necesario.
- **Detalle:** [0019-sec03-staging-deploy.md](../orquestador/tasks/0019-sec03-staging-deploy.md).

### SEC-01 / TASK-0021 — R3+R4+R4-01 en staging (6823247114) — 2026-11-12

- **SHAs:** BASE `9d0c24d` (staging antes); TESTED = REVIEW = desplegado `71e8a6d`; workflow `43220318614` success; fails-before de la revisión `c5a9f8e` (`audit/task-0021-r401-fails-before`).
- **Candidato:** 12 commits de código y pruebas de R3/R4/R4-01 con `cherry-pick -x`, más 2 commits de pruebas de la tarea. R4/R4-01 idénticos a `24413d5`; en archivos compartidos, solo líneas añadidas. SEC-03, configuración, rutas, migraciones y dependencias sin cambios.
- **Revisión R4-01:** PASS (código contra Shield 3.9.10 y Filament 3.3.55; 7+5 pruebas existentes y 2 nuevas a la escala histórica, con fails-before).
- **Preflight:** respaldo `/root/backups/sec01-t21-20261112T123419Z` (sha256 verificado); restauración aislada con 0 diferencias en 88 tablas y línea base de permisos idéntica; FK `contact_empresa_contact_id_foreign` recreada `NOT VALID` (2 huérfanas, sin modificar); suite completa 794/794; rollback a la imagen `pre-sec01-9d0c24d` preparado y no usado.
- **Staging:** salud y SEC-03 sin cambios (HSTS `max-age=86400`, 301 a https, Host ajeno 400, 0 respuestas 5xx). La validación sintética en vivo es una transacción revertida: ocultos conservados 60/60 (antes 0/60), visible exacto, forjados rechazados, no `super_admin` rechazado, identidad privilegiada protegida, R3 11/11.
- **Exposición cerrada:** el editor anterior habría quitado 116 permisos a `super_admin`, 35 al rol de afiliados (324 usuarios) y 1 a `reportes` al guardarlos.
- **Residual:** F-SEC01-T21-01 (§4). Detalle en [`tasks/0021`](../orquestador/tasks/0021-sec01-r3-r4-r401-staging-deploy.md).

### SEC-01 / TASK-0022 — F-SEC01-T21-01 y recuperación de permisos (6829151868) — 2026-11-12

- **SHAs:** BASE `71e8a6d` (staging, sin cambios); TESTED `4bdec36`; REVIEW `270e07d` (más la ayuda); fails-before `23b5c0d` (`audit/task-0022-role-section-fails-before`). Sin push a la rama runtime ni despliegue.
- **Causa:** Shield deriva la clave del recurso de su clase, después del último `Resources\`; el recurso publicado en `App\Filament\Resources\Shield\` da `shield::role`.
- **Corrección:**
  - la clave queda fijada en `role` con el *hook* de Shield, aplicado en cada resolución de su servicio *scoped*; los demás recursos conservan la clave por defecto;
  - la sección «Role» muestra los 6 `*_role` en solo lectura, porque un campo deshabilitado no es editable. Así el editor no puede darlos a otro rol, lo que deshace la contención de R4, ni quitárselos a `super_admin`, lo que lo bloquearía.
- **Pruebas:** 4 nuevas. Falla antes la de las seis casillas. Las regresiones de R4/R4-01/TASK-0021 dan 28/28 y la suite completa 798/798, SEC-03 incluida.
- **Recuperación, solo análisis:**
  - de 128 vínculos, el defecto retiró 109 ocultos en el rol sin usuarios; los otros 19 eran visibles y se desmarcaron (7 en el rol con 1 usuario y 12 en el rol sin usuarios);
  - todos siguen faltando;
  - `super_admin`, afiliados y `reportes` no perdieron nada;
  - los 13 de administración de roles no se restauran nunca, ni los 42 sin referencia.
  - **Recomendación:** como máximo 1 vínculo (`view_any_user` en el rol con 1 usuario), con un script revisado (solo se ejecutó su modo `check`: `to_insert=1`) o a mano en el editor. La lista exacta está en las notas restringidas. Toda escritura requiere autorización específica.
- **Detalle:** [`tasks/0022`](../orquestador/tasks/0022-sec01-t21-01-role-section-and-permission-recovery.md).

### SEC-01 / TASK-0023 — despliegue en staging de F-SEC01-T21-01 (6831152066) — 2026-11-12

- **SHAs:** BASE `71e8a6d`; TESTED = REVIEW = desplegado `270e07d`; workflow `43274064073` success; push *fast-forward*, sin *force*.
- **Preflight:**
  - respaldo `/root/backups/sec01-t23-20261112T174040Z` (sha256 verificado), imagen `pre-t23-71e8a6d`;
  - restauración aislada: 88 tablas sin diferencias y roles idénticos; FK `contact_empresa_contact_id_foreign` con `NOT VALID` (2 huérfanas, sin modificar);
  - regresión 93/93 y suite 798/798;
  - rollback preparado, no usado.
- **Staging:** salud sin cambios (0 respuestas 5xx, 0 errores nuevos, rutas en 200). HSTS `max-age=86400` sin `includeSubDomains` ni `preload`; 301 a https; Host ajeno 400.
- **Validación sintética en vivo** (transacción revertida):
  - clave `role`; 0 casillas `shield::`;
  - sección «Role» con los 6 `*_role` reales deshabilitados, marcados en `super_admin`;
  - ocultos conservados y visible exacto;
  - forjados y `*_role` no concedidos; no `super_admin` rechazado.
- **Datos reales:** 5 roles idénticos a la línea base del respaldo; totales sin cambios. R1/R2 sin tocar; las 6 filas `*_shield::role` siguen sin vínculos y no se borraron.
- **Detalle:** [`tasks/0023`](../orquestador/tasks/0023-sec01-t21-01-staging-deploy.md).
- **Veredicto 2026-11-13:** APPROVED con reservas (`6848907151`); las reservas pasan a TASK-0024.

### SEC-01 / TASK-0024 — diagnóstico de las reservas de TASK-0023 (6848913937, decisiones 6849259532) — 2026-11-13

- **Alcance:** solo análisis. Staging `270e07d` verificado y sin escrituras; tres sondas `READ ONLY` y una petición sin clave a
  la Data API.
- **Cierres:** R-01 (UAT confirmada por el propietario sobre `270e07d`; la UAT previa `6829143372` fue sobre `71e8a6d`; sin
  afirmar guardado de roles reales), R-02 (no restaurar) y R-03 (conservar las 6 filas; riesgo aceptado).
- **R-04:** 2 filas de `contact_empresa` con contacto inexistente, invisibles en la aplicación. Probable residuo de la importación
  desde MySQL: FK viva validada y triggers activos, y `postgres` ya no puede saltarse la FK.
  - Copia aislada: borrado guardado de las 2 filas, FK validada, segunda restauración con exit 0 y 0 errores (101 FK válidas),
    87 tablas idénticas, rollback exacto.
  - Pendiente de autorización que nombre las filas (nota restringida) y la operación.
- **R-05:** inventario de escritores. El límite de R4 (query builder, `saveQuietly`, `withoutEvents`, `DB::table`) es una
  capacidad interna: la prueba lo confirma y el inventario estático encuentra 0 usos en `app/` y `routes/`. Se registran
  F-SEC01-T24-01 y F-SEC01-T24-02.
- **Base de datos:** la aplicación conecta como `postgres` (propietario y BYPASSRLS, sin superusuario). RLS está activo en
  88/88 tablas, sin políticas.
- **R-06:** R3-02/R3-03, en tarea independiente posterior (candidato aislado, sin desplegar); el resto de SEC-01, abierto.
- **Detalle:** [`SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md`](SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md) y
  [`tasks/0024`](../orquestador/tasks/0024-sec01-t23-reservations-diagnosis.md).
- **Veredicto 2026-11-13:** APPROVED, solo diagnóstico y planificación (`6850519341`, ratificado en `6850596236`).

### SEC-01 / TASK-0025 — corrección candidata de F-SEC01-T24-02 (6850596236) — 2026-11-13

- **Alcance:** análisis, migración y pruebas en entornos desechables. En la BD compartida y en staging no hubo DDL, cambios
  de grants ni despliegue.
- **Inventario (catálogo):**
  - 13 vistas de `postgres`, sin `security_invoker`; `anon`, `authenticated` y `service_role` con todos los privilegios;
  - sin vistas anidadas, funciones ni vistas actualizables; 21 tablas base con RLS sin políticas;
  - `pg_graphql` ausente; la vía de acceso es REST;
  - solo `catalogoView` tiene datos personales (contactos);
  - consumidor único: Laravel como propietario. Ningún flujo de n8n las lee en Supabase.
- **Clon REST aislado** (PostgREST v12.2.3, credenciales sintéticas, 0 filas reales):
  - antes: `anon` lee por REST las filas sintéticas de 6 vistas, incluido el contacto en `catalogoView`, mientras `/contacts`
    le devuelve vacío;
  - después: 401 (`anon`) y 403 (`authenticated`) en las 13; `service_role` y la huella del propietario sin cambios;
  - `down()` restaura exactamente.
- **Candidato:** `fix/sec-01-t25-public-views-hardening`, fails-before `56375f5` y TESTED/REVIEW `6e7f525`.
- **Gate:** el despliegue ejecuta `migrate --force` sobre la BD compartida. El procedimiento (respaldo, verificación,
  rollback y monitorización) está en [`SEC01_TASK0025_VISTAS_PUBLIC.md`](SEC01_TASK0025_VISTAS_PUBLIC.md) §7.

## 6. Cobertura pendiente

R1 informó 131 rutas inventariadas y 792 solicitudes sobre 88 entradas con nueve actores. Son conteos de inventario y caracterización, no porcentaje global de seguridad.

| Superficie | Evidencia R1 | Pendiente |
|---|---|---|
| Relation managers | ~~3 de 23 (sin cambio en R2)~~ → **R3 (2026-10-21): 10 probados** (R1: Contacts, Users, Chambers; R3: Assets, Management, Experiences, Presence, Sustainabilities, Services, TaxonomyCategories). **Discrepancia de denominador:** el inventario de R1 dice 23; el recuento en `e95169c` da **24** registros activos (recurso × gestor; Empresa 10 + 14 en otros recursos) | ~~14 por el recuento (13 por el denominador anterior)~~ → **resuelto en R4: el denominador es 24** gestores (pares recurso × gestor). El inventario de R1 mostraba 23 entradas porque `CountryResource` agrupa States y Cities en un `RelationGroup` contado como una sola. **Restan 14**, todos fuera de Empresa |
| Búsqueda global | ~~1 de 13 recursos (sin cambio en R2)~~ → **R3:** 13 en inventario, de los que **12 son buscables de verdad** (RoleResource está desactivado por la configuración de Shield, y ahora se prueba). Probados: Empresa (R1), Customer y TaxonomyCandidateTerm (R3), más Role como no buscable | **9**. Desglose (R4): **12** recursos con búsqueda habilitada; **3** con búsqueda habilitada probada (Empresa, Customer, TaxonomyCandidateTerm); **1** deshabilitado probado como no buscable (Role, que no cuenta como ejecución habilitada); **9** habilitados restantes |
| Actor administrador | Rol sintético sin conjunto explícito representativo | Expectativas y capacidades efectivas de fixture |
| Acciones Livewire / exportaciones generales | Inventario y pruebas parciales | Ejecución negativa y positiva por operación |
| Registro, perfil, roles, verificación y recuperación | Comprobaciones parciales | Pruebas específicas de escritores y flujos |
| Archivos / rutas / cargas / temporales | Inventario parcial | Sinks, límites de directorios y permisos |
| Caché previa y configuración efectiva | Sin cierre acreditado | Evidencia y decisión |
| Dependencias e integraciones | Sin cierre | SEC-04 |

## 7. Regla de actualización obligatoria

En CADA ronda de seguridad, el desarrollador actualiza este documento en su rama de entrega:

1. Añadir una fila de cronología con fecha, alcance, comentario, BASE_SHA, TESTED_HEAD, REVIEW_HEAD y estado de revisión.
2. Añadir hallazgos con ID estable; no renumerar ni borrar. Referenciar duplicados a su causa original.
3. Separar **sospechado / confirmado / corregido en candidato / aceptado / desplegado y verificado / cerrado / reabierto / riesgo aceptado**. Registrar por separado la validación, revisión y aplicación al entorno.
4. Para cada corrección: cambio resumido, commit, regresión antes/después, efectos legítimos y límites de evidencia.
5. Tras despliegue autorizado, añadir SHA real, workflow, fecha, comprobación posterior y riesgos que permanecen. Si no hubo despliegue, mantenerlo explícito.
6. Actualizar denominadores y pendientes. Los errores conocidos deben contarse y tener referencia; nunca desaparecer de la matriz.
7. Preservar la cronología anterior. Retirar conclusiones incorrectas mediante una nota fechada, no sobrescribiéndolas sin rastro.
8. Mantener un único registro canónico: llevar este documento a la rama sucesora mediante integración o copia revisada de sus cambios documentales, sin promover runtime ajeno ni hacer force-push. No modificar un candidato ya aceptado; nueva documentación produce otro SHA claramente identificado.
9. En el comentario de entrega añadir `SECURITY_REGISTER: docs/security/SECURITY_STATUS.md` y los IDs modificados.
10. Mantener la entrada de reanudación corta: enlace al registro; no copiar todo el registro en SESSION_RESUME.

## 8. Confidencialidad y cierre

Este repositorio es público. No incluir datos reales, secretos, mapas de permisos reales, payloads ni pasos de explotación de problemas no corregidos. Conservar la evidencia sensible en almacenamiento restringido fuera de git y webroot; indicar un canal de entrega seguro sin asumir acceso entre ejecutores.
Una búsqueda por patrones sin coincidencias no acredita que un archivo sea inocuo.
El cierre del programa requiere alcance acordado, pruebas y decisiones explícitas sobre lo pendiente. Gerencia se activa mediante instrucción posterior; no se inicia por actualizar este informe.

# TASK-0024 — SEC-01: diagnóstico y plan de cierre de las reservas de TASK-0023 (solo análisis)

| Campo | Valor |
|---|---|
| Fuente | Issue #2, asignación [`6848913937`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848913937) (2026-11-13 09:14Z), actualizada por las decisiones del propietario en [`6849259532`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6849259532) (09:31Z). Veredicto previo: [`6848907151`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848907151) (TASK-0023 APPROVED con reservas) |
| ID | TASK-0024: siguiente libre verificado (`tasks/`, `current_task.md`, ramas remotas, `git log --all`, handoff). `6848913937` no estaba materializada; `6849259532` pide no duplicarla, así que es **una sola tarea** |
| Estado | **IN_PROGRESS** (2026-11-13 ~09:50Z) |
| Tipo | Diagnóstico y propuesta. **No** asigna implementación, limpieza de BD ni despliegue. No reabre TASK-0023 |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (documental). Pruebas aisladas en rama `audit/` aparte si hacen falta; nunca en la rama de runtime |
| Staging (runtime) | `270e07dcda8c61840c569b5c85cbad471ce9bf82`, a verificar en remoto y servidor por lectura |

## Alcance (según `6848913937` con las decisiones de `6849259532`)

| Reserva | Decisión del propietario (`6849259532`) | Trabajo de esta tarea |
|---|---|---|
| R-01 UAT visual del editor | **OWNER-CONFIRMED PASS** (confirmación del 2026-11-13; UAT previa `6829143372` sobre `71e8a6d`) | Registrar ambas evidencias por separado y cerrar solo en el alcance declarado, sin afirmar guardado de roles reales |
| R-02 R1 `view_any_user` | **Cerrada:** no restaurar; R1/R2 no se tocan | Confirmar estado por lectura y registrar el cierre |
| R-03 seis `*_shield::role` inertes | **Cerrada:** aceptación del riesgo residual; conservar las filas | Confirmar por lectura 0 vínculos y 0 referencias en código; registrar la aceptación |
| R-04 FK `contact_empresa_contact_id_foreign` y 2 huérfanas | **Remediar**, previa revisión del plan exacto (gate humano aparte) | Inventario de solo lectura, causa probable, alternativas sin pérdida de información, demostración en copia aislada (FK validada, restauración sin errores, conteos, checksum, integridad semántica, reversibilidad) |
| R-05 límites de R4 | **Verificar** | Inventario de escritores y puntos de entrada de roles/permisos, autorizaciones en servidor, privilegios de BD, pruebas adversariales con datos sintéticos aislados; vulnerabilidad explotable frente a capacidad interna |
| R-06 SEC-01 | Corregir primero F-SEC01-R3-02 y F-SEC01-R3-03, como **tarea independiente** tras revisar esta entrega | Solo enumerar pendientes y proponer el alcance; **no** se materializa ni implementa en este ciclo |

**Prohibido:** despliegue, push a la rama de runtime, merge a `main`, producción, Cloudflare/nginx/túnel, HSTS (sigue
`max-age=86400`), n8n, taxonomía, secretos, escrituras en roles/permisos/usuarios reales, borrar filas inertes, cambios de
datos, FK, constraints o migraciones en staging, y pruebas que alteren el estado vivo.

**Entrega:** matriz de reservas (evidencia, severidad, impacto, probabilidad, cierre, dependencias, coste, riesgos, plan
mínimo, pruebas de aceptación, gate humano), prioridades P0/P1/P2, plan por fases, qué no hacer y la autorización concreta
que necesita cada cambio posterior. READY_FOR_REVIEW o BLOCKED; STOP.

## Anexo: texto íntegro de la fuente

<details><summary><code>6848913937</code> (asignación de diagnóstico)</summary>

# NUEVA ASIGNACIÓN — Diagnóstico y plan de cierre de reservas de TASK-0023 (SOLO ANÁLISIS)

**Fuente:** petición expresa del propietario de trabajar las reservas para cerrarlas; veredicto [6848907151](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848907151); entrega TASK-0023 [6831635341](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6831635341). Esta es **una tarea nueva de diagnóstico y propuesta**, NO reabre TASK-0023 y **NO asigna implementación, limpieza de BD ni despliegue**.

## Procedimiento
1. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `SESSION_RESUME.md`, `current_task.md`, la TASK-0023 y referencias directas estrictamente necesarias. Comprobar `git status -sb`, rama, HEAD, divergencias y cambios locales antes de editar. No descartar nada; worktree aislado cuando corresponda.
2. Verificar el siguiente ID libre real y materializar **nueva TASK consecutiva** de análisis; conservar TASK-0023 APPROVED con reservas. No inventar/reutilizar ID. Staging reportado `270e07dcda8c61840c569b5c85cbad471ce9bf82`: verificar en remoto y servidor en modo lectura.
3. Elaborar **matriz de reservas** con ID/fuente, evidencia verificable, severidad, impacto actual, probabilidad, condición de cierre, dependencias, coste estimado, riesgos, plan mínimo, pruebas de aceptación y si requiere gate humano. Diferenciar defectos de TASK-0023, deuda preexistente, mejoras y UAT.

## Diagnósticos acotados (sin escrituras reales)
A. **UAT editor:** preparar checklist humano breve para Roles → Editar → sección «Role»: 6 `*_role` reales, deshabilitados, sin `*_shield::role`; NO guardar roles reales. El desarrollador no puede declarar PASS de UAT humana sin la ejecución del propietario.
B. **R1/R2:** confirmar solo por lectura estado de `view_any_user` y efecto funcional; documentar guía manual precisa y reversión para el propietario, pero **NO restaurar ni tocar R1/R2**. No acceder ni divulgar identidades innecesarias.
C. **6 permisos inertes:** confirmar ausencia de vínculos y referencias en código y evaluar si dejarlos es inocuo; presentar comparación entre **no hacer nada** y borrado controlado (respaldo, conteos, rollback), **sin DELETE**. Si no hay riesgo, recomendar conservarlos y cerrar la reserva por aceptación explícita de riesgo.
D. **FK y 2 huérfanas:** identificar claves y causa solo lectura, determinar si son registros legítimos, obsoletos o una inconsistencia de importación; valorar alternativas de remediación sin pérdida de información (reconectar a padre válido, archivar o eliminar solo con autorización específica), impacto en backups/restore, pruebas y rollback. **NO modificar datos, FK, constraints ni migraciones**.
E. **R4 y otros SEC-01:** revisar fuentes y cobertura concreta de guardas para SQL directo, `saveQuietly`, `withoutEvents`, escrituras masivas y endpoints de relación/búsqueda. Clasificar vulnerabilidad explotable vs limitación interna, recomendar controles proporcionales. Enumerar R3-02/R3-03/F-03/F-06 como pendientes **sin absorber su implementación** ni ampliar la tarea.

## Entrega y restricciones
- Documento de diagnóstico y propuesta con prioridades P0/P1/P2, cierre recomendado por reserva (evidencia suficiente / aceptar riesgo / necesita UAT / requiere tarea nueva), plan de fases y coste aproximado; recomendaciones explícitas de **qué no hacer**. Para cualquier cambio posterior, identificar la autorización concreta requerida.
- Registrar tarea y checkpoints `docs/orquestador/`, `docs/security/SECURITY_STATUS.md` si procede y handoff bajo reglas de `audit/**`; conservar historia y cambios locales.
- **Prohibido:** deploy, push a runtime, merge a `main`, producción, Cloudflare/nginx/HSTS (mantener `max-age=86400`), n8n, taxonomía, secretos, escrituras en permisos/roles/usuarios reales, limpieza de filas, cambios de datos, migraciones y pruebas que alteren estado vivo. Lecturas de staging solo cuando necesarias y seguras.
- Publicar **READY_FOR_REVIEW** con HEAD y matriz de decisión o **BLOCKED** con causa y **STOP**. No iniciar correcciones por iniciativa propia.

**Objetivo:** cerrar documentalmente lo que no requiere cambios y preparar decisiones informadas y separadas para cualquier remediación real.

</details>

<details><summary><code>6849259532</code> (decisiones del propietario)</summary>

# DECISIÓN DEL PROPIETARIO — Cierre de reservas TASK-0023 y secuencia de SEC-01

**Fuente:** decisiones expresas del propietario en el chat del 2026-11-13, posteriores al veredicto [6848907151](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848907151) y a la asignación de diagnóstico [6848913937](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848913937). **No reabrir TASK-0023.** Este comentario conserva el antecedente y actualiza las decisiones, sin fingir que ya se materializaron en archivos.

## Matriz de decisiones
- **R-01 UAT visual:** **OWNER-CONFIRMED PASS**. El propietario confirma ahora que verificó el editor; consta además UAT previa del editor en [6829143372](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6829143372), aunque esa fue sobre el runtime anterior. Registrar ambas evidencias separadas. Cerrar reserva de UAT visual **solo en el alcance declarado**, sin afirmar guardado de roles reales.
- **R-02 R1 `view_any_user`: CERRADA por decisión expresa de NO restaurar.** R1/R2 no se tocan; si surge necesidad futura, nueva autorización.
- **R-03 seis permisos `*_shield::role` inertes: CERRADA por aceptación expresa de riesgo residual.** Conservar las filas; no autorizar DELETE ni limpieza.
- **R-04 dos huérfanas de `contact_empresa` / FK `contact_empresa_contact_id_foreign`: REMEDIAR.** Primero diagnóstico preciso, preservar datos y proponer corrección mínima, backup, validación FK y rollback; **no hay autorización de escritura, borrado, migración ni ALTER sobre BD real en este comentario**. Elevar plan exacto a revisión para gate humano separado.
- **R-05 límites R4:** VERIFICAR escritores alternativos (SQL directo, masivos, `saveQuietly`, `withoutEvents`), controles de BD y rutas alcanzables; distinguir capacidad interna de vulnerabilidad explotable. Solo análisis y pruebas aisladas, sin modificar datos reales.
- **R-06 SEC-01:** el propietario **ACEPTA corregir primero F-SEC01-R3-02 y F-SEC01-R3-03**, ambos reproducidos en aislamiento; **F-SEC01-03/F-SEC01-06, relation managers, búsquedas y demás SEC-01 permanecen abiertos para tareas posteriores**. Se autoriza preparar **candidato de código y pruebas en rama aislada, SIN desplegar**, una vez que el diagnóstico actual quede entregado y revisado; materializar una tarea independiente de implementación conforme al protocolo y verificar el siguiente ID real antes de asignarlo. No extender el alcance a F-03/F-06.

## Instrucciones operativas completas
1. **No duplicar tareas.** La asignación vigente [6848913937](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6848913937) es de diagnóstico. Comprobar si ya fue materializada y su estado; completar esa misma tarea con la matriz actualizada R-01..R-06, evidencia, impacto, prioridad, criterios de cierre y propuestas. **STOP en READY_FOR_REVIEW**, sin iniciar implementación en el mismo ciclo.
2. Antes de cualquier edición: leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md`, tarea activa y referencias directas; `git status -sb`, rama, HEAD, diferencias, worktree. No descartar cambios ni reconstruir estado desde el chat. Verificar staging `270e07dcda8c61840c569b5c85cbad471ce9bf82` solo si hace falta y por lectura.
3. **R-04:** inventario seguro de las dos huérfanas y referencias legítimas, causa probable y alternativas (reconectar padre válido, preservar/archivar, eliminación solo si se autoriza); demostrar en copia aislada cómo conseguir FK validada y restauración sin errores de integridad, con conteos, checksum, integridad semántica y reversibilidad. **No ejecutar remediación en staging** hasta autorización explícita sobre filas y operación.
4. **R-05:** inventariar escritores concretos y puntos de entrada de cambios de roles/permisos; comprobar autorizaciones en servidor y privilegios de BD; pruebas adversariales solo con fixtures sintéticos aislados; clasificar hallazgos y recomendar mitigación proporcional. No afirmar explotación sin reproducción.
5. **R-06 (fase posterior independiente, tras revisión del diagnóstico):** candidato acotado para F-SEC01-R3-02: exigir autorización de escritura propia del módulo en cada acción identificada, incluida ejecución Livewire/endpoint y después de hydrate; denegar payload forjado o acceso revocado. F-SEC01-R3-03: validar en servidor que la categoría vinculada esté activa, incluso con ID forjado y cambios de estado entre montaje y guardado. Usar pruebas fails-before y regresión positiva/negativa con datos sintéticos; no romper flujos legítimos, R3/R4/R4-01, Shield, SEC-03. Suite completa y revisión de diff. **No push a rama runtime ni deploy**. La implementación requiere tarea nueva materializada y su propio handoff; nunca reutilizar TASK-0023 ni la tarea de diagnóstico.
6. Registrar decisiones, evidencia, riesgos aceptados y pendientes en checkpoints versionados `docs/orquestador/`, `docs/security/SECURITY_STATUS.md` y `audit/orchestrator_handoff.json` según reglas; conservar historia. Publicar `READY_FOR_REVIEW` o `BLOCKED` con HEAD, resultados y enlaces. **STOP** tras cada entrega.

**Gates permanentes:** sin escrituras de roles/permisos/usuarios reales, sin borrar filas inertes, sin alterar datos/FK de staging, sin producción, `main`, force-push, n8n, taxonomía, Cloudflare/nginx/túnel, ni HSTS (mantener `max-age=86400`, sin `includeSubDomains` ni `preload`). No iniciar despliegue ni remediación de BD sin aprobación específica posterior.

**Objetivo de cierre:** R-01/R-02/R-03 cerradas por alcance y aceptación del propietario; R-04 remediación pendiente; R-05 verificación pendiente; R-06 primera corrección acotada autorizada solo para candidato aislado, restantes hallazgos abiertos.

</details>

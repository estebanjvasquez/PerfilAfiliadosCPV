# CPV — Punto de reanudación maestro

**Checkpoint:** 2026-10-15, ~09:15 UTC. TASK-0010C está **desplegada en staging** y espera la prueba funcional del propietario.
Las §0 a §6 son la primera lectura. Lo que viene después de la línea «Historia» es el checkpoint del 2026-10-08, que **ya no rige** y se conserva como historia.

> Si algo de aquí contradice GitHub o la base viva, prevalece la evidencia viva. Registrar la discrepancia antes de actuar.
> Las filas marcadas «registrado» son hechos con fecha, **no** estado vivo verificado en esta lectura.

## 0. Indicación para una sesión nueva

> Lee `docs/orquestador/SESSION_RESUME.md` §0 a §6 y solo los comentarios del Issue #2 enlazados en §3. Carga los accesos con `. docs\orquestador\bootstrap_access.ps1 -Quiet;` y, si hace falta, `-Verify`. Verifica la rama, el HEAD y el working tree. No releas el hilo completo ni cargues logs grandes. Ejecuta **solo** la siguiente acción autorizada explícitamente en el Issue #2; si no hay ninguna, espera.

## 1. Estado (registrado el 2026-10-15)

| Tema | Estado |
|---|---|
| Runtime de staging | `feature/upgrade-filament-v3` = **`a5772345ff0a52bd79a5b29ab063991960109271`**. Desplegado el 2026-10-15 09:07Z, run `38612101908` **success**. Su código es el TESTED_HEAD de C (`349ea61`). |
| TASK-0010A/B + test-readiness | Integradas, aceptadas y desplegadas el 2026-10-14 (`9b447eb`). |
| TASK-0010C | Aceptada (`6188413270`) y desplegada con la autorización `6188477132`. **DEPLOYED_FOR_OWNER_REVIEW — MANUAL**. Espera la prueba funcional del propietario. |
| Solicitud #1 | `pending_review`, aviso `failed` / `recipient_not_configured`. La migración le agregó **una fila `historical`** en el historial. Su contenido no cambió: misma huella md5, verificada a las 09:08Z. **No hay reintento autorizado.** |
| Correo | `MAIL_MAILER=log` y **sin destinatario** (registrado 2026-10-15 09:08Z). No se ha enviado ningún correo real. |
| Automatización | El ejecutor autónomo está **suspendido** desde el 2026-10-14. No hay procesos de IA en segundo plano ni sondeos programados. |

## 2. SHAs y ramas

- **Desplegado / runtime:** `a5772345ff0a52bd79a5b29ab063991960109271` (= REVIEW_HEAD aceptado de C; el delta frente a TESTED_HEAD es solo `audit/**`).
- **C, TESTED_HEAD:** `349ea61078fe5b6b88abc0a90333958c0ea9f220` (`OK 481 tests, 2869 assertions`; navegador 55/55).
- **Rama de C:** `feature/task-0010c-request-operations`. El commit de checkpoint es el que introduce este texto; su SHA está en el comentario de entrega.
- **Integración 0010:** `feature/integration-candidate-0010` @ `8a7664f` (incluye el seguimiento de la #1).
- **Arnés aislado:** `infra/contabo-test-runner` @ `246b0d0`.
- **Imágenes de recuperación en el VPS:**
  - `perfilafiliados-app:pre-0010c-9b447eb`: runtime anterior a C.
  - `perfilafiliados-app:pre-0010-004d24e`: runtime anterior a 0010.

## 3. Evidencia (enlazar, no copiar)

- Informe de C, con el despliegue y la guía de prueba del propietario: `audit/task_0010c_request_operations_2026-10-15.md`.
- Despliegue de C (preflight, verificación posterior y prueba de humo): `audit/task_0010c/staging_deploy_2026-10-15/`.
- Despliegue de 0010 y seguimiento: `audit/integration_candidate_0010_staging_deploy_2026-10-1{4,5}*.md`, en la rama de integración.
- Issue #2:
  - aceptación de C y requisitos de checkpoint: `6188413270`;
  - autorización de despliegue: `6188477132`;
  - entrega de C: `6188344669`.

## 4. Siguiente acción

- **Propietario:** probar las pantallas según la guía del informe de C («Guía de prueba»).
- **Agente:** **ninguna** hasta una nueva instrucción. Cualquier código nuevo exige una ronda manual completa:
  1. rama propia;
  2. entorno aislado;
  3. revisión;
  4. preflight de solo lectura;
  5. autorización explícita antes de tocar `feature/upgrade-filament-v3`.
- **Decisiones del propietario pendientes:**
  - destinatario y SMTP (proveedor, destinatario y aprovisionamiento seguro);
  - si se reintenta la #1.

## 5. Pendientes y limitaciones (no arrancan solos)

- **`EmpresaPgsql` no compila** (fatal latente desde `a5265ce`). Cargar esa clase es un error fatal. Necesita su tarea propia.
- **Reserva del aviso.** Mientras está vigente, la reserva de 10 minutos impide envíos concurrentes. **No garantiza un único envío** si el proceso cae después de que el servidor de correo aceptó el mensaje, si el transporte falla de forma ambigua, o si la reserva expira con un envío a medias. Hay que revisarlo al activar SMTP. La ayuda de administración dice «se rechaza para no enviar dos avisos»; ese texto se precisa en la próxima ronda de código, sin redesplegar ahora.
- Los avisos `simulated` no se pueden reintentar con el contrato actual.
- La concurrencia está probada a nivel de la reserva, no con dos procesos reales.
- El validador del handoff, del loop retirado, falla en la regla histórica `6080004466`. No bloquea nada.

## 6. Accesos (solo nombres; nunca valores)

| Capacidad | Mecanismo establecido | Verificación barata | 2026-10-15 |
|---|---|---|---|
| `git fetch`/`push` | Git Credential Manager (Windows) | `git ls-remote origin` | verificado (push 09:06Z) |
| Leer el Issue #2 | API anónima | `bootstrap_access.ps1 -Verify` | verificado |
| Escribir en el Issue #2 | `CPV_GITHUB_TOKEN` en `%USERPROFILE%\.cpv\credentials.env`, fuera de git, cargado con el bootstrap; publicar con `docs\orquestador\post_issue_comment.ps1 -BodyFile` | `-Verify` muestra SET/MISSING | verificado |
| SSH al VPS de staging | clave OpenSSH en `%USERPROFILE%\.ssh\` (el nombre está en la memoria privada local del agente) | `ssh … true` | verificado |
| Entorno aislado (PHPUnit y navegador) | runner en el VPS (ruta y raíz en la memoria privada) | una corrida `--skip-suite` | verificado (corridas del 2026-10-15) |
| Despliegue | workflow «Deploy a Contabo» con los secretos del repo | estado del run en la API | verificado (`38612101908`) |
| Credenciales del ejecutor del servidor | quedan **en el servidor**; no se copian | — | no revisado (ejecutor suspendido) |

- Las variables exportadas **no sobreviven** a una shell nueva: cada comando que las necesite empieza con `. docs\orquestador\bootstrap_access.ps1 -Quiet;`.
- El clasificador de permisos de la sesión puede rechazar un push a `feature/upgrade-filament-v3`. En ese caso lo ejecuta el propietario. **Un rechazo de permisos se reporta; no se rodea.**
- No crear, rotar, revelar ni reaprovisionar secretos sin una instrucción explícita.

---

# Historia: checkpoint del 2026-10-08 (superado; se conserva)

## (Encabezado original del 2026-10-08)

**Fecha del checkpoint:** 2026-10-08  
**Proyecto:** PerfilAfiliadosCPV / CIRA / Taxonomía CPV  
**Repositorio principal:** `estebanjvasquez/PerfilAfiliadosCPV`  
**Rama activa:** `feature/upgrade-filament-v3`  
**HEAD aceptado al crear este checkpoint:** `bdfefa97cda9cc812ae86fb4080989ccbb2182f2`  
**Runtime Laravel desplegado en staging:** `004d24e98159bf152c741bd9f0ee698b43139d87`  
**Worker repo:** `estebanjvasquez/perfilafiliados-mcp`  
**Worker source HEAD conocido:** `29de993c12fbdadf0577c3630cc07611af020a46`

> Este archivo es el punto de entrada para retomar el proyecto sin releer toda la conversación ni todo
> el Issue #2. Si cualquier dato de aquí contradice GitHub o la base viva, prevalece la evidencia viva y
> debe registrarse la discrepancia antes de seguir.

---

## 1. Estado actual

### TASK-0007 — APPLY atómico de taxonomía

**CLOSED / PASS.** Cierre formal del orquestador: Issue #2 comentario `6035642794`.

Resultado publicado en staging:
- 12 propuestas `APPLIED`
- 3 propuestas históricas `SUPERSEDED`
- 0 `PENDING_APPLY`
- 0 `ABORTED`
- 10 candidate links = 3 `published` + 7 `context_required`
- 2 concept relations = 2 `rejected`
- canonical concepts = 82
- TERM→CONCEPT = 145
- TERM→CPV = 9.749
- regresión congelada = 32/32, 288 campos, 0 diffs
- targeted E2E `pipeline` / `refinery` / `refinería` = PASS

No ejecutar otro APPLY sin una nueva autorización explícita.

### TASK-0008 — UAT cliente + revisión taxonómica del pasante

**CLOSED / PASS.** Cierre formal del orquestador: Issue #2 comentario `6041102746`.

Paquete aceptado en `docs/uat/`:
1. `README.md`
2. `GUIA_PRUEBAS_BUSCADOR_CLIENTE.md`
3. `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv`
4. `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.md`
5. `GUIA_CLASIFICACION_RESULTADOS_UAT.md`
6. `RESUMEN_UAT_CLIENTE_TEMPLATE.md`
7. `GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`
8. `FORMATO_REVISION_TAXONOMICA_PASANTE.csv`
9. `FORMATO_REVISION_TAXONOMICA_PASANTE.md`
10. `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`

**Estado operativo actual:** esperando dos entradas externas:
- resultados de la UAT del buscador por parte del cliente;
- primer lote de calibración del pasante: 30–50 relaciones, estrictamente REVIEW-ONLY.

El pasante NO está autorizado a usar `Aprobar`, `Rechazar`, `Editar`, `Eliminar` ni acciones
masivas durante la calibración. Sus cinco decisiones se registran sólo en la planilla.

### TASK-0010 — solicitud de categoría + manual en línea

**AUTORIZADA PERO EN ESPERA — NO INICIADA.** Autorización: `6079956985`. Espera registrada en la
revisión `6084707259` y reafirmada en `6085642297`.

**No entrar al loop por estas dos subtareas.** La instrucción de §8 de entrar directamente al loop
cuando hay una tarea autorizada **no aplica** acá: el propietario frenó TASK-0010A/B hasta que la
infraestructura de orquestación funcione y pase revisión. Hace falta revisión PASS **más** un record
explícito del propietario que levante la espera; ninguno de los dos existe todavía.

### Trabajo activo: infraestructura de orquestación

Autorizada en `6085642297`, contrato en el repo privado `estebanjvasquez/SISTEG-Agent-Orchestrator`,
`docs/CPV_EXECUTOR_CONTABO_CONTRACT.md`. Dos workstreams: ejecutor persistente en el VPS de Contabo
(systemd, estado durable, revisión por SHA exacto, write-back autenticado, recuperación tras caída) y
PHPUnit aislado en el mismo servidor con PostgreSQL desechable.

---

## 2. Próximo trabajo cuando llegue feedback

No abrir una corrección de búsqueda a ciegas.

### Si llega la planilla UAT del cliente

Abrir **TASK-0009 — UAT TRIAGE**:
- clasificar cada observación;
- separar `FALSO_POSITIVO`, `FALSO_NEGATIVO`, `RANKING`, `TERMINO_TAXONOMIA`,
  `MAPEO_CPV`, `DATOS_EMPRESA`, `EVIDENCIA_WEB_FALTANTE`, `CONSULTA_AMBIGUA`,
  `UI_USABILIDAD`, `OTRO`;
- no corregir nada en la misma ronda de diagnóstico;
- producir backlog priorizado y resumen de aceptación.

### Si llega el lote del pasante

Revisar primero la calibración:
- comparar `Decision` vs `Decision_Final`;
- excluir casos genuinamente ambiguos del denominador;
- calcular tasa de coincidencia;
- referencia de calidad: ≥90% en casos no ambiguos;
- buscar patrones de sobre-aprobación;
- registrar términos ambiguos y categorías faltantes.

Sólo después, si el propietario lo autoriza, abrir una tarea separada para permisos operativos
controlados del pasante. TASK-0008 NO concede esos permisos.

### Trabajo posterior ya identificado

- ampliar cobertura TERM→CPV gobernada;
- Fase E: crawler de contenido web de empresas;
- Fase F: integración crawler → evidencia/taxonomía;
- Fases G/H: refinamiento retrieval híbrido / ranking sólo con evidencia real;
- Fase I: benchmark y gate de producción.

---

## 3. Estado de datos que debe verificarse al reanudar

Último estado aceptado:

TERM→CPV:
- total: 9.749
- approved: 212
- candidate: 17
- needs_review: 9.282
- deprecated: 238

Reviewed proposals:
- APPLIED: 12
- SUPERSEDED: 3
- PENDING_APPLY: 0
- ABORTED: 0

Último `taxonomy_audit_log.id` aceptado: **5260**.

Al reanudar después de una pausa larga, hacer una lectura de solo consulta para confirmar que estos
valores no cambiaron antes de asumir continuidad.

---

## 4. Accesos y rutas conocidas

### GitHub

Conector confirmado con lectura y escritura:
- principal: `estebanjvasquez/PerfilAfiliadosCPV`
- Worker: `estebanjvasquez/perfilafiliados-mcp`
- hilo de gobierno: **Issue #2** del repo principal

Loop del desarrollador (protocolo completo en `AUTONOMOUS_DEV_LOOP.md`):
1. agente trabaja;
2. agente hace commit/push y publica `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`;
3. orquestador lee código/docs reales en ese HEAD;
4. orquestador publica auditoría completa directamente en Issue #2;
5. **el agente lee esa auditoría directamente de Issue #2** y, si es `CORRECTIONS_REQUIRED`, aplica las
   correcciones y devuelve un HEAD nuevo.

**El propietario no retransmite comentarios.** Interviene sólo ante un gate A–F, un bloqueo externo que
requiera su acción, o un `PASS / CLOSED`. Desde el 2026-10-09 el agente también **escribe** en Issue #2
(`CPV_GITHUB_TOKEN`), así que el loop cierra en ambas direcciones; si ese token falta o caduca, el
fallback es `AUTONOMOUS_DEV_LOOP.md` §11, donde la señal de ida es el handoff más el HEAD remoto.

### Supabase

Proyecto conectado:
- project id: `mrquhxwvcrbwbuqafjee`

Usar el conector para lectura/auditoría y sólo hacer mutaciones con autorización explícita.

### Staging

- app: `https://pruebas.camarapetrolera.app`
- UAT CIRA: `https://pruebas.camarapetrolera.app/cira-test/`
- VPS conocido: `66.94.121.98`

Último deploy Laravel aceptado:
- `004d24e98159bf152c741bd9f0ee698b43139d87`
- GitHub Actions run `37458287882`

### Worker / Cloudflare

- Worker: `perfilafiliados-mcp`
- endpoint: `https://perfilafiliados-mcp.sisteg.workers.dev`
- Hyperdrive: `perfilafiliados-taxonomy`
- Hyperdrive id: `f16a1ab0a9514504b80bd14138699d4c`

**No guardar secretos en este archivo.**
El `DEBUG_TOKEN` fue rotado para TASK-0007 y existe como secret del Worker, pero su plaintext no debe
considerarse recuperable desde GitHub ni desde este checkpoint. Si se necesita de nuevo, seguir un flujo
de autorización de secretos explícito.

No tocar la configuración Hyperdrive no relacionada `talento-cpv-db`.

### n8n / CIRA

La página de UAT llama al flujo clonado de pruebas. Antes de una sesión UAT confirmar que el workflow
está activo; si está detenido, todas las consultas pueden fallar igual y producir un falso diagnóstico
de caída del buscador.

---

## 5. Restricciones de continuidad

Hasta nueva autorización:
- no merge a `main`;
- no deploy a producción;
- no otro APPLY/replay;
- no aprobación masiva de TERM→CPV;
- no cambios de ranking/pesos para “mejorar” una consulta aislada;
- no cambios de secretos;
- no otorgar al pasante permiso operativo de publicación;
- no tocar baseline `audit/regression_baseline_2026-09-23.json`.

Toda corrección de ranking/search debe cerrar con nueva regresión contra la línea base congelada.

---

## 6. Loop autónomo Orquestador ↔ Agente

El protocolo vigente está en `docs/orquestador/AUTONOMOUS_DEV_LOOP.md`.

Regla resumida:
- el propietario autoriza una tarea/fase una vez;
- dentro de ese alcance, implementación, pruebas, correcciones, commits, pushes y re-revisiones fluyen
  entre orquestador y agente sin usar al propietario como mensajero;
- sólo se vuelve al propietario ante gates críticos (nueva tarea/fase, producción/main, datos
  destructivos/publicación masiva, secretos/permisos, decisión de negocio/dominio, coste/seguridad/acción
  externa irreversible);
- al terminar, el orquestador informa `PASS / CLOSED` y propone la siguiente tarea si corresponde.

---

## 7. Cómo debe reanudar ChatGPT

Cuando se abra este proyecto desde otro dispositivo o desde la app de escritorio:

1. Abrir el proyecto **CPV - INVESTIGACIONES**.
2. Abrir esta conversación si está disponible; si se inicia un chat nuevo, indicar:
   **“Retoma CPV desde docs/orquestador/SESSION_RESUME.md; verifica HEAD, Issue #2 y estado vivo antes de actuar.”**
3. Leer solamente, en este orden:
   - este archivo;
   - `docs/orquestador/current_task.md`;
   - `audit/orchestrator_handoff.json`;
   - último comentario del orquestador en Issue #2.
4. Verificar el HEAD remoto de `feature/upgrade-filament-v3`.
5. Si se va a tocar taxonomía, hacer una lectura de solo consulta de los conteos de §3.
6. Sólo si hay discrepancias, reconstruir contexto desde comentarios anteriores.

No releer todo el Issue #2 por defecto.

---

## 8. Cómo debe reanudar el agente desarrollador

Al iniciar una nueva sesión del agente:

1. `git fetch origin`
2. cambiar a `feature/upgrade-filament-v3`
3. verificar que el working tree esté limpio
4. leer, en este orden:
   - `docs/orquestador/SESSION_RESUME.md`
   - `docs/orquestador/AUTONOMOUS_DEV_LOOP.md`
   - `docs/orquestador/current_task.md`
   - `audit/orchestrator_handoff.json`
   - último comentario del orquestador en Issue #2
5. confirmar el HEAD remoto antes de modificar archivos
6. no reconstruir decisiones históricas si estos cuatro puntos son consistentes
7. si existe discrepancia entre documentación y repo/base viva, detenerse y reportarla
8. si hay una tarea/fase ya autorizada, entrar directamente al loop de `AUTONOMOUS_DEV_LOOP.md`:
   - implementar/probar/corregir sin pedir autorización por fallos ordinarios;
   - commit + push;
   - actualizar `current_task.md` y `orchestrator_handoff.json`;
   - leer directamente la última auditoría del orquestador en Issue #2;
   - aplicar `CORRECTIONS_REQUIRED` sin que el propietario retransmita mensajes;
   - detenerse sólo ante un `OWNER_GATE_REQUIRED`, bloqueo externo real o `PASS / CLOSED`;
   - nunca persistir tokens, contraseñas ni claves.

---

## 9. Comentarios de gobierno clave

No hace falta leerlos todos al reanudar, pero son las referencias autoritativas:

- TASK-0007 owner APPLY authorization: `6032819854`
- TASK-0007 final closure: `6035642794`
- TASK-0008 open: `6035726459`
- TASK-0008 intern extension: `6035827197`
- TASK-0008 corrections: `6036293989`
- TASK-0008 final closure: `6041102746`

---

## 10. Regla maestra

**No confiar sólo en la memoria de una conversación o de un agente.**

La continuidad se establece con:
1. Git HEAD;
2. este checkpoint;
3. `current_task.md`;
4. `orchestrator_handoff.json`;
5. Issue #2;
6. estado vivo de Supabase cuando corresponda.

Esa combinación permite retomar el trabajo de forma rápida y auditable sin releer toda la historia.

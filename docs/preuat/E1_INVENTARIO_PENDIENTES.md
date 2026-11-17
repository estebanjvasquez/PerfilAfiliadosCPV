# E1 — Inventario consolidado de pendientes

**TASK-0033** · Corte: 2026-11-17 ~11:15Z, rama `fix/sec-01-r4-01-role-editor-preserve` (BASE `2cdb53d`) · Para: el
propietario y el orquestador · Índice: [`00_INDICE.md`](00_INDICE.md)

**Qué es:** una matriz única de lo que queda abierto. Toma como antecedente el
[consolidado del 11/11](../orquestador/PENDIENTES_CONSOLIDADO_2026-11-11.md), que se conserva sin cambios, y lo contrasta con
las entregas posteriores (TASK-0018 a TASK-0032) y con lecturas de hoy. No asigna trabajo.

**Etiquetas de estado:**
- **VIVO:** verificado hoy en la fuente viva; evidencia en [`evidencia/`](evidencia/).
- **DOC:** documentado antes; se cita la fuente.
- **PEND:** pendiente de comprobar.
- **BLOQ:** bloqueado por acceso o gate.
- **DEC:** decisión del propietario o de la Cámara.

**Tipo de pendiente:**
- **TEC:** técnico, requiere asignación.
- **UAT-P:** UAT o revisión del propietario.
- **UAT-C:** UAT o decisión de la Cámara.
- **GATE-P:** bloquea producción.

**Prioridad:** **A** antes de la UAT institucional · **B** durante o al cerrar la UAT · **C** antes de producción · **D**
después o en fase 2.

## 1. Estado vivo de referencia (VIVO, 2026-11-17)

| Elemento | Valor |
|---|---|
| Staging | `5e6e3fc` = `feature/upgrade-filament-v3` = `feat/cira-evaluation-module` (remoto); 0 cambios versionados; BD con 135 migraciones, lote 64 |
| `main` (producción) | `73853cd`, 2026-09-09 (Filament v2 + MySQL según `docs/task.md` §4, confirmado por el propietario en `6802438608`). **Ninguna entrega posterior está fusionada** |
| CIRA | n8n `2d0a1ee8`; Worker `8ce4e0ff` (código `4599217`) |
| Repositorio del Worker | `master` = `29de993`, **nunca desplegado**. El código vivo (`4599217`, linaje `1ea9175`) **solo está en la rama `fix/t32-get-empresa-id`**, sin fusionar |
| Datos | 406 empresas activas; 368 usuarios (21 sin verificar); `cira_search_log` 142; votos 13; evaluaciones CPV 0 (1 campaña, 2 invitaciones) |

## 2. Cambios desde el corte del 11/11 (lo que el consolidado ya no refleja)

| Tema en el corte del 11/11 | Estado entonces | Estado hoy | Fuente |
|---|---|---|---|
| SEC-03 P1–P5 | Sin corregir | Desplegadas en staging y verificadas (TASK-0018/0019/0020) | `SECURITY_STATUS.md` |
| R3 + R4 + R4-01 | Sin desplegar; «no guardar roles» | Desplegados en staging (TASK-0021); F-SEC01-T21-01 corregido (TASK-0023) | idem |
| Vistas públicas (F-SEC01-T24-02) | No existía | Desplegado en staging (TASK-0026); cierre formal pendiente (OBS-T33-02) | E2 |
| Módulo de evaluación CPV-01..08 | No existía | En staging (TASK-0027), 0 evaluaciones | VIVO |
| CIRA: capacidades y experiencias, V1b, ficha por id | Pasos 1–4 | TASK-0028 a 0032; aceptación funcional del propietario con reservas (`6945403230`) | DOC + VIVO |
| Sin sector principal | 194 (TASK-0011) | **191** | VIVO |
| Servicio «X» | 250 vínculos / 236 empresas | **249 / 235**; prioridad alta igual (119 / 109) | VIVO |
| Correo | `smtp` desde el 26/10 | `smtp` | VIVO |
| Búsqueda semántica en staging | Apagada | **Sigue apagada** (`MCP_EMBED_URL`/`TOKEN` vacíos) | VIVO |
| Caché de Cloudflare en `/pdf/*` y `/xls/*` | Sin comprobar | Sin sesión: `DYNAMIC`. Con sesión: PEND | VIVO + PEND |

## 3. Matriz

### 3.1 CIRA

| ID | Pendiente | Fuente / evidencia (fecha) | Estado | Tipo | Riesgo | Depende de | Decide | Prio. | Siguiente acción |
|---|---|---|---|---|---|---|---|---|---|
| CI-01 | TASK-0032 A+B: reserva de **rendimiento** | `6945403230` (17/11); E4 | VIVO: búsqueda ~10 s, ficha ~5,5 s | UAT-P | Percepción en la UAT de la Cámara | — | Propietario | A | Decidir L0+L8, o L1 antes de la UAT (E4 §5) |
| CI-02 | TASK-0032: reserva de **relevancia** (I.O.E.D./Geohidra en ambas vistas) | `6945403230`; ejecución 23163 | VIVO: causa explicada (E5 D3) | UAT-C | Bajo | E5 pregunta 4 | Cámara | B | Preguntar a la Cámara |
| CI-03 | Observaciones de UX: marcador `[ID n]` visible; «Empresa no afiliada» ante un texto no resuelto | `6945403230` | DOC | UAT-P | Bajo | — | Propietario | B | Decidir si se corrigen (requiere asignación) |
| CI-04 | El pedido de ficha llama al modelo aunque el resultado sea determinista | E4 §3.2 | VIVO | TEC | Ninguno (solo tiempo) | — | Propietario | B | Opción L1 |
| CI-05 | Sesgo de caché en los benchmarks de TASK-0031/0032 (p50 6,1 s subestima) | E4 §3.4 | VIVO (HIP con evidencia fuerte) | TEC | Expectativas | — | — | B | Tenerlo en cuenta en futuros benchmarks (consultas nuevas o pausa > 60 s) |
| CI-06 | W1 (Worker) | TASK-0031; `6924316065` | DOC: no autorizado | TEC | Bajo | UAT | Propietario | D | Decisión aparte |
| CI-07 | TASK-0027: lecturas de monitorización T+4/12/24 h no registradas | `tasks/0027`; `current_task.md` | VIVO: instantánea PASS (92 h) | UAT-P | Proceso | — | Propietario | A | Aceptar la instantánea de hoy o pedir una ventana nueva (OBS-T33-02) |
| CI-08 | Campaña real: 1 campaña, 2 invitaciones (roles `lectura` y `afiliados`), 0 evaluaciones | VIVO | VIVO | UAT-C | — | E3 §3 | Propietario | A | Invitar a los evaluadores definitivos (E3 B-01) |
| CI-09 | TASK-0029 / 0030 / 0028 / 0031: sin aprobación global | `current_task.md` | DOC | UAT-P | — | — | Orquestador | B | Revisión |
| CI-10 | TASK-0013: votos sin revisar (13, todos 👍 del personal); pasos 3, 4 y 6 del plan sin asignar | `tasks/0013`; VIVO | VIVO | UAT-C | — | E5 D4 | Cámara / propietario | B | Glosario regional (E5 pregunta 5) |
| CI-11 | **El código vivo del Worker no está en `master`**; `master` tiene `29de993`, nunca desplegado | VIVO (repo del Worker) | VIVO | GATE-P | Alto para producción: fuente de verdad ambigua | — | Propietario | C | Asignación: conciliar ramas del Worker sin desplegar |
| CI-12 | Dos hallazgos de seguridad del flujo n8n (privados) | `tasks/0013` | DOC | GATE-P | Ver E2 | — | Propietario | C | Asignación |
| CI-13 | El flujo n8n solo está versionado como export sanitizado; respaldos fuera del repo | TASK-0029–0032 | DOC | GATE-P | Recuperación ante desastre | — | Propietario | C | Plan de producción |

### 3.2 Seguridad (detalle en [E2](E2_MATRIZ_SEGURIDAD_PREPRODUCCION.md))

| ID | Pendiente | Estado | Tipo | Prio. | Siguiente acción |
|---|---|---|---|---|---|
| SE-01 | F-SEC01-R3-02, R3-03, T24-01 sin corregir | DOC; T24-01 contenido (VIVO: nadie fuera de `super_admin` tiene `delete_user`) | GATE-P | C | Asignación |
| SE-02 | Cierre formal de F-SEC01-T24-02 y de su monitorización; inventario de la clave anónima de la Data API | VIVO (13/13) + PEND | GATE-P | A (decisión) / C | DEC |
| SE-03 | F-02, F-03 (rol `afiliados` con `create_area`/`create_country`, VIVO), F-06 | DEC | GATE-P | B | DEC |
| SE-04 | Recuperación de los 109 permisos de R4-01; R-04 (2 huérfanas, VIVO); R-06 | DEC | GATE-P | C | DEC + gate de escritura |
| SE-05 | Cobertura: 14 de 24 relation managers, 9 de 12 búsquedas | DOC | GATE-P | C | Ronda |
| SE-06 | SEC-02, SEC-04 y SEC-05 sin empezar | DOC | GATE-P | C | Asignación |
| SE-07 | RT-BUILD (imagen sin `.env`), sin desplegar | DOC | GATE-P | C | Asignación de despliegue |
| SE-08 | Rotar 3 credenciales; revocación de los tokens de Cloudflare de septiembre; PAT | BLOQ (no autorizado) | GATE-P | C | Plan |
| SE-09 | HSTS en 1 día (VIVO); subir por etapas | VIVO | GATE-P | C | DEC |
| SE-10 | OBS-T33-01: cabeceras de endurecimiento ausentes | VIVO | GATE-P | C | Evaluar en SEC-02/04 |
| SE-11 | Turnstile intermitente en móvil (Samsung + Chrome) | DOC | UAT-C | A (aviso) / C | Avisar en la UAT (E3 §5); diagnóstico aparte |

### 3.3 Taxonomía y diccionario

| ID | Pendiente | Fuente / evidencia | Estado | Tipo | Decide | Prio. | Siguiente acción |
|---|---|---|---|---|---|---|---|
| TX-01 | Sector principal de 191 empresas (103 solo con «X») | E5 D1 | VIVO | UAT-C | Cámara | B | Enviar F1 |
| TX-02 | Corregir 249 vínculos «X» (119 de prioridad alta) y retirar «X» | E5 D2; DEC del 2026-11-07 | VIVO | UAT-C → TEC | Cámara → propietario | B | Enviar F2; aplicar con gate de escritura |
| TX-03 | Alias «pumping unit», «pump jack», «balancines» (0 en el diccionario) | E5 D4; `tasks/0014` | VIVO | UAT-C | Cámara | B | Glosario (F3) |
| TX-04 | Búsqueda semántica de categorías en staging (autorizada en alcance por `6802438608` punto 5) | VIVO: sin configurar | TEC | Propietario | C | Asignación con configuración y prueba |
| TX-05 | TASK-0014: UAT parcial (diccionario sin probar); búsqueda global del panel solo por código | DOC | UAT-P | Propietario | B | E3 B-07 |
| TX-06 | TASK-0015: revisión técnica; sin comentario de entrega; renumerar Familias protegidas exige cambiar listas; **las ediciones normales de la ficha no quedan auditadas** | DOC | TEC / UAT-P | Orquestador | C | Revisión |
| TX-07 | TASK-0016: revisión técnica; sin respaldo en el servidor si el proveedor se niega; buscador del catálogo sin filtro por jerarquía | DOC | TEC | Orquestador | C | Revisión |
| TX-08 | 3 solicitudes de categoría `pending_review`; **el aviso de la n.º 1 (de la Cámara) falló** | VIVO | UAT-C | Cámara | B | E5 D6 |
| TX-09 | Candidatos de conceptos: 7 `context_required`, 3 `published`; relaciones: 2 `rejected` | VIVO | UAT-P | Propietario | D | Revisión en el panel (sin publicación automática) |
| TX-10 | ¿La Cámara (empresa 451, activa) debe figurar como empresa? | VIVO; `tasks/0013` | UAT-C | Cámara | B | E5 pregunta 6 |
| TX-11 | Gerencia (`planning/management-taxonomy`) en espera | DOC | — | Propietario | D | Después de la seguridad |

### 3.4 Cuentas, recuperación, perfiles, PDF y exportaciones, panel

| ID | Pendiente | Fuente / evidencia | Estado | Tipo | Prio. | Siguiente acción |
|---|---|---|---|---|---|---|
| CU-01 | TASK-0012: evidencia formal de cada flujo de correo y RIF con cuentas y buzones de prueba (autorizada en alcance, sin tarea) | `6802438608`; VIVO `smtp` | PEND | UAT-P / GATE-P | B | Asignación (E3 B-05 y B-06) |
| CU-02 | Manual: «Verificar su correo electrónico» dice que el sistema no envía correos | consolidado §7 | DOC | TEC (doc) | A | Corregir el manual (documental) |
| CU-03 | Texto de la pantalla de verificación tras un cambio de correo (dice «cuenta creada») | `SESSION_RESUME.md` §5 | DOC | TEC | D | Asignación |
| CU-04 | `Empresa::create` vincula automáticamente 4 usuarios del personal a una empresa nueva | `SESSION_RESUME.md` §5 (TASK-0020) | DOC | TEC | C | Revisión independiente |
| PF-01 | Perfil completo en PDF, secciones, Inicio: UAT declarada | `6802438608` | DOC | UAT-C | B | E3 A-27/A-28 |
| PF-02 | Recorrido de UX en navegador y teléfono (TASK-0011/0012); revisión visual integral (fase 2) | consolidado §7; `6802438608` punto 8 | DOC | UAT-C | D | Fase 2 |
| PF-03 | `JoinViews`: falla (500), PDF a un método inexistente, pierde experiencias UUID | VIVO (log del 13/11) | VIVO | TEC / GATE-P | C | Asignación (deuda 8a) |
| EX-01 | Descargas con control de acceso: comprobar **con sesión** que Cloudflare no cachea | VIVO sin sesión: `DYNAMIC` | PEND | UAT-P / GATE-P | B | E3 A-30/A-31 + lectura de cabeceras con una cuenta de prueba |
| EX-02 | `CompanyDossierPdfTest` falla al azar (código CPV-900..999) | consolidado §7 | DOC | TEC | D | Asignación |
| PA-01 | *Hotfix* de paginación sin archivo de tarea | consolidado §8 | DOC | UAT-P | D | Registrar si se decide |
| PA-02 | Backlog: `Empresa::users()` sin sesión, `EditEmpresa::getSteps()` con `cities` vacía, `StatsOverview` sin empresas, estado de ancestros, `app/images/user.pdf` ajeno, mysql del crawler | `SESSION_RESUME.md` §5 | DOC | TEC | D | Asignación |

### 3.5 Infraestructura y producción

| ID | Pendiente | Fuente / evidencia | Estado | Tipo | Prio. | Siguiente acción |
|---|---|---|---|---|---|---|
| IN-01 | BD compartida (desarrollo + staging) con datos reales: la UAT no debe escribir datos de empresas | VIVO | VIVO | UAT-C | A | Regla C1 (E2, E3) |
| IN-02 | Hyperdrive: caché activa (~60 s) y `origin_connection_limit 8` (el pooler tiene 15) | VIVO | VIVO | TEC | B | Tenerlo en cuenta en E4 y en la carga de la UAT |
| IN-03 | Despliegue: `paths-ignore` en `resources/manual/*.md`; el agente no puede lanzar `workflow_dispatch` (403); nunca `bash -s` por SSH | `SESSION_RESUME.md` §5 | DOC | TEC | D | — |
| IN-04 | Ciclo de vida de las imágenes antiguas (con `.env` en capas) y respaldos en `/root/backups` | RT-BUILD | DOC | GATE-P | C | DEC |
| PR-01 | Plan comparativo de producción (migración conjunta o por etapas), checklist y ensayo aislado | `6802438608` punto 7 | DOC: sin asignar | GATE-P | C | Asignación del plan |
| PR-02 | Producción: PHP 8.2+ con parche, `intl`, `.cpanel.yml` con `filament:assets`, `shield:generate`, respaldo | `docs/task.md` §4 (2026-09-29) | DOC, **confirmar vigencia** | GATE-P | C | Dentro de PR-01 |
| PR-03 | Fusionar a `main` (nada de 0011–0032 está fusionado) | VIVO (`main` del 09/09) | VIVO | GATE-P | C | Autorización explícita |
| PR-04 | Gate de liberación: seguridad crítica y alta cerrada, UAT completa, aceptación de la Cámara y autorización explícita | `6802438608` | DEC | GATE-P | C | — |

## 4. Inconsistencias de registro detectadas hoy (para corregir en una asignación documental)

| Dónde | Qué dice | Qué muestra la fuente viva |
|---|---|---|
| `SESSION_RESUME.md` y `current_task.md`, TASK-0026/0027 | «Siguiente: lecturas T+4/12/24 h» | Las lecturas no constan; ventana vencida hace 3 días. Instantánea de hoy PASS |
| `audit/CIRA_TASK0032_FASE_B_FICHA_EMPRESA.md` §3 | «Laravel de staging solo usa `/embed`» | Hoy `MCP_EMBED_URL` está vacío: Laravel de staging **no llama** al Worker. La conclusión de aislamiento no cambia |
| Benchmarks de TASK-0031/0032 | p50 de V1b 6,0–7,0 s | Mezclan consultas cacheadas; con consultas nuevas el Worker suma ~1,5 s (E4 §3.4). No se reescriben; se anota aquí |
| `SESSION_RESUME.md` §2 | Tabla de staging con SHAs anteriores mezclados | Vivo `5e6e3fc` (correcto en la última columna) |
| Consolidado del 11/11 §5 | «Desplegar `29de993` del Worker» | Sigue sin desplegar y además **el código vivo no está en `master`** (CI-11) |

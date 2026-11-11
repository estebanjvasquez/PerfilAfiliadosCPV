# Consolidación de pendientes, 2026-11-11

**Para quién:** el propietario y el orquestador. Sirve para decidir qué se revisa, qué se aprueba y qué se asigna. No es una asignación de trabajo.

**Fuentes, y solo estas:**
- Archivos versionados de la rama canónica `fix/sec-01-r4-01-role-editor-preserve` en `HEAD` `6c46de4`, igual al remoto:
  - `SESSION_RESUME.md` y `current_task.md`;
  - `tasks/0011` a `tasks/0017`;
  - `docs/security/SECURITY_STATUS.md` y `docs/plans/SECURITY_REVIEW_PLAN.md`;
  - `docs/task.md`, del 2026-09-29.
- Del Issue #2, los 30 comentarios publicados desde el 2026-10-27, cuando empezó TASK-0011, hasta `6798355704` (2026-11-11 14:22Z). De ellos se tomaron las entregas, más un comentario que todavía no está versionado.

**Fuera de este informe:**
- los cambios locales sin commit (incluido trabajo en curso que no está versionado);
- lo conversado en el chat y no registrado;
- `audit/**` e `history/**`;
- los comentarios del Issue anteriores al 2026-10-27, salvo lo que los archivos versionados ya resumen.

Las cifras y estados son los del registro citado, no una verificación en vivo.

---

## 1. Lo más urgente

1. **SEC-03 / TASK-0017, entregada para revisión.** La auditoría asignada con prioridad alta (`6797800863`) está READY_FOR_REVIEW (`6798355704`): no se confirmó toma de cuentas y hay 6 hallazgos registrados en `SECURITY_STATUS.md`, sin corregir. Falta la revisión y, si se desea, una autorización nueva para las correcciones P1–P5. Uno de los hallazgos debe verificarse en producción antes de llevar allí la recuperación de acceso (§6).
2. **Riesgo vivo en roles (F-SEC01-R4-01).** El editor de roles de staging quita al guardar los permisos que no muestra. La corrección está lista para revisión desde el 2026-10-21, sin desplegar. Hasta desplegarla: **no guardar roles en el editor.**
3. **Seguridad aceptada sin desplegar.** R3 (`e95169c`), R4 (`97737ba`) y R4-01 (`24413d5`) están aceptados o listos, pero staging no los tiene: sus despliegues se promovieron aislados, sin el trabajo de seguridad.
4. **UAT y revisión acumuladas.** Hay 11 entregas esperando la UAT del propietario o la revisión del orquestador: 10 en staging y la auditoría TASK-0017 (§3).
5. **Antes de producción:** rotar tres credenciales y corregir dos hallazgos de seguridad del flujo de n8n (§6).

---

## 2. Asignaciones abiertas sin entrega

Ninguna. La última asignación, SEC-03 (`6797800863`), se entregó como TASK-0017 (`6798355704`) y está en §3.

---

## 3. Entregas que esperan revisión o UAT

| Entrega | Estado | Dónde está | Qué falta | Fuente |
|---|---|---|---|---|
| **TASK-0017**: auditoría SEC-03 de recuperación de cuentas, tokens y `Host`/proxies | READY_FOR_REVIEW. Solo auditoría sobre staging `b048df0`; pruebas en la rama `audit/sec-03-account-recovery` (`8a4ed35`), que no despliega | Informe en `audit/` y registro en `SECURITY_STATUS.md` | Revisión del orquestador. Decisión del propietario sobre las correcciones P1–P5 (§5) | `tasks/0017`; Issue `6797800863`, `6798355704` |
| **TASK-0016**: análisis con IA de solicitudes de categoría | READY_FOR_REVIEW, en staging (`893351f`, run `42999603780`) | Panel: Taxonomía CPV > Configuración del análisis con IA | UAT del propietario: configurar proveedor, clave, modelo y presupuesto, y analizar las solicitudes #2 «Ingeniería de Costos» y #3. **El primer análisis es la primera llamada de pago** | `tasks/0016`; Issue `6792149810`, `6792865854` |
| **Hotfix**: paginación sin «Todos» | READY_FOR_REVIEW, en staging (`b048df0`) | Todas las tablas del panel | Revisión. **No está en ningún archivo versionado** | Issue `6794806339` |
| **TASK-0015**: cambio de código de categoría validado y auditado (incluye Familias y Grupos) | READY_FOR_REVIEW, en staging (`d8e2aae`) | Edición de categorías | UAT del propietario. **No tiene comentario de entrega en el Issue** | `tasks/0015`, `current_task.md` |
| **TASK-0014**: coherencia de búsqueda de taxonomía y términos desde la categoría | READY_FOR_REVIEW, en staging (`a8020cf`) | Selector de la empresa, listados de administración, pestaña «Términos del diccionario» | UAT del propietario. **No tiene comentario de entrega en el Issue** | `tasks/0014`, `current_task.md` |
| **TASK-0013**: calibración del buscador CIRA, pasos 1 a 4 | READY_FOR_REVIEW. Paso 4 en vivo: Worker `1005d61f` y tarjetas de n8n | `pruebas.camarapetrolera.app/cira-test/` | Revisión del orquestador de la tarea y sus pasos. Revisión de los votos registrados (§5) | `tasks/0013`; Issue `6685054227` … `6745479939` |
| **TASK-0012**: recuperación de acceso por correo y RIF | En staging desde 2026-11-03 | Login, recuperar contraseña y recuperar acceso por RIF | UAT del propietario. Una prueba de entrega real de correo a un buzón de prueba suyo, que requiere autorización específica | `tasks/0012`; Issue `6602724157`, `6603072545` |
| Descargas con acceso controlado (export-access) | DEPLOYED_FOR_OWNER_REVIEW (`ce70b7f`) | Descargas de perfil | UAT de 5 pasos (`current_task.md`) y revisar o purgar la caché de Cloudflare `/pdf/*` y `/xls/*` | `current_task.md`, `6265502896` |
| Perfil completo en PDF | DEPLOYED_FOR_OWNER_REVIEW (`514fa6e`) | Empresas > Perfil completo (PDF) | UAT de 5 pasos | `current_task.md`, `6286221372` |
| Sección «Operación en Venezuela» del PDF | DEPLOYED_FOR_OWNER_REVIEW (`87dd997`) | Elegir secciones | UAT de 5 pasos | `current_task.md`, `6288459314` |
| Inicio con nueva marca (`0f8bdad`) | UAT pendiente | Inicio en staging | UAT | `SESSION_RESUME.md` §2 y §3 |

Ya cerradas, como referencia: TASK-0011 APPROVED (`6440840198`); SEC-01 R1+R2 desplegado con UAT PASS (`6316265832`).

---

## 4. Seguridad (programa SEC)

| Pendiente | Estado | Fuente |
|---|---|---|
| R3: relaciones de empresa | Aceptado (`6318588005`), **no desplegado** | `SECURITY_STATUS.md`, `current_task.md` |
| R4: administrar roles, solo super_admin | Código aceptado (`6319407886`), **no desplegado**. La contención manual está verificada | idem |
| F-SEC01-R4-01: el editor de roles conserva lo que no muestra | READY_FOR_REVIEW (`6320241495`), **no desplegado**. El propietario debe revisar la corrección y la propuesta de recuperación (≤1 vínculo) | idem |
| R3-02 y R3-03 | Permisos de módulo en acciones propias; categoría inactiva por id forjado. Registrados, sin corregir | `SECURITY_STATUS.md` §4 |
| Cobertura de relation managers | Probados 10 de 24; **faltan 14**, todos fuera de Empresa | `SECURITY_STATUS.md` §6 |
| Cobertura de búsqueda global | Probados 3 de 12 recursos buscables; **faltan 9** | idem |
| Otras superficies sin cerrar | Actor administrador; acciones Livewire y exportaciones generales; registro, perfil, roles, verificación y recuperación; archivos y cargas; caché y configuración; dependencias | idem |
| Decisiones F-02, F-03 y F-06 | Del propietario: editor y configuración de roles, y datos de roles | idem |
| F-SEC03-01 a F-SEC03-06 (TASK-0017) | Confirmados, sin corregir ni desplegar. Ninguno permitió tomar una cuenta en lo probado. Las propuestas P1–P5 esperan autorización | `SECURITY_STATUS.md` §4 y §5, `tasks/0017` |
| SEC-03 en general | Sigue abierta: TASK-0017 cubrió recuperación, tokens y `Host`/proxies. Quedan sin verificar el borde de Cloudflare, producción, el correo real y la medición de tiempos | `SECURITY_STATUS.md` §3 |
| Rondas SEC-02, SEC-04 y SEC-05 | PLANNED, sin empezar | `SECURITY_REVIEW_PLAN.md` |
| Gerencia (`planning/management-taxonomy`) | En espera hasta cerrar la seguridad | `SESSION_RESUME.md` §1 |

---

## 5. Decisiones del propietario

| Decisión | Contexto | Fuente |
|---|---|---|
| Desplegar R3 + R4 + R4-01 | El riesgo vivo del editor de roles sigue hasta entonces | `SESSION_RESUME.md` §3 |
| Autorizar las correcciones P1–P5 de SEC-03 | El registro marca como lo más urgente F-SEC03-03 (los límites por IP afectan a todos los usuarios a la vez) y F-SEC03-01 (verificar en producción). P2 no debe aplicarse sin P1 o sin el ajuste de cabeceras que indica el registro | `SESSION_RESUME.md` §3, `tasks/0017` |
| Activar el correo | `SESSION_RESUME.md` dice que sigue en `log` y que sin correo un afiliado nuevo no puede verificarse solo. `tasks/0012` dice que staging envía por `smtp` desde el 2026-10-26. Ver §8 | `SESSION_RESUME.md` §2 y §5, `tasks/0012` |
| Solicitud #1 | Mencionada como pendiente, sin más detalle en los archivos leídos | `SESSION_RESUME.md` §3 |
| Sector principal de las empresas que no lo tienen | 194 de 406 empresas sin sector principal, según TASK-0011. Es un dato que define la Cámara, no el sistema | `tasks/0011`, `tasks/0013` |
| Retirar el servicio «X» | 250 vínculos en 236 empresas. 109 empresas perderían su único sector si se borra «X» sin corregirlas antes | `tasks/0013` paso 2 |
| Si la Cámara aparece como empresa en las búsquedas | Decisión de datos | `tasks/0013` paso 2 |
| Revisar los votos 👍/👎 de CIRA | Los votos no cambian el ranking; esperan revisión | `tasks/0013`, Issue `6690891320` |
| Activar la búsqueda semántica en staging | Falta `MCP_EMBED_URL`/`MCP_EMBED_TOKEN`. Afecta a TASK-0014 y TASK-0016 | `tasks/0014`, `tasks/0016` |
| Desplegar `29de993` del Worker | Está en `master` del Worker desde el 2026-09-28 y nunca se desplegó | Issue `6690487613` |
| Plan del buscador, pasos 3, 4 y 6 | Glosario regional validado por el cliente; juicio asistido por IA sobre perfiles; análisis de empresas que salen como ruido. Cada paso necesita su propia asignación | `tasks/0013`, «Plan propuesto» |
| Alias de «Balancín» | «pumping unit», «pump jack» y «balancines» todavía no la encuentran. Es un cambio de datos no autorizado | `tasks/0014`, limitaciones |

---

## 6. Antes de producción

| Pendiente | Fuente |
|---|---|
| Rotar `N8N_API_KEY`, `CPV_GITHUB_TOKEN` y `CPV_MCP_TOKEN`. El último se rota en el Worker y en n8n a la vez | `tasks/0013`, `current_task.md` |
| Corregir los dos hallazgos de seguridad del flujo de n8n. Se informaron por canal privado y no se detallan aquí | `tasks/0013` |
| Verificar F-SEC03-01 en producción antes de llevar allí la recuperación de acceso (TASK-0012) | `SECURITY_STATUS.md` §4, `tasks/0017` |
| Producción (`main`) sigue en Filament v2 + MySQL. Decisiones de negocio: ¿pgsql en el mismo despliegue que Filament v3?, ¿cómo se combinan las ramas? | `docs/task.md` §4 (2026-09-29; confirmar si sigue vigente) |
| Checklist funcional en staging; `.cpanel.yml` con `filament:assets`; PHP 8.2+ e `intl` en producción; `shield:generate` en producción; respaldo previo | `docs/task.md` §4 |
| Confirmar que se revocaron los tokens de Cloudflare usados en septiembre, y vigilar el vencimiento del PAT de GitHub | `docs/task.md` §2 |
| Merge a `main`: ninguna de las tareas 0011 a 0017 ni el *hotfix* está fusionada | Todas las entregas |

---

## 7. Deuda técnica y mejoras registradas (no arrancan solas)

- **`JoinViews` (Cámara):**
  - su vista falla con empresas incompletas;
  - su acción Pdf apunta a un método que no existe;
  - pierde experiencias con clave UUID. (`SESSION_RESUME.md` §5)
- **Backlog de `SESSION_RESUME.md` §5:**
  - `Empresa::users()` sin sesión;
  - `EditEmpresa::getSteps()` con `cities` vacía;
  - `StatsOverview` con `empresas` vacía;
  - estado de los ancestros en el buscador;
  - `app/images/user.pdf` ajeno;
  - dependencia mysql del crawler.
- **Despliegue:**
  - `paths-ignore` no despliega cambios solo en `resources/manual/*.md`;
  - el agente no puede lanzar `workflow_dispatch`;
  - los avisos de solicitudes no garantizan un único envío.
- **TASK-0011:**
  - recorrido UX en navegador y en teléfono;
  - limpiar `lang/` sin uso;
  - quitar desde la ficha los servicios viejos fuera de sector.
- **TASK-0012:**
  - la sección «Verificar su correo electrónico» del manual dice que el sistema no envía correos;
  - sin recorrido en navegador ni en teléfono.
- **TASK-0014:**
  - la búsqueda global del panel solo busca por código, branch y subbranch;
  - sin pruebas de navegador.
- **TASK-0015:**
  - renumerar Familias protegidas exige cambiar listas en el código y en el Worker;
  - el índice `empresa_search_documents` ve el código nuevo solo al reconstruirse;
  - las ediciones normales de la ficha no quedan auditadas.
- **TASK-0016:**
  - ante una negativa del proveedor no hay respaldo del lado del servidor (queda «Falló»);
  - el buscador del catálogo no filtra por jerarquía.
- **Pruebas:** `CompanyDossierPdfTest` puede fallar por azar: su código aleatorio CPV-900 a CPV-999 puede chocar con uno existente. (Issue `6794806339`)
- **Datos históricos:** una fila de `assets` con actor NULL y una empresa con dos filas. Solo lectura, sin acción.

---

## 8. Inconsistencias en los registros (para corregir al actualizar)

| Dónde | Qué dice | Qué dice la fuente más reciente |
|---|---|---|
| `tasks/0013`, cabecera y «Evidencia» | Migración «no aplicada»; estado al 2026-11-06 | Aplicada el 2026-11-06, lote 61 (misma tarea; Issue `6690305941`); pasos 2 a 4 en vivo |
| `current_task.md`, bloque TASK-0013 | REVIEW_HEAD `bc8212b`; sin cambios de ranking | Ranking cambiado y en vivo (pasos 2 a 4, Worker `1005d61f`) |
| `tasks/0014`, «Limitaciones» | «Sin desplegar» | Desplegada en staging el 2026-11-09 (cabecera de la misma tarea) |
| `tasks/0016`, «Limitaciones y pendientes» | Migración no aplicada y código no desplegado | Desplegada con migraciones (addendum del 2026-11-11) |
| `SESSION_RESUME.md` §2 | Staging en `06155ce` (2026-11-03) | `b048df0`, verificado en el servidor el 2026-11-11 (`tasks/0017`; Issue `6794806339`) |
| `SESSION_RESUME.md` §2 y §5, frente a `tasks/0012` | Correo en `log`, activación pendiente | Staging envía por `smtp` desde el 2026-10-26 |
| *Hotfix* de paginación | Solo en el Issue | Falta registrarlo en los archivos versionados |
| TASK-0014 y TASK-0015 | Entregadas y en staging | Sin comentario de entrega en el Issue #2 |
| `SESSION_RESUME.md` §1, mensaje para reiniciar | Escrito con voseo («Trabajá», «Leé», «usá») | Regla de idioma: español neutro o venezolano |

---

## Addendum 2026-11-11 17:17Z — decisiones del propietario (`6802438608`)

Todo lo anterior es el corte original de `f6e3134` (comentario `6801807831`) y se conserva sin cambios. Esta sección registra la aprobación del propietario en `6802438608`.

**Es solo un registro documental.** No asigna tareas ni autoriza implementar, desplegar, fusionar, rotar secretos, cambiar n8n o datos, ni probar en producción.

Cómo leer las columnas:
- **Declaración del propietario:** lo que el propietario decide o afirma en `6802438608`.
- **Evidencia técnica:** lo que ya consta en los registros versionados.
- **Pendiente de comprobación:** lo que todavía no tiene evidencia.

| # | Tema | Declaración del propietario | Evidencia técnica | Pendiente de comprobación | Para ejecutarse |
|---|---|---|---|---|---|
| 1 | SEC-03 / TASK-0017 | Autoriza en alcance desarrollar P1–P5 y probarlas en aislamiento, sin despliegue automático. P1 (hosts y URL) va antes de confiar en cabeceras para P2 | Auditoría READY_FOR_REVIEW; 6 hallazgos abiertos (`tasks/0017`, `SECURITY_STATUS.md`) | P1–P5 sin implementar | Materializar una tarea según `PROTOCOL.md` |
| 2 | SEC-01 R3, R4 y R4-01 | Autoriza revisión final, integración y despliegue **en staging**, con respaldo previo, regresión y verificación de preservación de permisos. No producción ni `main`. Hasta verificar R4-01, no guardar roles en el editor de staging | R3 y R4 aceptados; R4-01 READY_FOR_REVIEW; ninguno desplegado | Integración, respaldo, regresión, despliegue y verificación de permisos | Asignación de ejecución |
| 3 | TASK-0012 y correo | Recibió correos de verificación y de recuperación. Autoriza pruebas controladas en staging con cuentas y buzones de prueba; no producción | `smtp` en staging desde el 2026-10-26 (`tasks/0012`; RT-MAIL-2026-10 y RT-MAIL-TLS-2026-10) | Evidencia formal de todos los flujos, incluido RIF y los controles de SEC-03 | Asignación de ejecución. No se vuelve a afirmar sin matiz que el correo no funciona |
| 4 | UAT declarada (10 comprobaciones) | Ver la lista de abajo | — | No es aprobación de seguridad, integración ni despliegue | — |
| 5 | TASK-0014 | La búsqueda de categorías se probó en parte; la gestión del diccionario no se probó. Pide configurar y probar la búsqueda semántica de categorías en staging, verificando dependencias y configuración antes de cualquier cambio | Semántica apagada en staging (falta `MCP_EMBED_URL`/`MCP_EMBED_TOKEN`, `tasks/0014`) | UAT del diccionario; configuración semántica | Asignación; no producción |
| 6 | CIRA / TASK-0013 | Verificó la explicación de coincidencias y los votos. Búsqueda, ranking y aceptación por la Cámara siguen **sin validación del cliente**. Pedir a la Cámara que revise sectores principales y el servicio «X» **antes** de modificar datos. Las mejoras adicionales de CIRA pasan a fase 2 | Pasos 1 a 4 en vivo (`tasks/0013`) | Validación del cliente | Solicitud a la Cámara |
| 7 | Producción | Confirma Filament v2 + MySQL. Pide primero un plan técnico comparativo de migración conjunta frente a migración por etapas. Autoriza solo planificar y preparar credenciales y n8n, sin rotar ni modificar. Pide un checklist y un ensayo aislado sin tocar producción | `docs/task.md` §4 | Plan, checklist y ensayo | Asignación del plan |
| 8 | Deuda técnica | Antes de producción: (a) `JoinViews` (vista, PDF, UUID); (b) formularios y panel; (c) pruebas automatizadas y cobertura; (d) manuales, documentación y handoff; (e) otros defectos y mantenimiento. Todo verificando el impacto y sin cambios destructivos implícitos. **Fase 2:** revisión visual integral y en dispositivos. Las pruebas mínimas de flujos críticos siguen siendo requisito de preproducción | §7 de este informe | — | Asignación |

**Gate de liberación a producción (declaración del propietario):**
1. seguridad crítica y alta cerrada;
2. UAT completa;
3. aceptación de la Cámara;
4. autorización explícita de producción.

### UAT declarada por el propietario (`6802438608`)

| # | Comprobación | Entrega |
|---|---|---|
| 1 | Perfil completo en PDF | `514fa6e` |
| 2 | Selección de secciones del PDF | `87dd997` |
| 3 | Exportaciones y control de acceso | `ce70b7f` |
| 4 | Paginación sin «Todos» | *hotfix* `b048df0` |
| 5 | Nueva página de Inicio | `0f8bdad` |
| 6 | Cambios de códigos de categoría | TASK-0015 |
| 7 | Configuración de IA | TASK-0016 |
| 8 | Análisis de solicitudes | TASK-0016 |
| 9 | Explicación de coincidencias | TASK-0013 |
| 10 | Valoraciones y retroalimentación | TASK-0013 |

Se registra como UAT declarada. Ningún estado pasa por eso a APPROVED técnico, DEPLOYED o CLOSED.

Sigue pendiente de comprobación:
- la caché de Cloudflare `/pdf/*` y `/xls/*` de las descargas;
- TASK-0014, cuya UAT es parcial (punto 5).

### Cómo quedan los urgentes del corte (§1)

| Urgente | Después de `6802438608` |
|---|---|
| SEC-03 / TASK-0017 | Correcciones autorizadas en alcance (punto 1). Falta materializar la tarea |
| Editor de roles (F-SEC01-R4-01) | Despliegue en staging autorizado en alcance (punto 2). El riesgo sigue hasta verificarlo: no guardar roles |
| R3, R4 y R4-01 sin desplegar | Igual que el anterior |
| 11 entregas sin UAT o revisión | 10 con UAT declarada. Quedan la UAT parcial de TASK-0014, la revisión técnica de cada entrega y la revisión de TASK-0017 |
| Antes de producción | Credenciales y n8n: solo planificar. Gate de liberación definido arriba |

### Qué se reconcilió y qué queda bloqueado

Reconciliado en esta revisión (solo documentos):
- `SESSION_RESUME.md`: checkpoint, SHA de staging, estado del correo, siguiente acción y mensaje de reinicio sin voseo;
- `current_task.md`: bloque de decisiones; correo con matiz;
- `SECURITY_STATUS.md`: decisión registrada, sin cambiar los estados de §4;
- addenda en `tasks/0012` a `tasks/0017`, más la corrección de la cabecera de `tasks/0013` con el texto anterior tachado.

No hecho:
- `audit/orchestrator_handoff.json`: las reglas de arranque no permiten editar `audit/**`.
- Ningún identificador de tarea nuevo. P1–P5, la integración de SEC-01, las pruebas de correo, la búsqueda semántica, el plan de producción y la deuda técnica necesitan materializarse o asignarse antes de ejecutarse.
- *Hotfix* de paginación: sigue sin archivo de tarea. Queda registrado en `current_task.md` sin asignarle número.

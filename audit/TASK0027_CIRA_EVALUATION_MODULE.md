# TASK-0027 — Módulo web de evaluación de CIRA para la Cámara

**Fuente:** Issue #2 `6852945146`. **Rama del candidato:** `feat/cira-evaluation-module`, desde staging `6e7f525`, con
*fast-forward* limpio.
**Estado:** **DEPLOYED_AWAITING_24H**. `5e6e3fc` está en staging desde el 2026-11-13 14:11:57Z (workflow `43440450319`), con la migración
sola en el lote 64. Lo autorizaron `6855056483` (DDL) y `6855171480` (excepción al gate de 24 h de TASK-0026). La
monitorización de 24 h termina el 2026-11-14 14:11:57Z. Ver la sección 7.

## 1. Especificación funcional

**Evaluador** (cuenta existente invitada a una campaña abierta):
1. En **CIRA Test** (`/cira-test/`), debajo de cada respuesta, aparece «Evaluar esta búsqueda». En la cabecera están «Guía de
   evaluación» y «Mis pruebas».
2. El enlace lleva el id de la búsqueda (`data-log` de las tarjetas de n8n) y la sesión de la página. Abre
   `/admin/evaluacion-cira/evaluar`; si el evaluador no ha iniciado sesión, el panel la pide y luego vuelve a la evaluación.
3. El formulario muestra la búsqueda tal como quedó registrada: consulta, consulta enviada al buscador, número de resultados,
   latencia, versión del motor, error si lo hubo, y cada empresa con su posición, el porqué y los votos 👍/👎 de esa búsqueda.
   Los campos que el registro no tiene dicen «no disponible».
4. El evaluador elige la prueba (CPV-01 a CPV-08) y valora **Correcta / Parcial / Incorrecta**. Además puede:
   - marcar resultados sobrantes (solo de esa búsqueda);
   - buscar empresas faltantes por nombre, o escribirlas;
   - indicar un motivo, un comentario y una observación.
5. Puede «Guardar borrador» (sin valoración) o «Enviar evaluación» (con valoración obligatoria). En «Mis pruebas» ve el avance
   de las 8 pruebas y reabre sus evaluaciones.
6. Si la respuesta no trae registro, evalúa «sin registro»: escribe la consulta y no se guarda ningún resultado inventado.

**Panel interno** (rol `super_admin`, grupo «CIRA»):
- **Campañas:** crear, abrir y cerrar, con fechas opcionales.
- **Evaluadores:** se invita a cuentas existentes con el correo verificado, cada una con un seudónimo E-NN. Se revocan sin
  borrar; volver a invitar reactiva el mismo seudónimo.
- **Evaluaciones recibidas:**
  - filtros por campaña, prueba, valoración, estado, hallazgo y evaluador;
  - el detalle muestra la instantánea verificada con SHA-256, los votos y los votos no vinculados;
  - «Clasificar hallazgo» (datos, taxonomía y sinónimos, indexación, orden, explicación u otros).
- **Informe consolidado:**
  - por prueba: valoraciones, borradores y evaluadores;
  - empresas faltantes y sobrantes, votos, hallazgos y comentarios;
  - la **decisión de la Cámara** por prueba (Aprobado / Observado / Rechazado) se registra a mano y nunca se calcula;
  - CSV opcional, con las fórmulas neutralizadas.

**Separación de planos:** el **resultado técnico** es la instantánea inmutable; el **juicio sectorial** son las valoraciones;
la **decisión de la Cámara** es explícita. Ninguna pantalla declara aceptado el buscador.

## 2. Decisiones de UX y de arquitectura

| Decisión | Por qué |
|---|---|
| Páginas dentro del panel existente (`/admin/evaluacion-cira/...`) | Reutiliza la autenticación, la sesión, CSRF, el diseño adaptable y la accesibilidad de Filament. No hay sistema de identidad nuevo |
| CIRA Test enlaza directo al panel; **no hay rutas públicas nuevas** | La ruta sugerida `/cira-test/evaluacion` cuelga de una carpeta estática real; fuera de nginx se resuelve mal (lo detectó la E2E). Enlazar al panel deja 0 rutas públicas nuevas |
| Acceso por **invitación por campaña**, no por rol o permiso global | Cumple «no roles/permisos globales». Las páginas y recursos nuevos se excluyen de Shield, así que el editor de roles no cambia |
| Una búsqueda registrada solo se evalúa **desde la sesión de navegador que la hizo** (`session_ref` = sesión de CIRA Test) | Impide leer o evaluar la búsqueda de otra persona por su id (IDOR). Verificado en staging: los 4 votos existentes tienen `voter_ref` igual a `session_ref` |
| Instantánea inmutable + SHA-256 + trigger de BD | Un cambio posterior del ranking no altera la evidencia; la BD rechaza editarla |
| Votos leídos de `cira_search_feedback`, nunca copiados | Una sola fuente de verdad. Un voto de una empresa ajena a la instantánea se muestra como «no vinculado» |
| Selects nativos | Funcionan bien en el teléfono, con el teclado y con lectores de pantalla |

## 3. Modelo de datos (migración `2026_11_13_130000_create_cira_evaluation_tables`)

| Tabla | Claves y restricciones |
|---|---|
| `cira_eval_campaigns` | `code` único con formato controlado; estado `draft/open/closed`; ventana opcional con CHECK |
| `cira_eval_invitations` | FK a la campaña (CASCADE) y a `users` (SET NULL); seudónimo `E-NN` único por campaña; un usuario por campaña; `revoked_at`, `expires_at` |
| `cira_evaluations` | FK a la campaña y a la invitación (RESTRICT) y a `cira_search_log` (SET NULL: la instantánea se conserva); prueba CPV-01..08; valoración, motivo y hallazgo con CHECK; longitudes con CHECK; `client_token` único; índice único (invitación, prueba, búsqueda); trigger `cira_evaluations_keep_evidence` |
| `cira_eval_test_decisions` | Única por (campaña, prueba); decisión con CHECK |

Las cuatro tablas tienen RLS activado y REVOKE ALL a `anon` y `authenticated`. Las tablas `cira_search_log` y
`cira_search_feedback` no se tocan; su migración de TASK-0013 (lote 61 en la BD compartida) va incluida tal cual para que la
BD aislada de pruebas las tenga. En vivo, `migrate` la ve como ya ejecutada.

## 4. Pruebas

**Runner aislado sobre `5e6e3fc`:** módulo + inventario de superficie OK (24 pruebas, 410 aserciones, run `20261113T134219Z-5e6e3fc0288c`); **suite completa OK (826 pruebas, 8969 aserciones, run `20261113T134329Z-5e6e3fc0288c`)**, con staging 200 antes y después y 0 contenedores sobrantes. Las 23 pruebas del módulo cubren:
- rutas, roles, acceso anónimo y acceso revocado, vencido o cerrado, también con la página ya abierta;
- IDOR de búsquedas y de evaluaciones, y propiedades bloqueadas;
- validaciones, doble clic, segunda pestaña y token idempotente;
- reanudación; instantánea inmutable (cambio del ranking y trigger); registro borrado;
- búsquedas sin resultados, con error y latencia, y sin registro;
- XSS en la consulta y en el comentario;
- votos preexistentes no duplicados y no vinculados;
- informe y decisión explícita; clasificación; invitaciones con seudónimo;
- migración idempotente y reversible con RLS y REVOKE; tablas de TASK-0013 de solo lectura; CSRF; guía.

**E2E de navegador (runner aislado, escritorio y teléfono Pixel 5): 40/40.**
- CIRA Test → búsqueda → 👍 → «Evaluar esta búsqueda» → inicio de sesión → formulario con la búsqueda y el voto previo → 8
  pruebas → borrador → doble clic → «Mis pruebas» → reabrir → enviar → progreso → guía con las 8 → 403 del evaluador en el
  panel → panel: lista con seudónimo, detalle con hash verificado y HTML como texto, informe.
- Sin desbordamiento horizontal en el teléfono.
- n8n se respondió dentro del navegador, sin tocar el real.

**Ensayo de la migración en un clon** de la BD compartida (respaldo de TASK-0026, ICU `en-US`, ACL de Supabase):
- subida ×2: 4 tablas, 11 FK, RLS 4/4, 0 privilegios de los roles de la API, trigger activo;
- 88 tablas existentes idénticas;
- comportamiento correcto con FK reales;
- bajada: datos y esquema idénticos al inicio.

## 5. Antes y después

El candidato no toca el buscador, el ranking, embeddings, índices, la taxonomía ni los datos de empresas: el diff se limita a
archivos del módulo. Los 13 flujos CIRA de n8n tienen la misma versión antes y después (lectura `GET`). Staging y la BD
compartida siguen sin cambios.

## 6. Evidencia (fuera de git)

- Capturas de la E2E (datos sintéticos): `<CPV_TEST_ROOT>/artifacts/20261113T134020Z-5e6e3fc0288c/ux/`. Son
  `desktop_1..4`, `phone_1..4` y `panel_1..3`.
- Ensayo del clon: salida en la tarea. Contenedores eliminados.
- Despliegue: respaldo `/root/backups/cira-t27-20261113T140809Z` en el servidor (700/600), con `monitor_check.sh`,
  `deploy_start_utc.txt` y `e2e/`. La carpeta `e2e/` guarda las capturas de la E2E en vivo, que muestran nombres reales
  de empresas de la búsqueda real; los resultados del panel; y `seed.env` sin cookie ni id de sesión.

## 7. Despliegue en staging (2026-11-13)

| Paso | Resultado |
|---|---|
| Preflight reforzado de TASK-0026 (monitorización parcial, T+2 h 22 min) | PASS, sin anomalías. La ventana original queda **interrumpida por excepción expresa**, sin PASS |
| Preflight de TASK-0027 | PASS: *fast-forward* `6e7f525` → `5e6e3fc`; solo las migraciones del módulo; `2026_11_06_120000` ya en el lote 61; sin despliegues concurrentes |
| Respaldo nuevo y restauración en el clon ICU | PASS: dump con sha256 verificado; datos, RLS y ACL del clon idénticos al vivo |
| Secuencia exacta del workflow en el clon (imagen viva + migraciones candidatas) | PASS: `db:skip` sin cambios; `migrate:status` y `--pretend` muestran **solo** `2026_11_13_130000`; `migrate --force` la deja en el lote 64; rollbacks A y B devuelven datos y esquema idénticos |
| Despliegue | *Fast-forward* sin force; workflow `43440450319` success; migración sola en el lote 64 |
| Verificación posterior | PASS: 4 tablas, 11 FK, RLS 4/4, 0 privilegios de la API, trigger activo; tablas, ACL y roles existentes idénticos; vistas de SEC-01 idénticas; SEC-03 intacta; 0 errores y 0 respuestas 5xx; n8n sin cambios |
| E2E en vivo, escritorio y Pixel 5 | **45/45**: búsqueda real por n8n → 👍 real → evaluar → borrador → reanudar → enviar → guía → IDOR y 403 |
| Panel `super_admin` en una transacción revertida | PASS: lista con seudónimo, detalle con hash verificado, informe y decisión explícita |
| Limpieza de los datos sintéticos | Hecha: tablas del módulo en 0; usuarios en 368 |
| Monitorización de 24 h | Desde 14:11:57Z, con lecturas manuales a T+4 h, T+12 h y T+24 h |

La sesión de la cuenta sintética de evaluador se emitió en el servidor: Turnstile no se desactivó y no se usaron claves de
prueba. El login con Turnstile lo comprueba el propietario al crear la campaña real.

**Aclaración del incidente del ensayo 1:** el «no» de identidad de datos sin detalle venía de comparar con `diff` huellas
**sin ordenar**: `UNION ALL` no garantiza el orden de las filas. Con las huellas ordenadas, las comparaciones coinciden.

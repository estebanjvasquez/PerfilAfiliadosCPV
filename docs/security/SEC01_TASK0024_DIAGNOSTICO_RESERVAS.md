# SEC-01 / TASK-0024 — Diagnóstico y plan de cierre de las reservas de TASK-0023

**Fecha:** 2026-11-13. **Tarea:** [`tasks/0024-sec01-t23-reservations-diagnosis.md`](../orquestador/tasks/0024-sec01-t23-reservations-diagnosis.md)
(Issue #2 `6848913937`, decisiones del propietario `6849259532`).
**Alcance:** solo análisis, lecturas de staging y pruebas aisladas. En staging no hubo escrituras, migraciones ni cambios de
FK, roles, permisos o usuarios. Staging sigue en `270e07dcda8c61840c569b5c85cbad471ce9bf82` (servidor y remoto, verificado).

Para los lectores: R1 es el rol con 1 usuario y R2 el rol con 0 usuarios. Sus nombres están en notas restringidas fuera de git.

## 1. Resumen

| Reserva | Tipo | Estado propuesto | Prioridad |
|---|---|---|---|
| R-01 UAT visual del editor | UAT | **Cerrada** por confirmación del propietario, en el alcance declarado | — |
| R-02 R1 `view_any_user` | Decisión de datos | **Cerrada**: no restaurar (decisión expresa) | — |
| R-03 seis `*_shield::role` inertes | Higiene, residuo de TASK-0021 | **Cerrada** por aceptación expresa del riesgo residual | — |
| R-04 FK de `contact_empresa` con 2 huérfanas | Deuda preexistente (importación) | Remediación **demostrada en copia aislada**; falta el gate para aplicarla en staging | P2 |
| R-05 límites de R4 | Riesgo residual y verificación | Verificado. El límite de R4 es una capacidad interna: ninguna ruta de la aplicación lo usa. Aparecieron **2 hallazgos nuevos** (§3.5) | P1 (hallazgos) |
| R-06 SEC-01 | Programa abierto | R3-02 y R3-03 en una tarea independiente posterior (candidato aislado, sin desplegar); el resto sigue abierto | P1 |

**Hallazgos nuevos** (reproducido o verificado por catálogo; ninguno explotado contra staging):
- **F-SEC01-T24-01 (media, latente):** un usuario sin `super_admin` que tenga `delete_user` (y `view_any_user` para ver la lista)
  puede **borrar una cuenta `super_admin`** con la acción «Eliminar» de la lista de usuarios. Al borrarla también se pierde su
  rol privilegiado. Está reproducido en aislamiento. Hoy ningún rol real no privilegiado tiene esos permisos, así que no lo
  puede usar ninguna cuenta actual.
- **F-SEC01-T24-02 (alta potencial, exposición de lectura):** 13 vistas de `public` se ejecutan con los derechos de su
  propietario (`postgres`, con BYPASSRLS), sin `security_invoker`, y el rol `anon` de la Data API de Supabase puede leerlas.
  La API responde (401 sin clave), así que quien tenga la clave anónima del proyecto podría leer esas vistas saltándose RLS.
  Una de ellas incluye datos de contactos. **No se reprodujo**, porque no se usó ninguna clave.

## 2. Matriz de reservas

| ID / fuente | Evidencia verificable | Severidad / impacto actual / probabilidad | Condición de cierre | Plan mínimo y coste | Pruebas de aceptación | Gate humano |
|---|---|---|---|---|---|---|
| **R-01** (`6848907151` §1) | Confirmación del propietario del 2026-11-13 sobre el runtime `270e07d` (`6849259532`). Aparte, la UAT previa del editor sobre `71e8a6d` (`6829143372`). Validación sintética en vivo de TASK-0023 | — / ninguno / — | Confirmación del propietario en el alcance declarado: sección «Role» con 6 `*_role` en solo lectura y sin `*_shield::role`, **sin guardar roles reales** | Ninguno | — | Ya otorgado. El desarrollador no declara PASS de UAT humana |
| **R-02** (`6848907151` §2) | Lectura: R1 sin `view_any_user` (0), 1 miembro, 205 vínculos; R2 con 0 miembros y 42 vínculos | Baja / R1 no ve la lista de usuarios / — | Decisión expresa de no restaurar | Ninguno | — | Una necesidad futura requiere autorización nueva |
| **R-03** (`6848907151` §3) | Lectura: 6 filas `*_shield::role`, 0 vínculos con roles y 0 directos. Código: ninguna referencia funcional (solo un comentario en `RolePermissionIdentifier`). La clave del recurso es `role`, así que el editor ya no las muestra | Muy baja / ninguno / — | Aceptación expresa del riesgo residual | Conservar las filas, sin DELETE | — | Un borrado futuro requiere autorización |
| **R-04** (`6848907151` §4) | Lectura (§3.4): 2 filas cuyo contacto no existe. FK viva validada y triggers activos. Copia aislada: remediación, FK validada, restauración limpia y rollback exacto | Baja / sin efecto funcional (las filas son invisibles); la restauración de respaldos necesita un paso manual / no reaparecen sin privilegios de superusuario | Staging sin huérfanas; un `pg_dump` nuevo se restaura con exit 0 y 101 FK validadas | §3.4: archivar 2 filas fuera de la BD y borrarlas por clave exacta con guardas. Sin DDL en vivo. **~1 h** | Las de §3.4, repetidas sobre un respaldo nuevo | **Sí**: autorización que nombre las 2 filas y la operación |
| **R-05** (`6848907151` §5) | §3.5: inventario de escritores; runs `20261113T100621Z-8e8b80c2b702` y `20261113T100718Z-8e8b80c2b702`; catálogo de privilegios de BD | Riesgo residual de R4: bajo (interno). Hallazgos: ver §3.5 | Límite documentado y fijado con una prueba; hallazgos registrados con su decisión | Ver hallazgos | Ver hallazgos | Para cada corrección |
| **R-06** (`6848907151` §6) | Registro: F-SEC01-R3-02 y R3-03 confirmados en aislamiento | Media y baja / ver registro | Tarea independiente con candidato y pruebas | §4, fase 3 | fails-before, regresión y suite completa | Despliegue aparte |

## 3. Diagnóstico por reserva

### 3.1 R-01 UAT visual

Hay dos evidencias, distintas entre sí:
1. **2026-11-12, runtime `71e8a6d`** (`6829143372`): el propietario probó el editor en el navegador sin guardar roles reales. Esa
   versión todavía mostraba las 6 casillas `*_shield::role`.
2. **2026-11-13, runtime `270e07d`** (`6849259532`): el propietario confirma que verificó el editor corregido.

Lo que queda probado: la sección «Role» muestra los 6 `*_role` reales en solo lectura y ya no aparece `*_shield::role`. Lo que
no queda probado: el guardado de roles reales. Ese guardado está cubierto solo por las validaciones sintéticas de
TASK-0021/0023, en transacciones que se revierten.

### 3.2 R-02 R1 `view_any_user`

Por lectura: R1 no tiene `view_any_user`. Efecto funcional: su único miembro no puede abrir la lista de usuarios, porque
`UserPolicy::viewAny` exige ese permiso. R2 no tiene miembros. La reserva queda cerrada por decisión del propietario.

Si alguna vez se necesitara, el procedimiento sería: un `super_admin` abre Roles → R1 → sección «Usuario», marca solo «Ver
cualquiera» y guarda; para revertirlo, la desmarca. Requiere autorización nueva. **No** dar `delete_user` a roles no
privilegiados mientras F-SEC01-T24-01 siga abierto.

### 3.3 R-03 permisos inertes

| Opción | Ventajas | Riesgos | Coste |
|---|---|---|---|
| **No hacer nada** (decidido) | Cero escrituras, sin riesgo de borrar algo equivocado | Ruido en la tabla `permissions` (6 de 508 filas). Nadie las concede: el editor ya no las muestra y nada las comprueba | 0 |
| Borrado controlado | Tabla más limpia | DELETE en datos reales compartidos, con respaldo, conteos y rollback, para un beneficio estético | ~1 h y un gate |

La reserva queda cerrada por aceptación expresa. Si `shield:generate` u otra sincronización de Shield volviera a crear nombres
con `shield::`, conviene revisarlo; con la clave fijada en `role` no debería pasar.

### 3.4 R-04 FK `contact_empresa_contact_id_foreign` y 2 huérfanas

**Inventario (solo lectura en staging, 2026-11-13):**

| Fila (sin clave primaria: se identifica por el par `(empresa_id, contact_id)`; claves exactas en la nota restringida R-04) | `is_principal` | Timestamps | Contacto | Empresa |
|---|---|---|---|---|
| E1 / C1 | false | nulos | no existe; ningún otro vínculo | activa; 11 contactos válidos; sin principal |
| E2 / C2 | false | nulos | no existe; ningún otro vínculo | activa; 4 contactos válidos; sin principal |

- `contact_empresa`: 540 filas, sin clave primaria ni índices, sin duplicados, 0 filas con empresa inexistente. No está en
  ninguna publicación de replicación, así que un DELETE no lo bloquea la identidad de réplica.
- **FK viva validada** (`convalidated = true`, NO ACTION) y sus triggers internos activos (`O`); `session_replication_role` está
  en `origin`. La FK de `empresa_id` también es válida. Es la única FK de `public` con huérfanas: en la restauración aislada,
  las otras 100 se recrean válidas.
- **Causa probable: residuo de la importación desde MySQL.** Los ids C1 y C2 son huecos dentro de series consecutivas de
  contactos que se cargaron para esas dos empresas el 2026-08-06, unos minutos antes y después. Eso apunta a contactos
  creados y luego borrados en el sistema de origen. La secuencia de `contacts` está por encima de ambos, así que esos ids existieron. Ningún
  `contact_empresa` tiene timestamps: son vínculos heredados. Una FK validada con huérfanas solo es posible si las filas se
  cargaron con la aplicación de FK desactivada. Hoy `postgres` **no** puede hacerlo: no puede fijar `session_replication_role`
  ni desactivar triggers internos sin ser superusuario. Por eso no se espera que reaparezcan. No hay tabla de auditoría que
  permita recuperar los datos de esos contactos.
- **Clasificación:** registros obsoletos. Son invisibles en la aplicación (la relación hace JOIN con `contacts`) y no tienen
  valor de negocio recuperable.

**Alternativas:**

| Alternativa | Pérdida de información | Valoración |
|---|---|---|
| A. No hacer nada (estado actual) | Ninguna | Las restauraciones siguen necesitando recrear la FK como `NOT VALID`: viables, pero no íntegras en sentido estricto |
| B. Reconectar a un contacto válido | Crea un vínculo inventado | **Descartada**: nada indica cuál sería el contacto correcto, y la empresa ya tiene vinculados sus otros contactos |
| C. Crear contactos de relleno C1/C2 | Inventa datos personales | **Descartada** |
| **D. Archivar fuera de la BD y borrar las 2 filas (recomendada)** | Ninguna: el par queda en un CSV con sha256 dentro del respaldo | Corrección mínima; en vivo no requiere DDL porque la FK ya está validada |

**Demostración en copia aislada** (2026-11-13, sobre el volcado de TASK-0023 con sha256 verificado; PostgreSQL 17 + pgvector
desechables en red `--internal` y sin puertos; evidencia en `/root/backups/sec01-t24-r04-rehearsal-20261113T094404Z`, fuera
de git):

| Paso | Resultado |
|---|---|
| Copia A restaurada (FK recreada `NOT VALID`) | Las mismas 2 huérfanas que en vivo |
| Archivo | `orphans_archive.csv` con 2 filas y su sha256 |
| Remediación (un bloque `DO` con guardas: exactamente 2 huérfanas, borra solo el par exacto con `NOT is_principal` y contacto inexistente, y aborta si algo difiere) | 2 filas borradas; 0 huérfanas |
| `VALIDATE CONSTRAINT` (en la copia; en vivo no hace falta) | `validated=true`; una huérfana nueva se rechaza |
| Checksum md5 por tabla (88 tablas, `COLLATE "C"`) | Solo cambia `contact_empresa` (540 → 538) |
| Integridad semántica | Vínculos visibles empresa-contacto idénticos (538); la única vista que lee la tabla es idéntica; E1 y E2 conservan 11 y 4 contactos |
| `pg_dump` de la copia remediada y restauración en una BD **B** nueva | **exit 0, 0 errores**; 101 FK, 0 sin validar; 0 índices inválidos; 133 migraciones; 88 tablas idénticas a A; roles idénticos a la línea base de TASK-0023 |
| Rollback en A (quitar la FK, reinsertar desde el CSV verificado y recrear la FK `NOT VALID`) | 88 tablas idénticas al estado previo; FK `validated=false` |

**Plan para staging (pendiente de gate):**
1. Respaldo nuevo (`pg_dump` con sha256) y conteos.
2. Exportar las 2 filas a CSV con sha256 dentro del directorio del respaldo.
3. Ejecutar el mismo bloque `DO` (`remediate.sql` de la evidencia) en una transacción.
4. Verificar: 0 huérfanas, 538 filas y el resto de tablas sin cambios.
5. `pg_dump` nuevo y restauración aislada con exit 0.

**Rollback en vivo:** reinsertar desde el CSV. Como la FK viva está validada, hay que quitarla y recrearla `NOT VALID`, que es
DDL y queda dentro de la misma autorización. La otra vía es restaurar el respaldo. No vuelve exactamente al estado anterior
(FK validada con huérfanas), porque ese estado no se puede reproducir sin superusuario.

**Atención:** la base de staging la comparten el desarrollo local y staging. La autorización debe citar las 2 filas de la nota
restringida R-04 (el script lleva las claves exactas y aborta si no coinciden), la operación y el entorno. Producción va aparte.

### 3.5 R-05 escritores de roles y permisos

**Inventario de escritores** (código del runtime `270e07d`):

| Escritor o punto de entrada | Control en servidor | Evidencia | Clasificación |
|---|---|---|---|
| Editor de roles de Shield (crear, editar, borrar, en lote, por Livewire directo) | `RolePolicy`: `super_admin` vigente + permiso `*_role`; R4-01 escribe solo lo renderizado y editable; sección «Role» en solo lectura | `RoleAdministrationTest`, `RoleEditorPermissionPreservationTest`, `Sec01Task0021*`, `Sec01Task0022*`: verdes en el run de 42 | Controlado |
| Definición del rol privilegiado (nombre, guard, borrado, homónimos) | `PrivilegedRoleDefinitionGuard` (eventos de modelo) | Prueba nueva: la escritura de modelo se rechaza | Controlado para escrituras de modelo |
| Selector de roles del formulario de usuario (crear y editar) | `PrivilegedRoleGuard` antes del `sync`; edición de una cuenta privilegiada bloqueada en mount | `AccountPrivilegeTest`, `AccountWriteAtomicityTest`: verdes. Prueba nueva: un objetivo promovido después de abrir la página se rechaza **ya al rehidratar** | Controlado |
| «Eliminar» en la página de edición de usuario | Guard de mount | Prueba nueva: rechazado en mount (403) | Controlado |
| **«Eliminar» en la lista de usuarios** | Solo `UserPolicy::delete` → `delete_user`, que no recibe la cuenta objetivo | Prueba nueva: **la cuenta `super_admin` se borra** (rojo esperado); el control positivo borra una cuenta ordinaria | **F-SEC01-T24-01** |
| Usuarios de una empresa (relation manager) | Solo `DetachAction` sobre `empresa_user`; reautorización R3 | Revisión de código | No escribe roles |
| Registro público | Crea cuentas ordinarias | `AccountPrivilegeTest` | Controlado |
| Suplantación | Solo `super_admin` (F-01) | `ImpersonationActionTest` | Controlado |
| Seeders, `shield:*`, `permission:*`, tinker | Exigen shell en el servidor o el contenedor | — | Capacidad interna |
| Query builder, `saveQuietly`, `withoutEvents`, `DB::table` sobre `roles` | **No** pasan por el guard de R4 | Prueba nueva: los 4 lo saltan (límite confirmado); el inventario estático encuentra **0** usos en `app/` y `routes/` | Capacidad interna; ninguna ruta alcanzable |

**Base de datos (catálogo, solo lectura):**

| Hecho | Clasificación |
|---|---|
| La aplicación conecta a Supabase por el pooler como `postgres`: propietario de todas las tablas, BYPASSRLS, CREATEROLE, miembro de `service_role`; no es superusuario | Capacidad interna. Quien tenga el `.env` de staging o del desarrollo local (comparten la BD) puede escribir roles directamente. Mitigación: mínimo privilegio (P2) |
| Data API de Supabase (REST y GraphQL) activa: responde 401 sin clave | Punto de entrada externo |
| `anon` y `authenticated` tienen todos los privilegios de tabla (valores por defecto de Supabase), pero **RLS está activo en 88/88 tablas con 0 políticas**: deniegan todo a esos roles. Hay además un trigger de evento (`ensure_rls`, función `rls_auto_enable`, en `ddl_command_end`) que, por su nombre, activa RLS en las tablas nuevas; su cuerpo no se revisó | Sin escritura de roles posible por la API con la clave anónima. PostgREST no expone TRUNCATE. La única función `SECURITY DEFINER` es de trigger de evento y no se puede invocar |
| `service_role` tiene BYPASSRLS | Quien tenga esa clave escribe todo. No se inspeccionó qué integraciones la usan (por ejemplo, el registro de CIRA en n8n, fuera de alcance) |
| 13 vistas de `public`, propiedad de `postgres` y sin `security_invoker`, que `anon` puede leer (ninguna es actualizable ni lee tablas de roles) | **F-SEC01-T24-02**: exposición de lectura que salta RLS. Exige la clave anónima; no reproducido |

**F-SEC01-T24-01. Propuesta proporcional:** `UserPolicy::delete`, `forceDelete` y `restore` reciben la cuenta objetivo y la
deniegan si es privilegiada y el actor no es `super_admin` (con `PrivilegedRoleGuard`). La prueba de reproducción sirve como
fails-before. Coste ~2–3 h con la suite. Mientras tanto, no conceder `delete_user` a roles no privilegiados.

**F-SEC01-T24-02. Propuesta proporcional:**
1. Comprobar dónde se usa la clave anónima del proyecto. Si se compartió fuera del equipo, tratarlo como P0.
2. Fijar `security_invoker = true` en las 13 vistas. La aplicación conecta como propietario con BYPASSRLS, así que no cambia;
   para `anon` quedarían vacías. Alternativas: revocar `SELECT` de `anon` y `authenticated`, o dejar de exponer `public` en la
   Data API, tras confirmar que ninguna integración la necesita.
3. Hacerlo con una migración versionada y pruebas. Coste ~1–2 h.

Es un cambio de BD compartida (desarrollo local + staging), así que necesita gate.

### 3.6 R-06 SEC-01

Siguen abiertos: **F-SEC01-R3-02** (acciones de módulos sin el permiso de escritura propio) y **F-SEC01-R3-03** (alta de una
categoría inactiva por id forjado), ambos autorizados para la siguiente tarea independiente. También siguen abiertos 14 de 24
relation managers, 9 búsquedas y F-SEC01-03/F-SEC01-06, más los nuevos F-SEC01-T24-01 y -02. Esta tarea **no** materializa ni
implementa nada de esto.

Alcance propuesto para la siguiente tarea, según `6849259532` §5:
- **R3-02:** autorizar con el permiso de escritura del módulo en cada acción identificada, también en la ejecución de
  Livewire después de rehidratar; denegar payloads forjados y accesos revocados.
- **R3-03:** validar en el servidor que la categoría esté activa, incluso con un id forjado o si cambia de estado entre el
  montaje y el guardado.

Ambas con fails-before, regresión positiva y negativa y suite completa, sin push a la rama de runtime. F-SEC01-T24-01 solo
entraría con una autorización expresa.

## 4. Plan por fases

| Fase | Contenido | Coste aprox. | Autorización necesaria |
|---|---|---|---|
| 0 | Registrar cierres R-01, R-02 y R-03 (esta entrega) | — | Revisión de esta entrega |
| 1 (P1) | F-SEC01-T24-02: inventario de dónde está la clave anónima, y después `security_invoker` en las 13 vistas | 1–2 h | Decisión del propietario sobre la BD compartida |
| 2 (P1) | R-06: candidato aislado de R3-02 y R3-03 | 1–2 días | Ya autorizado como candidato; tarea nueva tras revisar esta entrega |
| 2b (P1) | F-SEC01-T24-01: política de borrado con objetivo + prueba | 2–3 h | Asignación (o inclusión expresa en la fase 2) |
| 3 (P2) | R-04 en staging, con el procedimiento de §3.4 | ~1 h | Autorización que nombre las 2 filas y la operación |
| 4 (P2) | Mínimo privilegio en BD: rol de la aplicación sin propiedad ni BYPASSRLS, rol de migraciones aparte, rol de lectura para integraciones; inventario de quién tiene `postgres`/`service_role`; el inventario estático de R-05 dentro de la suite | 1–2 días (plan) | Solo planificación; cualquier cambio de roles de BD o credenciales requiere gate |
| — | Despliegues de las fases 1, 2 y 2b | — | Gate de despliegue en staging, por separado. Producción y `main`, aparte |

## 5. Qué no hacer

- No ejecutar `remediate.sql` ni ningún DELETE o DDL en staging sin la autorización de §3.4. No desactivar FK ni triggers, no
  usar el modo de réplica, no crear contactos de relleno y no reconectar las huérfanas a otro contacto.
- No borrar las 6 filas `*_shield::role` ni restaurar `view_any_user` en R1. No tocar R2.
- No conceder `delete_user`, `delete_any_user` ni `view_any_user` a roles no privilegiados mientras F-SEC01-T24-01 siga abierto.
- No usar la clave anónima ni la de `service_role` para «probar» F-SEC01-T24-02 sin autorización. No cambiar la configuración
  de la Data API sin revisar antes las integraciones (n8n).
- No ampliar la fase 2 a F-SEC01-03/-06, relation managers ni búsquedas.
- No desplegar, no hacer merge a `main` y no tocar producción, HSTS (`max-age=86400`), Cloudflare, nginx ni el túnel.

## 6. Evidencia

- Lecturas en staging, 2026-11-13: tres sondas PHP en el contenedor, cada una en una transacción `READ ONLY` que se revierte;
  solo ids y conteos. Más una petición sin clave a la raíz de la Data API, sin registrar el identificador del proyecto.
  Servidor en `270e07d` y árbol limpio.
- Ensayo de R-04: `/root/backups/sec01-t24-r04-rehearsal-20261113T094404Z` (700, fuera de git): CSV de archivo y su sha256,
  `remediate.sql`, `rollback.sql`, checksums antes, después y tras el rollback, y logs de las dos restauraciones.
  Contenedores eliminados.
- Pruebas de R-05: rama `audit/task-0024-r05-writer-paths` @ `8e8b80c2b7027ab24a8bc68fad713794300712a0`, desde `270e07d`,
  solo pruebas y nunca para desplegar; archivo `tests/Feature/Security/Sec01Task0024WriterPathsTest.php`.
  - Run `20261113T100621Z-8e8b80c2b702` (solo el archivo nuevo): 6 pruebas, 27 aserciones; 5 verdes y 1 roja esperada (la
    reproducción).
  - Run `20261113T100718Z-8e8b80c2b702` (seguridad de cuentas y roles): 42 pruebas, 372 aserciones; 41 verdes y la misma roja
    esperada.
  - Staging respondió 200 antes y después de cada run; 0 contenedores remanentes.
  - Los commits `408f470` y `58ac0e1` corrigieron el arnés: la página rechazada en mount y el `fillForm` de varias peticiones
    tras un 403. No cambiaron el resultado de seguridad.

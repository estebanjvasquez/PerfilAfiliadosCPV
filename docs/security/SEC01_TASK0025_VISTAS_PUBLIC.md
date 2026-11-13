# SEC-01 / TASK-0025 — F-SEC01-T24-02: vistas de `public` con derechos del propietario

**Fecha:** 2026-11-13. **Tarea:** [`tasks/0025-sec01-t24-02-public-views-hardening.md`](../orquestador/tasks/0025-sec01-t24-02-public-views-hardening.md)
(Issue #2 `6850596236`). **Alcance:** análisis, migración versionada y pruebas en entornos desechables. En la BD compartida y
en staging no hubo DDL, cambios de grants ni despliegue. Staging sigue en `270e07dcda8c61840c569b5c85cbad471ce9bf82`.

## 1. Resumen

- **Hallazgo:** las 13 vistas de `public` pertenecen a `postgres` (BYPASSRLS), no tienen `security_invoker`, y `anon`,
  `authenticated` y `service_role` pueden leerlas. Las tablas base tienen RLS sin políticas, que deniega todo a los dos
  primeros roles, pero las vistas leen con los derechos del propietario y se lo saltan.
- **Exposición demostrada en aislamiento** con credenciales sintéticas: en un clon REST con PostgREST, `anon` lee por REST
  filas que RLS le niega en las tablas, incluida la vista que agrega contactos (§4). En vivo es **potencial**: no se usó la clave
  real ni se consultó la API con datos reales.
- **Consumidores:** solo Laravel, que conecta como el propietario. Ninguna integración usa `anon` ni `authenticated` (§3).
- **Corrección candidata:** una migración idempotente y reversible que fija `security_invoker = true` en las 13 vistas y revoca
  `anon` y `authenticated` (§5). No toca tablas base, RLS, políticas ni otros roles. El propietario y `service_role` leen
  exactamente lo mismo.
- **Pruebas:** fails-before 5/5 en rojo; candidato 5/5 en verde; suite completa **803/803** (8562 aserciones) en la repetición; la primera pasada tuvo 1 error ajeno e inestable (§6).
- **Gate:** el despliegue normal ejecuta `php artisan migrate --force` (`.github/workflows/deploy-contabo.yml`). Por eso
  integrar el candidato en la rama de runtime **aplica el DDL a la BD compartida** (desarrollo local + staging) y requiere
  autorización explícita (§7).

## 2. Inventario (catálogo de staging, solo lectura, 2026-11-13)

Común a las 13 vistas:
- propietario `postgres`; `reloptions` vacías (sin `security_invoker` ni `security_barrier`);
- `anon` y `authenticated` con todos los privilegios (`DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE`), igual que `service_role`;
- ninguna es actualizable, ninguna depende de otra vista y ninguna función las usa;
- las 21 tablas base tienen RLS activo, sin `FORCE` y con 0 políticas.

Alcance real por API: la Data API responde (401 sin clave). `pg_graphql` **no** está instalado, así que la vía es REST
(PostgREST). La lista de esquemas expuestos no está en la BD: se configura en el panel de Supabase y no se puede leer sin
credenciales. `anon` tiene USAGE sobre `public`.

Las vistas se crearon fuera de las migraciones de Laravel (`db:skip-mysql-only-view-migrations`), así que la BD aislada de
pruebas no las tiene. Las pruebas las crean desde sus definiciones vivas (`tests/Fixtures/Sec01PublicReportViews.php`, solo
esquema).

## 3. Matriz por vista

Consumidor común: Laravel (`pgsql`, rol `postgres` = propietario), a través de la página Filament de la vista y su modelo.
Ningún flujo de n8n lee estas vistas en Supabase. La única mención (`capacityView`) está en 3 flujos **inactivos** que usan la
herramienta MySQL de la base heredada.

| Vista | Filas (vivo) | Datos | Consumidores Laravel | Riesgo | Cambio | Antes → después (aislado) |
|---|---|---|---|---|---|---|
| `catalogoView` | 406 | **Personales**: contactos (nombre, cargo, correo, teléfono) + RIF, dirección, teléfono y web de la empresa | JoinViews, JoinViewExport, JoinedViewsController, GenCatalog | **Alto** | `security_invoker` + REVOKE | ver §4 |
| `FinanceView` | 406 | Empresa: empleados, rango de facturación, estado, capital, origen | página, JoinViews, JoinViewExport | Medio | ídem | ver §4 |
| `capacityView` | 406 | Empresa: servicios, RR. HH., instalaciones, maquinaria, inventario (resumen) | JoinViews, JoinViewExport, JoinedViewsController, CompanyDossier, GenCapacity | Medio | ídem | ver §4 |
| `ClientsView` | 2111 | Empresa: países de clientes por sector | página, JoinViews, JoinViewExport, CompanyDossier | Medio | ídem | ver §4 |
| `ExperienceView` | 974 | Empresa: proyectos (sector, tipo, región, año, magnitud, personal, descripción) | página, JoinViews, CompanyDossier | Medio | ídem | ver §4 |
| `ResourceView` | 109 | Empresa: personal por nivel | página, ResourceExport, JoinViews, CompanyDossier | Medio | ídem | ver §4 |
| `FacilityView` | 110 | Empresa: instalaciones (cantidad, superficie, tenencia) | página, FacilityExport, JoinViews | Medio | ídem | ver §4 |
| `InventoryView` | 81 | Empresa: inventario (cantidad, valor estimado) | página, InventoryExport, JoinViews | Medio | ídem | ver §4 |
| `MachineryView` | 98 | Empresa: maquinaria por tipo | página, MachineryExport, JoinViews | Medio | ídem | ver §4 |
| `ManagementView` | 406 | Empresa: certificaciones por área | página, ManagementExport, modelo `Empresa` | Bajo–medio | ídem | ver §4 |
| `ManagementDetView` | 99 | Empresa: certificaciones en detalle | página, ManagementDetExport, JoinViewExport, JoinViews | Bajo–medio | ídem | ver §4 |
| `PresenceView` | 75 | Empresa: oficinas y experiencia en el exterior | página, JoinViews | Bajo–medio | ídem | ver §4 |
| `SustainabilityView` | 406 | Empresa: áreas de sostenibilidad | página | Bajo | ídem | ver §4 |

«Riesgo» supone que alguien obtiene la clave anónima del proyecto. La clave anónima de Supabase puede ser pública por diseño,
y esta aplicación no la usa ni la distribuye.

## 4. Exposición demostrada y potencial

**Clon REST desechable** (2026-11-13; evidencia en `/root/backups/sec01-t25-rest-clone-20261113T111025Z`, fuera de git):
- Base: solo el esquema del volcado de TASK-0023 (sha256 verificado); **0 filas reales** en el clon.
- Roles tipo Supabase, con contraseñas y secreto JWT aleatorios. `app_owner` imita al `postgres` de Supabase: propietario de
  todo, BYPASSRLS, no superusuario.
- Privilegios por defecto como en vivo, RLS en 88/88 tablas sin políticas, y una empresa, un contacto y una certificación
  sintéticos.
- PostgREST v12.2.3 y curl en una red `--internal` sin puertos publicados.
- Resultado por petición REST: `HTTP:filas`. `anon` va sin JWT; `authenticated` y `service_role` con JWT sintéticos.

| Petición | Antes (anon / authenticated / service_role) | Después | Tras `down()` |
|---|---|---|---|
| `GET /contacts` (tabla, control) | 200:0 / 200:0 / 200:1 | igual | igual |
| `GET /catalogoView` | **200:1 / 200:1** / 200:1 (`anon` recibe el contacto sintético) | **401 / 403** / 200:1 | 200:1 / 200:1 / 200:1 |
| `FinanceView`, `ManagementView`, `ManagementDetView`, `SustainabilityView`, `capacityView` | **200:1 / 200:1** / 200:1 | **401 / 403** / 200:1 | como antes |
| Las otras 7 vistas (sin filas sintéticas que las alimenten) | 200:0 / 200:0 / 200:0, accesibles | **401 / 403** / 200:0 | como antes |

La huella md5 de la salida de las 13 vistas, leída como propietario, es **idéntica en los cuatro estados**
(`cd2549a99278`). La migración se aplicó como propietario no superusuario y dos veces seguidas (idempotente). `down()` devolvió
exactamente el estado observado en vivo.

**Exposición en vivo: potencial.** En staging se cumplen todas las condiciones del clon: Data API activa, `anon` con SELECT y
vistas con derechos del propietario. Falta un dato: si `public` está en los esquemas expuestos (lo habitual en Supabase) y quién
tiene la clave anónima. Confirmarlo exige el panel o la clave, así que queda fuera de este gate. No se usó ninguna clave real ni se
consultó la API con datos reales.

## 5. Corrección candidata

Rama `fix/sec-01-t25-public-views-hardening`, desde `270e07d`, con *fast-forward* limpio:

| Commit | Contenido |
|---|---|
| `56375f5` | Prueba `tests/Feature/Security/Sec01Task0025PublicViewsTest.php` + fixture `tests/Fixtures/Sec01PublicReportViews.php` (fails-before) |
| `6e7f525` | Migración `database/migrations/2026_11_13_000001_harden_public_report_views.php` |

**Por vista**, la misma mitigación, porque las 13 tienen el mismo perfil (sin vistas anidadas ni funciones, consumidor único:
el propietario):
1. `ALTER VIEW ... SET (security_invoker = true)`. La vista comprueba los privilegios y la RLS **del que consulta**. El
   propietario (dueño de las tablas, BYPASSRLS) y `service_role` (BYPASSRLS) no notan cambio; `anon` y `authenticated` reciben
   lo que la RLS les da en las tablas base, que hoy es nada.
2. `REVOKE ALL ... FROM anon, authenticated`. Es mínimo privilegio y una segunda barrera: un `CREATE OR REPLACE VIEW` sin la
   opción la borra en silencio, pero conserva los grants (prueba 5).

**No** se tocan tablas base, RLS, políticas, `service_role` ni otros objetos. La migración no hace nada si falta una vista, un
rol de la API o PostgreSQL. `down()` deja la opción sin fijar y vuelve a dar ALL a los dos roles, que es el estado observado.

**Regla para migraciones futuras de vistas** (prueba 5): un `DROP` + `CREATE` pierde las dos barreras y los privilegios por
defecto de Supabase vuelven a dar acceso a `anon`. Por eso hay que crear las vistas con `WITH (security_invoker = true)` y
revocarlas a los roles de la API. La invariante de la prueba 2 lo detecta para cualquier vista de `public`.

**Recomendaciones fuera de este alcance** (requieren decisión aparte):
- revocar a `anon`/`authenticated` los privilegios de escritura y TRUNCATE sobre las tablas base;
- ajustar los privilegios por defecto de Supabase en `public`;
- dejar de exponer `public` en la Data API si ningún cliente la usa.

## 6. Pruebas

| Run | SHA | Alcance | Resultado |
|---|---|---|---|
| `20261113T105506Z-56375f5f21ed` | `56375f5` (solo prueba) | `Sec01Task0025` | **5/5 en rojo, por la razón correcta**: `anon` lee filas de 5 vistas a las que alcanzan los datos sintéticos (incluido el contacto en `catalogoView`); la invariante marca las 13; las otras 3 pruebas fallan porque la migración no existe |
| `20261113T105643Z-6e7f52546a29` | `6e7f525` | `Sec01Task0025` | **OK (5 tests, 48 assertions)** |
| `20261113T105754Z-6e7f52546a29` | `6e7f525` | suite completa | 803 pruebas, 1 error ajeno: `CompanyDossierPdfTest` genera un código con `'CPV-'.random_int(900, 999)` que chocó con el fixture (inestable de por sí) |
| `20261113T110734Z-6e7f52546a29` | `6e7f525` | suite completa (repetición) | **OK (803 tests, 8562 assertions)**. Staging respondió 200 antes y después; 0 contenedores remanentes |

Qué cubren las 5 pruebas, con roles y datos sintéticos dentro de una transacción revertida:
1. `anon` y `authenticated` no leen nada a través de las 13 vistas, aunque sí reciben los privilegios por defecto; control:
   tampoco leen las tablas directamente.
2. Invariante: ninguna vista de `public` legible por un rol de la API corre con derechos del propietario.
3. Idempotencia y reversibilidad: `up` ×2, `down` (estado vivo exacto) y `up`.
4. Lectores legítimos: el propietario, un rol de aplicación (BYPASSRLS, no propietario) y `service_role` leen salidas md5
   idénticas antes y después; el modelo Laravel `GenCatalog` sigue viendo la empresa y su contacto.
5. Trampas documentadas: `CREATE OR REPLACE` sin la opción la borra, pero el REVOKE protege; `DROP` + `CREATE` pierde ambas y
   la invariante lo detecta; `CREATE ... WITH (security_invoker = true)` es seguro aunque haya grants por defecto.

## 7. Propuesta de despliegue (requiere gate explícito)

1. **Autorización** que nombre: la migración `2026_11_13_000001_harden_public_report_views`, la BD compartida (desarrollo
   local + staging) y la vía, que es el despliegue normal: un *fast-forward* de `feature/upgrade-filament-v3` a `6e7f525`, y el
   workflow ejecuta `migrate --force`.
2. **Respaldo previo:**
   - `pg_dump` con sha256, como en TASK-0023;
   - foto del catálogo de las 13 vistas: `reloptions` y ACL por rol;
   - huella md5 de la salida de cada vista leída como propietario;
   - etiqueta de la imagen actual.
3. **Aplicación:** el despliegue normal, sin *force*. El lote de `migrate` debe contener solo esta migración; se comprueba en
   `migrations`.
4. **Verificación posterior:**
   - catálogo: 13 vistas con `security_invoker = true`, `anon`/`authenticated` sin SELECT y `service_role` con SELECT;
   - huellas md5 idénticas a las previas;
   - las páginas de informes y una exportación, abiertas por `super_admin` sin guardar nada;
   - logs de Laravel sin `42501`;
   - salud: 0 respuestas 5xx y HSTS sin cambios.
   - Opcional, con autorización aparte: el propietario comprueba con la clave anónima que `GET /rest/v1/catalogoView`
     responde 401.
5. **Monitorización 24 h:** errores `permission denied` en los logs de Laravel y ejecuciones de los flujos activos de n8n
   (ninguno usa estas vistas).
6. **Rollback:** `php artisan migrate:rollback --step=1` en el contenedor, o el `DOWN_SQL` de la migración. Restaura el estado
   exacto, demostrado en el clon y en la prueba 3. Si además hace falta revertir el código, primero el rollback de la migración
   y luego `git revert`, sin *force*.

## Addendum 2026-11-13: despliegue en staging (TASK-0026)

El candidato `6e7f525` se desplegó en staging con la autorización de `6851565505`: workflow `43419358951`, migración sola en
el lote 63 y verificación posterior PASS. La monitorización de 24 h sigue en curso. Detalle en
[`tasks/0026-sec01-t24-02-staging-deploy.md`](../orquestador/tasks/0026-sec01-t24-02-staging-deploy.md).

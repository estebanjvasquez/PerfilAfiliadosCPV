# CPV — Esquema desde cero y fixtures sintéticos en PostgreSQL desechable

Ronda única para revisión manual. Entorno aislado de Contabo. El orquestador
autónomo sigue **suspendido**: esta ronda no hizo polling, no llamó a Anthropic
y no disparó ninguna revisión automática.

**Lo que esta ronda logró:** una base PostgreSQL vacía se construye desde las
migraciones, y la suite pasó de **25 fallos/errores a 4 fallos y 0 errores** sin
desactivar una sola restricción, sin omitir un solo test y sin tocar una
aserción.

**Qué significa «se construye» aquí**, precisado tras la revisión `6167931953`:
el juego de **tablas y migraciones probado**, no paridad con la base desplegada.
Quedan fuera a propósito las 15 vistas exclusivas de MySQL (marcadas como
corridas) y las 13 vistas PostgreSQL `2026_08_31_*` (sección 4), así que **este
fixture no es vista por vista idéntico a Supabase**. Decir «esquema completo» sin
esa salvedad sería inexacto.

---

## 1. Repositorio, rama y SHA

| | |
|---|---|
| Repositorio | `estebanjvasquez/PerfilAfiliadosCPV` (público) |
| Rama de trabajo **aislada** | `feature/isolated-test-readiness` |
| SHA base (de partida) | `a6e9db4711718ca053b9e8be0678be92cfe02ecb` (`feature/upgrade-filament-v3`) |
| **SHA exacto probado** | `d4ea4055ab96c43ce7c72232339ca6809d853ae3` |
| Arnés de pruebas | `infra/contabo-test-runner` @ `2e896639b374a1a1ad970a060604afe46a681131` |
| Rama hermana consultada | `feature/supplhi-postgres-buscador` @ `5df23fa9c5d0b93d5ccd0e155095a0e98726d6d0` |
| `run_id` | `20261014T092024Z-d4ea4055ab96` |

**Qué comparte y qué no con el SHA de la ronda anterior**, corregido tras la
revisión `6167931953`: la versión previa de este párrafo decía que *la base y el
SHA probado* tenían código idéntico a
`99e603cb4713fe0a349f7958af44327e5ae34845`, y eso era falso para el SHA probado.

* la **base** (`a6e9db4`) sí: entre `99e603cb` y ella sólo cambiaron `docs/` y
  `audit/` — `git diff 99e603cb..a6e9db4 -- app database tests config routes`
  sale vacío;
* el **SHA probado** (`d4ea4055`) **no**: es precisamente el que **cambia el
  juego de migraciones**. Lo que no cambia ahí es el código de **servicio** de la
  aplicación (`app/`, sin contar lo que tocan los parches de esta ronda).

Por tanto los números de la suite son comparables con los de la ronda anterior en
cuanto al código de servicio, pero **no** se trata del mismo esquema: esa es la
diferencia que esta ronda introduce a propósito.

**Ninguna rama se fusionó.** `feature/isolated-test-readiness` no está mezclada
en la rama de integración y el workflow de despliegue sólo dispara con push a
`feature/upgrade-filament-v3` (`.github/workflows/deploy-contabo.yml`, bloque
`on:`), así que esta rama no puede alcanzar staging ni producción.

### Artefactos de esta corrida

Todos en `audit/isolated_phpunit_2026-10-14b/`, en este mismo commit. Redactados:
la raíz de scratch del host, la ruta de la app viva y la dirección del puente
Docker se sustituyen por `<CPV_TEST_ROOT>`, `<LIVE_APP_DIR>` y
`<docker-bridge-ip>`. Ningún secreto, ninguna cadena de conexión completa,
ningún dato personal y ningún volcado de la base compartida.

| artefacto | qué prueba |
|---|---|
| [`summary.txt`](isolated_phpunit_2026-10-14b/summary.txt) | los cuatro grupos de resultados, con contadores |
| [`run.log`](isolated_phpunit_2026-10-14b/run.log) | la traza completa de la corrida, fase por fase |
| [`mounts.txt`](isolated_phpunit_2026-10-14b/mounts.txt) · [`mounts_phpunit.txt`](isolated_phpunit_2026-10-14b/mounts_phpunit.txt) | un solo bind mount, el workspace desechable |
| [`connections.txt`](isolated_phpunit_2026-10-14b/connections.txt) | las 5 conexiones resueltas, ninguna a Supabase |
| [`db_ports.json`](isolated_phpunit_2026-10-14b/db_ports.json) | la base del fixture no publica puerto |
| [`migration_deps.txt`](isolated_phpunit_2026-10-14b/migration_deps.txt) | 0 violaciones de orden, 0 tablas MISSING |
| [`migrate.log`](isolated_phpunit_2026-10-14b/migrate.log) | el replay sobre la base vacía |
| [`constraints.txt`](isolated_phpunit_2026-10-14b/constraints.txt) | el catálogo confirma las 4 FK con su definición |
| [`schema_fix.log`](isolated_phpunit_2026-10-14b/schema_fix.log) · [`schema_fix_applied.diff`](isolated_phpunit_2026-10-14b/schema_fix_applied.diff) | los 3 parches ya presentes; el diff aplicado está **vacío** |
| [`pgsql_bootstrap_migrations.txt`](isolated_phpunit_2026-10-14b/pgsql_bootstrap_migrations.txt) | los 8 archivos de `database/migrations/pgsql` |
| [`seed.log`](isolated_phpunit_2026-10-14b/seed.log) | los seeders y el comando de vocabulario del proyecto |
| [`fixture_load.log`](isolated_phpunit_2026-10-14b/fixture_load.log) | la carga del fixture y sus aserciones de forma |
| [`fixture_preconditions.txt`](isolated_phpunit_2026-10-14b/fixture_preconditions.txt) | las 8 precondiciones, PASS/FAIL una por una |
| [`fixture_rows.txt`](isolated_phpunit_2026-10-14b/fixture_rows.txt) | el inventario de filas, medido |
| [`phpunit.txt`](isolated_phpunit_2026-10-14b/phpunit.txt) | la salida de la suite y los 4 fallos |
| [`timestamp_probe.txt`](isolated_phpunit_2026-10-14b/timestamp_probe.txt) | la causa raíz de 3 de los 4 fallos, medida |

El fixture y el arnés viven en `infra/contabo-test-runner` @ `2e89663`, bajo
`docker/test/` — en particular
[`docker/test/fixtures/synthetic_fixture.sql`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/blob/2e896639b374a1a1ad970a060604afe46a681131/docker/test/fixtures/synthetic_fixture.sql).

---

## 2. Archivos modificados y por qué

### En `feature/isolated-test-readiness` (14 archivos)

| archivo | motivo |
|---|---|
| `2022_05_31_223828_create_empresas_table.php` | quita la declaración en línea de la FK `city_id` → `cities`, que se crea después |
| `2022_08_12_145310_create_sustainabilities_table.php` | ídem, `areas_id` → `areas` |
| `2022_09_01_154856_create_infratypes_table.php` | ídem, `infrasectors_id` → `infrasectors` |
| `2022_09_01_185804_create_infrafacilities_table.php` | ídem, `infraregion_infrasystem_id` → `infraregion_infrasystem` |
| **`2022_12_02_000000_restore_deferred_foreign_keys.php`** (nuevo) | vuelve a declarar las cuatro, ya existentes sus tablas; guardada contra bases ya migradas |
| `2022_06_13_231159_create_permission_tables.php` | el stub publicado es de spatie/laravel-permission v5; `composer.lock` instala 6.25.0 |
| `2026_07_24_000001_add_sub_type_to_empresa_module_status_table.php` | `indexExists()` consultaba `information_schema.statistics`, vista exclusiva de MySQL |
| 7 × `database/migrations/pgsql/2026_09_01_1200*.php` | la dependencia indispensable de la rama hermana: crean la tabla que seis migraciones referencian |

### En `infra/contabo-test-runner`

| archivo | motivo |
|---|---|
| `docker/test/fixtures/synthetic_fixture.sql` (nuevo) | el fixture sintético, definición versionada |
| `docker/test/run_tests.sh` | cuarto grupo de resultados (carga de fixtures), sonda de timestamps, y tres puntos donde el arnés daba por sentada la rama de integración |
| `docker/test/migration_deps.mjs` | seguía ningún `rename`, y por eso reportaba como MISSING una tabla que el replay construye sin problema |
| `.gitignore` | la regla `*.sql` habría descartado el fixture en silencio, igual que pasó antes con `restore_four_fks.sql` |

---

## 3. Diff de los tres parches, y su efecto sobre bases ya existentes

Los tres parches que la ronda anterior aplicaba al workspace desechable ahora
están **commiteados** en la rama aislada. El arnés lo detecta y lo dice:
`schema_fix_already_present=defer_four_fks.patch,align_permission_migration_to_v6.patch,portable_index_check.patch`
y `schema_fix_applied=(none: already in the code under test)`. El artefacto
`schema_fix_applied.diff` quedó en **0 bytes**: no se parcheó nada en caliente,
la suite corrió el SHA tal como está commiteado.

### 3.1 Aplazar las cuatro claves foráneas

```diff
--- a/database/migrations/2022_05_31_223828_create_empresas_table.php
+++ b/database/migrations/2022_05_31_223828_create_empresas_table.php
@@ -33,7 +33,6 @@ public function up()
             $table->string('otros_profile', 20)->nullable();
 
-            $table->foreign('city_id')->references('id')->on('cities');
 
--- a/database/migrations/2022_08_12_145310_create_sustainabilities_table.php
+++ b/database/migrations/2022_08_12_145310_create_sustainabilities_table.php
@@ -16,7 +16,7 @@ public function up()
             $table->foreignId('empresa_id')->constrained();
-            $table->foreignId('areas_id')->constrained();
+            $table->foreignId('areas_id');
             $table->foreignId('user_id')->nullable()->constrained();

--- a/database/migrations/2022_09_01_154856_create_infratypes_table.php
+++ b/database/migrations/2022_09_01_154856_create_infratypes_table.php
@@ -15,7 +15,7 @@ public function up()
             $table->id();
-            $table->foreignId('infrasectors_id')->constrained();
+            $table->foreignId('infrasectors_id');
             $table->string('type_name', 100);

--- a/database/migrations/2022_09_01_185804_create_infrafacilities_table.php
+++ b/database/migrations/2022_09_01_185804_create_infrafacilities_table.php
@@ -15,7 +15,7 @@ public function up()
             $table->id();
-            $table->foreignId('infraregion_infrasystem_id')->references('id')->on('infraregion_infrasystem');
+            $table->foreignId('infraregion_infrasystem_id');
```

**Las columnas se quedan**, con el mismo tipo: `foreignId()` sin `constrained()`
sigue creando `unsignedBigInteger`. No cambia la forma de ninguna tabla. Las
otras llamadas a `constrained()` en los mismos archivos (`empresa_id`,
`user_id`) quedan intactas.

La migración nueva las vuelve a declarar, con los nombres que Laravel genera y
la misma acción referencial por defecto (`NO ACTION` tanto para
`->constrained()` como para `->references()->on()` sin modificador):

```php
private array $deferred = [
    ['empresas', 'city_id', 'cities', 'empresas_city_id_foreign'],
    ['sustainabilities', 'areas_id', 'areas', 'sustainabilities_areas_id_foreign'],
    ['infratypes', 'infrasectors_id', 'infrasectors', 'infratypes_infrasectors_id_foreign'],
    ['infrafacilities', 'infraregion_infrasystem_id', 'infraregion_infrasystem',
     'infrafacilities_infraregion_infrasystem_id_foreign'],
];

public function up(): void
{
    foreach ($this->deferred as [$table, $column, $referenced, $name]) {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referenced)) { continue; }
        if ($this->foreignKeyExists($table, $column, $referenced)) { continue; }

        Schema::table($table, function ($blueprint) use ($column, $referenced, $name) {
            $blueprint->foreign($column, $name)->references('id')->on($referenced);
        });
    }
}
```

**EFECTO SOBRE BASES YA EXISTENTES — la condición exacta, no un absoluto.**

La versión anterior de este párrafo decía «ninguno», y la revisión `6167931953`
objetó con razón que eso es un absoluto que ni siquiera es cierto al pie de la
letra: **insertar la fila en la tabla `migrations` ya es un cambio**. Dicho con
precisión:

**Sobre el esquema y los datos no cambia nada, SI SE CUMPLE que** cada una de las
cuatro restricciones ya existe con la misma columna, la misma columna
referenciada y sin propagación. En ese caso `up()` las omite una por una y lo
único que escribe en la base es su propia fila de registro en `migrations`.

Si esa condición **no** se cumple, la migración **no** sigue en silencio:

| estado encontrado | qué hace |
|---|---|
| las cuatro ya existen y son equivalentes | no toca nada (salvo la fila de `migrations`) |
| falta una restricción | la crea — que es el objetivo |
| falta una tabla referenciada | **levanta excepción**; antes era un `continue` que dejaba la base sin la clave y registraba la migración como aplicada |
| existe una clave sobre la columna que **no** es equivalente (otra columna referenciada, o `ON DELETE CASCADE`) | **levanta excepción** en vez de apilar una segunda |

Dos capas sostienen el caso normal:

1. Las cuatro migraciones históricas **ya están registradas como corridas** en
   Supabase, así que editarlas es inerte allí: Laravel no las vuelve a ejecutar.
   Por eso se editan en vez de renombrarse — renombrar un archivo de migración
   cambia su identidad en la tabla `migrations` y haría aparecer como pendiente
   algo ya aplicado en todas las bases existentes.
2. La migración nueva **pregunta al catálogo del propio motor** vía
   `Schema::getForeignKeys()`, que es consciente del driver.

**Escenarios efectivamente probados** (en la base desechable, no en Supabase),
en `DeferredForeignKeysMigrationTest`: réplica limpia; restricciones
preexistentes con el nombre original y con uno alternativo, intactas y sin
duplicar; `down()` sin borrar nada; `up()` repetido sin duplicar; filas
sintéticas pobladas que sobreviven a up/down/up con la restricción aún activa; y
los dos fallos declarados de la tabla anterior.

La comparación es **por columna, columna referenciada y acciones referenciales,
no por nombre**: lo que no debe duplicarse es la relación, no la etiqueta, pero
una clave que propague borrados donde el objetivo no lo hace **no** cuenta como
equivalente.

La fecha `2022_12_02` está en el pasado a propósito. En una base ya migrada,
Laravel ordena entre sí las **pendientes** sin importar su fecha respecto de las
ya registradas, así que corre una sola vez, al final del próximo `migrate`, y
queda inerte. En una reproducción desde vacío corre en cuanto existen las cuatro
tablas referenciadas.

### 3.2 Alinear la migración de permisos con la v6 instalada

```diff
@@ -16,6 +16,8 @@ public function up()
         $tableNames = config('permission.table_names');
         $columnNames = config('permission.column_names');
+        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
+        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
         $teams = config('permission.teams');
@@
-            $table->unsignedBigInteger(PermissionRegistrar::$pivotPermission);
+            $table->unsignedBigInteger($pivotPermission);
-            $table->foreign(PermissionRegistrar::$pivotPermission)
+            $table->foreign($pivotPermission)
```

(34 líneas en total: declara los dos locales, los pasa a los tres closures que
los necesitan y reemplaza las referencias estáticas.)

El stub commiteado es el publicado por **spatie/laravel-permission v5**, que leía
`PermissionRegistrar::$pivotPermission` / `::$pivotRole`. `composer.lock`
instala **6.25.0**, donde esas propiedades estáticas ya no existen, y el replay
muere con `Access to undeclared static property`. El stub de la versión
instalada lee los nombres desde config, que es exactamente lo que hace este
parche.

**EFECTO SOBRE BASES YA EXISTENTES: el esquema resultante es idéntico.**
`config/permission.php` tiene `role_pivot_key` y `permission_pivot_key` en
`null`, así que resuelven a `role_id` y `permission_id` — los mismos nombres de
columna que las bases existentes ya tienen. Y de todos modos esa migración
corrió hace años allí, bajo la versión que estaba instalada entonces, así que
no se vuelve a ejecutar.

### 3.3 Comprobación de índice portable

```diff
@@ -77,10 +77,12 @@ private function indexExists(string $indexName): bool
-        return DB::table('information_schema.statistics')
-            ->where('table_schema', Schema::getConnection()->getDatabaseName())
-            ->where('table_name', 'empresa_module_status')
-            ->where('index_name', $indexName)
-            ->exists();
+        return Schema::hasIndex('empresa_module_status', $indexName);
```

`information_schema.statistics` es una vista **exclusiva de MySQL**; PostgreSQL
no tiene esa relación, así que la migración era inejecutable allí
(`SQLSTATE[42P01]`). Su propio docblock es explícitamente MySQL-céntrico
(razona sobre InnoDB reusando un índice como soporte de FK y sobre el error 1553
de MySQL), lo que explica cómo se escribió así. Es la **única** migración del
juego que usa esa vista.

**EFECTO SOBRE BASES YA EXISTENTES: ninguno.** `Schema::hasIndex()` es
consciente del driver y le pregunta a cada motor su propio catálogo, con el
mismo resultado en MySQL. Está disponible desde Laravel 11; este proyecto corre
v12.69.0. Y, otra vez, esa migración ya está registrada como corrida.

---

## 4. Dependencia concreta de `feature/supplhi-postgres-buscador`

### Lo indispensable: 7 archivos

```
database/migrations/pgsql/2026_09_01_120000_create_supplier_categories_table.php
database/migrations/pgsql/2026_09_01_120001_create_supplier_category_translations_table.php
database/migrations/pgsql/2026_09_01_120002_create_supplier_category_synonyms_table.php
database/migrations/pgsql/2026_09_01_120003_create_empresa_supplier_categories_table.php
database/migrations/pgsql/2026_09_01_120004_create_empresa_catalog_items_table.php
database/migrations/pgsql/2026_09_01_120005_create_empresa_catalog_item_category_table.php
database/migrations/pgsql/2026_09_01_120006_create_category_mapping_audit_table.php
```

**Por qué.** Seis migraciones de la rama de integración referencian
`taxonomy_categories` y **ninguna la crea**. No es esquema que haya que
inventar: la tabla se crea como `supplier_categories` en
`2026_09_01_120000` y la renombra a `taxonomy_categories` el
`2026_09_07_090000_rework_taxonomy_categories_structure.php`, que la rama de
integración **ya tenía** — pero cuyo propio docblock dice que asume que esas
tablas ya existen. Es una rama sin fusionar, no un hueco de diseño.

El archivo decisivo es `120000`. Los otros seis entran porque
`2026_09_07_090000` renombra tres de ellos en el mismo `up()`
(`supplier_category_translations`, `supplier_category_synonyms`,
`empresa_supplier_categories`), así que sin ellos esa migración falla; y
`120004`–`120006` porque `120005` declara FKs contra `120004` y
`120000`. Los siete son un bloque coherente de la Fase 4, no una selección.

Comprobado con el analizador, sobre los dos directorios de migraciones
(`audit/isolated_phpunit_2026-10-14b/migration_deps.txt`):

| | antes (rama de integración) | después (esta rama) |
|---|---|---|
| migraciones | 98 | 107 |
| violaciones de orden | **4** | **0** |
| tablas MISSING | **1** (`taxonomy_categories`) | **0** |
| veredicto | aborta | `clean - an empty database can be built from this set` |

### Lo ajeno a esta ronda: 13 archivos, NO incluidos

Las 13 vistas PostgreSQL `2026_08_31_*` (`sustainability`, `management`,
`catalogo`, `resource`, `machinery`, `facility`, `inventory`, `clients`,
`experience`, `presence`, `capacity`, `finance`, `management_det`) **no se
trajeron**. No son dependencia de esta ronda: ninguna crea una tabla que otra
migración referencie, y el replay completa sin ellas —
`pgsql_bootstrap_migrations=8` (los 7 + el rework que ya estaba) y
`migrate_ok=yes`. Si se fusionara la rama entrarían igual; aquí quedan fuera
para que el diff a revisar sea exactamente la dependencia y nada más.

### Lo que esto NO decide

Si `feature/supplhi-postgres-buscador` debe fusionarse sigue siendo decisión
del propietario. Esta rama **copia** los 7 archivos en lugar de fusionar, lo que
tiene un costo honesto: si esa rama cambia esos archivos, hay dos copias. Se
hizo así porque fusionar la rama completa está explícitamente no autorizado, y
porque el diff a revisar queda legible.

---

## 5. Comandos reproducibles

```bash
# 1. El análisis de dependencias, sin base de datos ni contenedores.
node docker/test/migration_deps.mjs database/migrations database/migrations/pgsql
#    -> "clean - an empty database can be built from this set", exit 0

# 2. Todo lo demás, en el VPS aislado de Contabo, desde la rama del arnés
#    (infra/contabo-test-runner @ 2e89663):
export CPV_TEST_ROOT=<directorio de scratch, absoluto, fuera del repositorio>
./docker/test/run_tests.sh d4ea4055ab96c43ce7c72232339ca6809d853ae3

#    Un subconjunto, para repetir sólo los fallos:
./docker/test/run_tests.sh d4ea4055ab96c43ce7c72232339ca6809d853ae3 \
  --filter 'the_state_delta_records_why_the_review_became_stale'
```

Un solo comando hace las cuatro fases, en este orden: crea el workspace
desechable en el SHA exacto, levanta el PostgreSQL desechable **vacío**, replica
las migraciones, corre los seeders versionados del proyecto, carga el fixture y
corre la suite. Códigos de salida: `0` las cuatro bien, `3` el esquema no se
pudo preparar, `4` el esquema se construyó pero el fixture no cargó o falta una
precondición, `1` cualquier otra cosa (suite roja incluida).

Las credenciales del fixture (`APP_KEY` y contraseña de la base) se generan
**efímeras por corrida**, viven sólo en el proceso y en los contenedores
desechables, y mueren con ellos. Nunca se escriben a disco. No se copia ningún
`.env` real y el arnés verifica que el workspace no tenga uno.

---

## 6. Resultados, separados

```
RESULT  isolation      : PASS (18 checks)
RESULT  schema prep    : PASS (12 steps)   [no patch needed; the corrections are committed at this SHA]
RESULT  fixture load   : PASS (2 checks)   [yes; 8 precondition(s) satisfied]
RESULT  test suite     : RED (exit 1)      [Unit+Feature (full suite)]
RESULT  findings in the repository under test: 1
```

### 6.1 Aislamiento de montajes y conexiones — PASS, 18 de 18

`mounts.txt` / `mounts_phpunit.txt`: exactamente **un** bind mount, el workspace
desechable sobre `/app`. Ninguna referencia a `<LIVE_APP_DIR>` en `storage`,
`bootstrap/cache`, `public` ni `.env`. La comprobación **falla cerrada**: un
listado vacío de montajes es un fallo, no un pase.

`db_ports.json`: `{"5432/tcp":null}` — la base del fixture no publica puerto en
el host.

`connections.txt`: las **cinco** conexiones resueltas, antes de cualquier
migración. `pgsql` (la de por defecto) apunta a `testdb`/`cpv_test`; `sqlite` a
un archivo dentro del workspace; `mysql`, `mariadb` y `sqlsrv` son placeholders
de loopback **con nada escuchando** (comprobado con `fsockopen`, no supuesto).
Veredicto: `SAFE: every resolved connection points at the disposable fixture
database`. La base viva es `cpv_test`, PostgreSQL 16.15, y empezó con **0 tablas
en `public`**.

### 6.2 Reconstrucción del esquema y restricciones — PASS, 12 de 12

| | |
|---|---|
| violaciones de orden | **0** |
| `migrate_ok` | **yes** |
| tablas resultantes | **77** |
| migraciones de bootstrap pgsql | 8 |
| las cuatro FK aplazadas | **las creó el propio replay** (`deferred_fks_restored_by=not needed`) |

Lo relevante de esta ronda: **ya no hay SQL fuera de banda.** La ronda anterior
necesitaba `restore_four_fks.sql` ejecutado a mano después del replay; ahora la
migración nueva las crea durante el replay, y el arnés le pregunta al catálogo
antes de ejecutar nada (`select count(*) from pg_constraint where ... conname in
(...)` → 4) y se salta el SQL. Un estado parcial de 1 a 3 sería un fallo propio:
ningún camino lo produce.

`constraints.txt`: el catálogo confirma las cuatro restricciones con su
definición, vía SQL que **levanta excepción** si falta alguna, así que no puede
pasar por omisión.

### 6.3 Carga de fixtures — PASS, 8 precondiciones de 8

`fixture_rows.txt`, medido y no supuesto:

| tabla | filas | origen |
|---|---|---|
| `empresas` | **3** | fixture sintético |
| `taxonomy_categories` | **26** | fixture sintético (2 grupos / 4 familias / 20 subcategorías) |
| `permissions` | 16 | seeders del proyecto |
| `taxonomy_concept_types` | 9 | `taxonomy:seed-concept-vocabulary` |
| `taxonomy_concept_relation_types` | **5** | `taxonomy:seed-concept-vocabulary` |
| `taxonomy_intent_markers` | **9** | `taxonomy:seed-concept-vocabulary` |
| `taxonomy_terms`, `taxonomy_canonical_concepts`, `users`, `roles` | 0 | los crean los tests dentro de su propia transacción |

`fixture_preconditions.txt` — las ocho precondiciones preguntadas **como las
leen los tests**, no como las declara el fixture:

```
PASS  empresas >= 1                      (have 3)
PASS  empresas >= 2                      (have 3)
PASS  categories with code >= 18         (have 26)
PASS  level-2 categories >= 1            (have 20)
PASS  siblings of the first leaf         (have 4)
PASS  children of the other family       (have 5)
PASS  active relation types >= 5         (have 5)
PASS  intent markers >= 1                (have 9)
```

### 6.4 Tests

| | ronda anterior (`99e603cb`) | **esta ronda** (`d4ea4055`) |
|---|---|---|
| ejecutados | 419 | **419** |
| aserciones | — | **2228** |
| errores | **5** | **0** |
| fallos | **20** | **4** |
| risky | 2 | 2 |
| omitidos / skipped | **0** | **0** |
| código de salida | 2 | **1** |

```
Tests: 419, Assertions: 2228, Failures: 4, PHPUnit Deprecations: 1, Risky: 2.
```

**Ningún test se omitió, se marcó como skipped, se borró ni se debilitó.** La
suite es la misma y las aserciones son las mismas; lo que cambió es que ahora
tiene esquema y datos.

---

## 7. Los cuatro fallos restantes

**Tres de los cuatro son la misma causa raíz**, por dos caminos de código
distintos: los timestamps se escriben con **resolución de un segundo**, así que
dos escrituras dentro del mismo segundo son indistinguibles.

La sonda `timestamp_probe.txt` lo mide directamente, sin escribir ninguna fila:

```
binding date format for this connection : Y-m-d H:i:s
php captured t1                         : 2026-10-14 09:22:15.386575
php captured t2 (2ms later)             : 2026-10-14 09:22:15.388771
t2 is after t1 in PHP                   : yes
what the query builder actually binds   : 2026-10-14 09:22:15  |  2026-10-14 09:22:15
postgres says bound t2 > bound t1       : NO
```

La columna es `TIMESTAMP` (microsegundos en PostgreSQL); es el **formato de
binding** de Laravel el que los descarta antes de guardar.

**Lo que esto establece y lo que no.** Establece que los tests **no son
deterministas**: su resultado depende de si dos escrituras caen a ambos lados de
un borde de segundo, y nada en ellos lo garantiza. Eso está medido.

Una explicación *plausible* de por qué pasaban históricamente contra Supabase es
que, con una base remota, cada operación tarda cientos de milisegundos y los dos
instantes cruzaban el borde casi siempre, mientras que contra un PostgreSQL local
caen en el mismo segundo casi siempre. **Pero es una inferencia, no una causa
establecida para cada corrida vieja** — corregido tras la revisión `6167931953`,
que señaló exactamente esta sobreafirmación. No instrumenté aquellas corridas y
no puedo atribuirles la causa una por una.

Lo que sí se puede afirmar sin inferir: con el reloj fijo, el fallo se reproduce
siempre, y la corrección lo elimina siempre. Eso es lo que prueban las
regresiones deterministas de `SameSecondTimestampRegressionTest`, que no usan
`sleep()` ni dependen de la latencia.

### 7.1 `ReviewedProposalConfirmationTest::invalidating_is_recorded_as_its_own_distinguishable_audit_event`

**Error:** `Failed asserting that actual size 0 matches expected size 1`
(`tests/Unit/Taxonomy/ReviewedProposalConfirmationTest.php:669`).

**Causa, con evidencia.** `TaxonomyAuditLogger::record()` empieza con un guard
de no-op:

```php
if (self::stringify($oldValue) === self::stringify($newValue)) {
    return;
}
```

`ReviewedProposalService::invalidateConfirmation()` lo llama con
`oldValue: $snapshot['confirmed_at']` (formateado `'Y-m-d H:i:s'`) y
`newValue: $invalidatedAt->format('Y-m-d H:i:s')`. El test confirma la propuesta
y la invalida de inmediato: si ambas caen en el mismo segundo, las dos cadenas
son **idénticas**, el guard lo toma por un no-op y **descarta la fila de
auditoría en silencio**.

Es el único de los cuatro que es intermitente: falló el 2026-10-13, pasó el
2026-10-14 y volvió a fallar en las tres repeticiones de hoy, siempre con el
mismo SHA. Pasa exactamente cuando las dos operaciones cruzan un borde de
segundo.

**Esto es un defecto real, no un artefacto del test.** Una invalidación de
confirmación ejecutada en el mismo segundo que la confirmación **no deja rastro
de auditoría**. Es relevante para gobernanza: el rastro durable de "qué se
anuló" es justamente lo que esa fila sostiene.

**Corrección propuesta** (no aplicada): el guard de no-op existe para no loggear
cambios vacíos, y es correcto en general — el propio docblock cuenta que atrapó
un bug real. Lo que está mal es compararlo sobre cadenas de segundo. Dos
opciones:

1. En `invalidateConfirmation()`, pasar el valor viejo con microsegundos
   (`format('Y-m-d H:i:s.u')`) o pasar el `id` de la confirmación anulada en vez
   de su timestamp. Mínimo y local.
2. En `TaxonomyAuditLogger::record()`, no dejar que el guard descarte una fila
   cuando el `field` es un evento y no un cambio de valor. Más general, más
   invasivo.

La 1 es la más pequeña y no toca el logger compartido. Ninguna requiere cambiar
el test.

### 7.2 `ReviewedProposalSupersessionTest::the_state_delta_records_why_the_review_became_stale`

**Error:** `Failed asserting that an array contains 274` (y `contains 1` en las
repeticiones; es el `id` del concepto nuevo, varía por corrida)
(`tests/Unit/Taxonomy/ReviewedProposalSupersessionTest.php:430`).

**Causa, con evidencia.** `ReviewedProposalService::supersessionStateDelta()`
deriva los conceptos aparecidos desde la revisión con:

```php
$conceptsCreatedSince = DB::connection('pgsql')->table('taxonomy_canonical_concepts')
    ->where('created_at', '>', $reviewedAt)
```

El test congela la propuesta, crea el concepto que invalida la revisión y
supersede, todo seguido. Con el `>` estricto y los dos timestamps truncados al
mismo segundo, el concepto queda **fuera** de la lista, y
`assertContains($newConcept->id, $createdIds)` falla sobre un array vacío.

**También es una limitación real del producto:** un cambio de taxonomía hecho en
el mismo segundo que la revisión es invisible en el delta de supersesión, que es
precisamente la evidencia que un humano lee para entender qué volver a mirar.

**Corrección propuesta** (no aplicada): el delta ya declara su propio límite
(`'limitation' => '...los cambios listados se derivaron de created_at/updated_at
acotados por reviewed_at...'`), así que el camino honesto es **estrechar el
límite, no documentarlo mejor**. Lo mínimo es persistir `reviewed_at` con
microsegundos para las filas que participan de esta derivación —
`$table->timestamp('reviewed_at', 6)` y un cast con `'datetime:Y-m-d H:i:s.u'`
— de modo que la comparación tenga la resolución que la columna ya soporta.
Cambiar el `>` por `>=` **no** sirve: incluiría conceptos creados exactamente en
el instante de la revisión, que son los que el revisor sí vio.

Esta corrección toca una migración y un modelo del dominio de propuestas, así
que la dejo propuesta y no aplicada: es un cambio de esquema sobre una tabla con
datos reales (#420/#421/#422) y pide decisión.

### 7.3 `TaxonomyConceptExplorerTest::an_already_frozen_candidate_is_still_protected_from_a_second_freeze`

**Error:** `Failed asserting that a table action with name [freezeReview] is
visible on [...ListTaxonomyCandidateConceptLinks]`
(`tests/Feature/Filament/TaxonomyConceptExplorerTest.php:506`).

**Causa, con evidencia.** Este **no** es timestamps. El test llama
`callTableAction('freezeReview', ...)` dos veces y espera terminar con una sola
propuesta. Pero TASK-0006D PARTE 2 cambió deliberadamente la UI para **esconder**
esa acción en cuanto el candidato tiene una propuesta viva:

```php
->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING
    && Auth::user()?->can('update', $record)
    && self::liveFrozenProposal($record) === null)
```

El docblock del recurso lo explica, y dice además que la visibilidad **no es la
salvaguarda real**: «el índice único parcial y `ReviewedProposalService::freeze()`
siguen rechazando un segundo congelamiento por cualquier camino (probado por
test)». El segundo intento ya no es alcanzable desde la UI, así que Filament
falla al no encontrar la acción visible.

**Es un test obsoleto, y la prueba es que su reemplazo existe y pasa.**
`TaxonomyCandidateConceptLinkReviewTest::freeze_review_double_submit_does_not_create_a_second_pending_proposal`
cubre exactamente la misma intención, fue actualizado para TASK-0006D PARTE 2
(su comentario lo dice textualmente) e invoca el servicio **directamente**,
sorteando la visibilidad. Está **verde** en esta corrida. También
`ReviewedProposalBatchApplyTest::the_partial_unique_pending_index_still_blocks_a_second_freeze_of_the_same_candidate`.
La protección está cubierta por dos tests que pasan; el que falla quedó
describiendo una UI que ya no existe.

**Corrección propuesta** (no aplicada): cambiar el segundo intento del bucle por
una aserción de que la acción está **escondida** —
`assertTableActionHidden('freezeReview', $candidate->fresh())` — conservando la
aserción final de que hay exactamente una propuesta `PENDING_APPLY`. Eso
**fortalece** el test: pasa a afirmar la defensa nueva (no se ofrece) además de
la vieja (no se duplica). Lo dejo propuesto porque editar un test es
precisamente lo que no debo hacer por mi cuenta para poner algo verde, aunque
aquí el cambio no debilite nada.

### 7.4 `TaxonomyReviewedProposalResourceTest::the_detail_page_of_a_superseded_proposal_renders_without_a_500`

**Error:** `El fixture tiene que producir un delta con la lista anidada, que es
justo lo que se quiere renderizar. Failed asserting that an array is not empty`
(`tests/Feature/Filament/TaxonomyReviewedProposalResourceTest.php:216`).

**Causa, con evidencia.** La **misma** de 7.2. El test arma su propio
precondición y la verifica antes de pedir la página:

```php
$this->assertNotEmpty($proposal->fresh()->supersession_state_delta['concepts_created_since_review'],
    'El fixture tiene que producir un delta con la lista anidada, ...');
```

`concepts_created_since_review` viene de la misma consulta con `>` estricto, así
que llega vacío y el test falla **antes de abrir la pantalla**. Lo que ese test
quiere probar — que `flattenStateDelta()` evita el TypeError de
`htmlspecialchars()` sobre un valor no escalar, el incidente 500/503 de
TASK-0002 — **no llegó a ejercitarse en esta corrida**. No es evidencia de que el
aplanado esté roto ni de que esté bien.

**Corrección propuesta** (no aplicada): se arregla solo con la corrección de 7.2.
No necesita cambio propio.

### Los 2 tests «risky», que no son fallos pero tampoco evidencia

`CanonicalConceptBuilderServiceTest::dry_run_attaches_predicted_impact_to_every_scored_candidate`
y `::propose_concept_relations_never_returns_directional_types` reportan *This
test did not perform any assertions*. Los dos iteran un resultado
(`$result['sample_results']`, `$result['proposals']`) que en este fixture llega
**vacío**, porque no hay términos ni conceptos candidatos que puntuar — los
crean los tests que los necesitan, dentro de su transacción. No fallan, pero
**pasan vacuamente**: no dicen nada sobre el código. Lo declaro en limitaciones
en vez de contarlos como verde.

---

## 8. Comprobaciones de staging, antes y después

| | antes | después |
|---|---|---|
| `GET /` (app de staging) | **200** | **200** |
| `perfilafiliados-app-1` | `Up 7 days` | `Up 8 days` |
| `perfilafiliados-nginx-1` | `Up 3 weeks` | `Up 3 weeks` |
| contenedores `cpvtest-*` sobrantes | — | **0** |

Los contenedores que sirven staging **no se reiniciaron**. El contador del
servicio `app` pasó de `Up 7 days` a `Up 8 days` porque cruzó la marca de los 8
días durante la sesión, no porque se reiniciara: un reinicio se mostraría como
`Up <minutos>`. Es decir, no es sólo que respondan — son los mismos procesos.

El arnés usa su propio proyecto Compose (`cpvtest`) y en el teardown borra
**sólo** los recursos de su corrida: después de las cinco corridas de hoy no
queda ningún contenedor `cpvtest-*`, y los workspaces desechables se borran, de
modo que en el scratch sólo quedan los artefactos.

**La base compartida no se tocó.** Ninguna conexión del contenedor de pruebas
apuntaba a Supabase (`connections.txt`, las cinco), la base viva era `cpv_test`
y arrancó con 0 tablas. No se leyó ni se escribió ninguna fila de la base
compartida, ni como fixture ni como referencia.

También siguen quietos, comprobado: `cpv-orchestrator.service` sigue `inactive`
y `disabled`, con 0 procesos, y esta ronda no hizo ninguna llamada a Anthropic.

---

## 9. Limitaciones y evidencia heredada

1. **Las 15 vistas exclusivas de MySQL no existen en este fixture.**
   `db:skip-mysql-only-view-migrations` las marca como corridas. En Supabase las
   creó otro mecanismo. Ningún test las referencia (comprobado con grep sobre
   `tests/` y `app/`), pero el fixture **no es vista por vista idéntico** a la
   base compartida.

2. **Las 13 vistas PostgreSQL `2026_08_31_*` tampoco.** Quedaron fuera a
   propósito (sección 4). El replay completa sin ellas, pero no verifiqué que
   esas vistas se creen correctamente sobre una base vacía.

3. **Los 2 tests risky pasan vacuamente** (sección 7.4). Harían falta términos,
   conceptos y relaciones CPV en el fixture para que afirmaran algo. No lo
   amplié: estos tests no declaran precondición, así que no sabría qué forma de
   datos es la correcta sin decidirlo yo.

4. **`the_detail_page_of_a_superseded_proposal_renders_without_a_500` no llegó a
   ejercitar el aplanado.** El riesgo que vigila — el 500 de TASK-0002 en la
   pantalla de detalle — **sigue sin verificar** en esta corrida.

5. **El 197/197 histórico sigue siendo heredado.** Es evidencia de aquella
   ejecución y no se descarta, pero esta ronda no lo reprodujo y no prueba que
   las migraciones reconstruyan una base vacía — eso es lo que prueba esta
   corrida, por separado.

6. **Sólo PostgreSQL 16.15.** Que otros motores rechacen las mismas
   declaraciones de clave foránea **no** está establecido y no se afirma. La
   conexión por defecto del proyecto es `mysql` (`config/database.php`), y
   **no verifiqué el replay desde vacío sobre MySQL.** Las tres correcciones
   están escritas para ser neutrales ahí (`Schema::hasIndex`,
   `Schema::getForeignKeys`, nombres de columna desde config), pero eso es
   razonamiento, no medición.

7. **El efecto sobre bases existentes está razonado, no ejecutado.** No corrí
   `migrate` contra Supabase — no está autorizado y sería exactamente lo que hay
   que evitar. El argumento de inercia (sección 3) se apoya en que las cuatro
   migraciones están registradas como corridas allí y en el guard de catálogo de
   la migración nueva. **Verificarlo pide una copia de la base, no la base.**

8. **Los 7 archivos de la rama hermana son copias, no un merge.** Si esa rama
   los cambia, habrá dos versiones (sección 4).

9. **El fixture es sintético y mínimo.** 26 categorías con códigos `ZZTEST-` y
   3 empresas ficticias. No reproduce la distribución del catálogo real (48
   grupos, cientos de categorías), así que no dice nada sobre rendimiento,
   paginación a escala real ni calidad de ranking. Para eso está la línea base
   de regresión, intacta.

---

## Lo que esta ronda NO hizo

* No fusionó `feature/isolated-test-readiness` ni
  `feature/supplhi-postgres-buscador` en ninguna parte.
* No promovió los parches a la rama de integración.
* No desactivó, soltó ni aplazó-sin-restaurar ninguna restricción. Ninguna
  `SET session_replication_role`, ningún `--no-foreign-key-checks`.
* No omitió, borró, debilitó ni marcó como skipped ningún test, ni cambió una
  aserción.
* No escribió en la base compartida, no copió datos de clientes y no usó la base
  compartida como fixture.
* No tocó `main`, producción, la publicación de taxonomía ni
  `audit/regression_baseline_2026-09-23.json`.
* No reactivó el orquestador ni llamó a Anthropic.

## Decisión que solicito

1. ¿Se aceptan las tres correcciones de esquema y la migración de restauración
   para promoverlas a la rama de integración? (Sección 3; el efecto sobre bases
   existentes está razonado pero no ejecutado — limitación 7.)
2. ¿Se fusiona `feature/supplhi-postgres-buscador`, o se queda la copia de los 7
   archivos? (Sección 4.)
3. ¿Se autorizan las correcciones propuestas para los 4 fallos? Las de 7.1 y 7.2
   son de código de producción, y la de 7.2 cambia una columna de una tabla con
   datos reales. La de 7.3 edita un test, que es justo lo que no hago sin
   autorización.

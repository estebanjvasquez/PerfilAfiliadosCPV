# Candidato de integración TASK-0010A + TASK-0010B + test-readiness

Entrega para revisión manual. Instrucción: Issue #2 comentario `6173818866`.
Una sola sesión en primer plano: sin subagentes, sin polling, sin revisiones
automáticas y sin llamadas a Anthropic. El ejecutor autónomo sigue **suspendido**.

**Esto es preparación y validación. No se desplegó nada y no se empujó runtime a
`feature/upgrade-filament-v3`**, que sigue exactamente en `a6e9db4`.

---

## 1. Repositorio, rama y SHA

| | |
|---|---|
| Repositorio | `estebanjvasquez/PerfilAfiliadosCPV` (público) |
| **Rama candidata** | `feature/integration-candidate-0010` |
| Creada desde | `origin/feature/upgrade-filament-v3` @ `a6e9db4711718ca053b9e8be0678be92cfe02ecb` |
| **SHA probado** (`TESTED_HEAD`) | `6c25bb3b55f2c8b825da5246fb40a2235c557e81` |
| **SHA a revisar** (`REVIEW_HEAD`) | la cabeza de esta rama al entregar; el comentario de Issue #2 lleva el valor exacto |
| Arnés de pruebas | `infra/contabo-test-runner` @ `5f6318105e57e8ae96fca9721a89bccb8c880f16` |
| `run_id` de la validación | `20261014T172522Z-6c25bb3b55f2` |

**La rama candidata NO está vigilada por el workflow de despliegue.**
`.github/workflows/deploy-contabo.yml` sólo dispara con push a
`feature/upgrade-filament-v3`; comprobado además de afirmado: el último run de
«Deploy a Contabo» sigue siendo el del 2026-10-06.

### Delta entre `REVIEW_HEAD` y `TESTED_HEAD`

**Sólo documentación.** Un commit no puede contener su propio hash, así que el
valor exacto va en el comentario de Issue #2. Por encima de `6c25bb3b` sólo hay
commits que añaden este informe y las evidencias bajo
`audit/integration_candidate_0010/`. Comprobable:

```
git diff --name-only 6c25bb3b..<REVIEW_HEAD> | grep -v '^audit/'   -> vacío
```

---

## 2. Qué se fusionó, y cómo se resolvieron los conflictos

Tres merges **normales, sin `--force` y sin reescribir historia**, en el orden en
que se apilan:

| merge | SHA aceptado | aceptado en |
|---|---|---|
| test-readiness | `2e71a2e3e34ae2d7c02467e56c88c065c1f28ee7` | comentario `6173818866` |
| TASK-0010A | `b43737efbe09eeb640314ee67dd0130b4cd4b671` | comentario `6172299365` |
| TASK-0010B | `964550dc11174e0f02def0b02b1264a89d3a8a83` | comentario `6172299732` |

**Todo el código aceptado sobrevivió**, verificado archivo por archivo: el
servicio, el Mailable y la migración de A; la página, el renderizador y el manual
de B; la migración de integridad, las regresiones y `phpunit.xml` de
test-readiness; y los 7 archivos de `database/migrations/pgsql/` que son la
dependencia seleccionada. **No se fusionó la rama supplhi completa.**

### Los conflictos, y por qué se resolvieron así

Los dos merges de subtarea chocaron en `audit/orchestrator_handoff.json`, y en
ambos el lado de la subtarea traía **la narrativa autónoma ya superada**:

```
"review_state": "WAITING_ORCHESTRATOR_REVIEW"
"review_state_note": "Set per AUTONOMOUS_DEV_LOOP.md section 11 after 60 minutes of polling..."
"review_head": "badce535..."            (tres rondas aceptadas de antigüedad)
"agent_issue_write_access": "UNAVAILABLE"
```

Siguiendo la instrucción —reconciliar en torno al flujo manual real, no a
narrativas superadas de rama— **nada de eso se arrastró**, y se dice por qué en el
propio archivo:

* **no hay polling**: el loop autónomo está suspendido desde el 2026-10-14 y cada
  ronda se entrega publicando un comentario y parando;
* ese `review_head` apunta a código tres rondas atrás;
* **el acceso de escritura a Issue #2 funciona** desde el 2026-10-09, con seis
  comentarios publicados hoy como prueba.

En su lugar el handoff ahora lleva `review_workflow` (manual, explícito),
`integration_candidate` (rama, origen, SHA fusionados, autorización) y el estado
real de cada subtarea: **`ACCEPTED_SUBTASK_PASS_2026-10-14`** con su comentario de
aceptación y sus dos SHA. `agent_review_polling_policy` queda marcada
`RETIRED_2026-10-14`, conservando la política vieja como registro en vez de
borrarla.

---

## 3. Etiquetas finales de A, y el test que las ata a su origen

La revisión `6171023438` pidió reemplazar la comprobación de **presencia** del
marcador provisional por aserciones de **etiqueta y comportamiento finales**, y no
dejar para siempre un test que exija un comentario interno. Hecho:

* el marcador `PROVISIONAL-TASK-0010A` **se retiró** del fuente del manual;
* las cinco etiquetas de pantalla son ahora **constantes** en
  `TaxonomyCategoriesRelationManager` — las tres de los campos estaban en línea,
  así que cambiar una dejaba el manual mintiendo sin que nada fallara;
* `UserManualContentTest::the_manual_cites_the_final_task_0010a_labels_read_from_the_implementation`
  **lee las constantes** y exige que el manual cite cada una. Cambiar una etiqueta
  sin reconciliar el manual, o al revés, ahora falla y dice cuál.

**Reconciliar encontró tres desajustes reales**, que es exactamente la razón de
hacerlo leyendo las constantes en vez de confiar en la afirmación anterior de que
coincidían «carácter por carácter»:

| etiqueta | antes en el manual | ahora |
|---|---|---|
| `SOLICITUD_MODAL_HEADING` | **no se citaba** | «Se abre una ventana titulada **Solicitar revisión de categoría a la Cámara**» |
| `SOLICITUD_JUSTIFICACION_LABEL` | parafraseada como «Elija el motivo» | «En **¿Por qué solicita la revisión?**, elija el motivo…» |
| `SOLICITUD_TERMINOS_LABEL` | sin el «(opcional)» | «**¿Qué palabras probó al buscar? (opcional)**» |

El test afirma además el **comportamiento** prometido, no sólo las palabras: que la
solicitud no crea una categoría y que la Cámara revisa primero si ya existe una.

---

## 4. Comandos reproducibles

```bash
export CPV_TEST_ROOT=<directorio de scratch, absoluto, fuera del repositorio>

# La validación completa de esta entrega, un solo comando:
./docker/test/run_tests.sh 6c25bb3b55f2c8b825da5246fb40a2235c557e81 \
  --ux --preflight docs/deploy/preflight_integration_candidate_0010.sql
```

Desde `infra/contabo-test-runner` @ `5f63181`, en el VPS aislado de Contabo.
**Sin parches en caliente**: el propio resumen lo registra
(`schema_fix_applied=(none: already in the code under test)` y
`pgsql_bootstrap_from=...not needed at this SHA`), porque las correcciones y las
7 migraciones están **commiteadas** en el candidato.

---

## 5. Resultados, separados

```
RESULT  isolation      : PASS (19 checks)
RESULT  schema prep    : PASS (16 steps)   [no patch needed; the corrections are committed at this SHA]
RESULT  fixture load   : PASS (2 checks)   [8 precondiciones]
RESULT  test suite     : GREEN             [Unit+Feature, suite completa]
RESULT  ux / browser   : PASS (5 checks)   [página autenticada real, 3 anchos x 2 esquemas]
```

### 5.1 La suite completa: verde

```
OK (465 tests, 2698 assertions)
```

`OK` pelado: **cero fallos, cero errores, cero risky, cero deprecaciones**, exit 0.

**El inventario de tests cuadra exactamente con lo aditivo**, como pedía la
instrucción:

| origen | tests | aserciones |
|---|---|---|
| test-readiness aceptado (`8b8bcff1`) | 433 | 2334 |
| TASK-0010A aceptada | +17 | +220 |
| TASK-0010B aceptada | +15 | +133 |
| **suma esperada** | **465** | **2687** |
| **medido en el candidato** | **465** | **2698** |

El conteo de tests coincide al número. Las aserciones dan **+11** sobre la suma,
y la diferencia está explicada, no redondeada: el test de etiquetas finales
reemplazó al del marcador provisional y afirma más (cinco etiquetas leídas de las
constantes, dos patrones de comportamiento y cuatro cadenas internas ausentes), y
`the_manual_documents_the_category_not_found_review_request_behaviour` ganó una
aserción al pasar a comprobar la etiqueta final además de la frase.

### 5.2 Comprobaciones de UI integradas — 40 de 40

En el entorno aislado, **sin despliegue a staging**: `php artisan serve` dentro
del contenedor desechable de la corrida, en la red privada de compose, sin
publicar puerto; afiliado y empresa sintéticos; Chromium como contenedor hermano.

* **invitado bloqueado** del manual, y sin ver su contenido;
* **afiliado autenticado** entra, el manual abre y está en la navegación;
* **las cinco etiquetas finales de A se ven renderizadas** — no sólo afirmadas
  contra las constantes por PHPUnit, sino presentes en la página que un humano lee;
* el manual dice, en su texto renderizado, que la solicitud **no crea** una categoría;
* los 14 enlaces del índice resuelven y su destino está visible;
* el tabulador llega a los enlaces de sección y hay indicador de foco;
* por cada ancho × esquema (1440 / 820 / 390, claro y oscuro): **cero
  desbordamiento horizontal**, tablas contenidas, contraste WCAG medido
  (14.89:1 claro, 13.96:1 oscuro) y el contenido visible y no tapado;
* capturas guardadas por combinación, más dos de control.

**El formulario de solicitud del afiliado, su validación y el correo falso** se
ejercitan en los 17 tests de A, que son tests de componente Livewire/Filament
—la acción existe en el relation manager, el envío persiste, `empresa_id`/`user_id`
no se pueden falsear, la lista de justificación es cerrada, los campos requeridos
rechazan vacío, `Mail::fake()` verifica destinatario/asunto/contenido, el
destinatario ausente y la excepción de envío no pierden la solicitud—. **No se
envió correo a ningún destinatario real en ninguna corrida.**

### 5.3 Sin mutaciones de taxonomía — medido, no afirmado

```
taxonomy_categories                26   (idénticas a lo que cargó el fixture)
taxonomy_category_translations      0
taxonomy_term_cpv_relations         0
taxonomy_canonical_concepts         0
taxonomy_term_concepts              0
taxonomy_concept_relations          0
empresa_taxonomy_category           0
```

Contado **después** de que todo corrió y comparado con lo que cargó la fase de
fixtures. Importa porque la suite ejercita el flujo de solicitud de categoría y
el punto entero de TASK-0010A es que una solicitud **nunca** cree ni publique una
categoría. Los tests usan `DatabaseTransactions` y revierten, así que la igualdad
es lo esperado; una diferencia habría significado que algo se escapó de una
transacción.

### 5.4 Aislamiento y staging

Un solo bind mount sobre `/app`; `{"5432/tcp":null}`; las cinco conexiones
resueltas **sin ninguna a Supabase**, con `cpv_test` arrancando en 0 tablas.
Staging **200 antes y 200 después**, sin reinicio de contenedores y sin
contenedores `cpvtest-*` sobrantes.

---

## 6. Migraciones pendientes de despliegue, y el preflight de solo lectura

### 6.1 Qué aplicaría un `migrate` sobre la base viva

**Nueve migraciones nuevas**, y la distinción entre ellas importa:

| migración | qué hace | riesgo |
|---|---|---|
| `2022_12_02_000000_restore_deferred_foreign_keys` | crea las 4 claves foráneas que falten | **ninguno si ya existen**: las omite una por una; `down()` es no-op |
| `2026_10_09_120000_create_taxonomy_category_requests_table` | tabla nueva + columna nullable en el singleton existente | aditiva; `IF NOT EXISTS` en ambas |
| `2026_09_01_120000` … `120006` (7 archivos) | `CREATE TABLE supplier_categories` y compañía | **ESTE ES EL RIESGO REAL** |

**El riesgo de los siete, dicho sin rodeos.** Son `CREATE TABLE` importados de
`feature/supplhi-postgres-buscador`. Si esos nombres **no** están registrados en
la tabla `migrations` de la base viva, `migrate` intentará crear tablas que allí
**ya existen bajo su nombre renombrado** por `2026_09_07_090000`, y eso falla —o,
peor, duplica estructura—. La instrucción es explícita en no asumir que están
registradas, **y este informe no lo asume**.

Y **seis migraciones modificadas** (las cuatro de claves foráneas aplazadas, el
stub de permisos y la comprobación de índice portable). Sus ediciones son
**inertes** sobre una base donde ya corrieron, porque Laravel no las vuelve a
ejecutar — pero esa inocuidad depende de que estén registradas, que es justo lo
que el preflight comprueba.

### 6.2 El preflight

`docs/deploy/preflight_integration_candidate_0010.sql`, **sólo SELECT**. Siete
bloques: identidad de la base; las nueve nuevas contra el registro de
migraciones, con `PARAR` para cualquier `CREATE` importado no registrado; las seis
modificadas, con `PARAR` si alguna no corrió; los objetos nuevos de A (no deben
existir) y el singleton que extiende (debe existir); las cuatro claves foráneas
con su **nombre actual, su tabla referenciada real y sus acciones referenciales**,
con `PARAR` si alguna apunta a otro sitio o propaga; las ocho tablas
prerrequisito; y un conteo de control de las tablas gobernadas para comparar
después.

**Se ejecutó, contra el fixture desechable**, porque un preflight escrito para una
base viva no debería entregarse sin haber corrido nunca en ningún lado. El arnés
además **rechaza** un script que no sea de solo lectura, y lo comprobó. Salida en
`audit/integration_candidate_0010/preflight_output.txt`.

**Cómo leer esa salida sin confundirse:** el fixture es una construcción NUEVA, así
que allí las nueve están registradas y las dos nuevas del candidato aparecen con
`ATENCIÓN: ya registrada`. Eso es correcto para un fixture recién construido y
**no** es lo que se espera de la base viva. Lo que esa corrida prueba es que el
script **corre** y que sus consultas son válidas; **no** prueba que la base viva
vaya a pasar.

### 6.3 Plan de despliegue propuesto a staging

No ejecutado. Propuesto para decisión:

1. **Preflight primero**, en solo lectura, contra la base de staging. Si alguna
   línea dice `PARAR`, **se detiene** y se reporta; en particular, cualquier
   `CREATE` importado no registrado es un bloqueo, no algo que forzar.
2. Guardar la salida del preflight como evidencia, con el conteo de control.
3. Recién entonces, y sólo con autorización explícita, empujar el runtime a
   `feature/upgrade-filament-v3` —que es lo que dispara el workflow— y dejar que
   el despliegue corra `migrate`.
4. Repetir el bloque 7 del preflight **después** y comparar: ninguna migración de
   este candidato debe mover una sola fila.
5. Smoke de staging antes y después, y verificar el HEAD desplegado contra el SHA
   exacto por el run del workflow.

**Nada de eso está autorizado por esta entrega** y nada de eso se hizo.

---

## 7. Limitaciones

1. **El efecto sobre la base compartida sigue razonado y verificable, no
   ejecutado.** El preflight existe precisamente para comprobarlo **antes** de
   migrar; hoy no se corrió contra Supabase.
2. **Sólo PostgreSQL 16.15.** La conexión por defecto del proyecto es `mysql` y el
   replay desde vacío **no** se probó allí.
3. **No es paridad vista por vista** con la base desplegada: quedan fuera las 15
   vistas exclusivas de MySQL y las 13 vistas PostgreSQL `2026_08_31_*`.
4. **Un solo motor de navegador (Chromium)**, un viewport de 390px y no un
   dispositivo físico; sin lector de pantalla y sin que una persona mire la
   página. Tampoco corrió un build de frontend, así que un tema compilado a medida
   está ausente.
5. **Ningún correo real**: todo con `Mail::fake()`; no se verificó transporte SMTP.
6. **Las filas ya guardadas con resolución de segundo** no recuperan precisión.
7. **La barra lateral del panel se abre sobre el contenido a 390px**, en el manual
   y en la raíz del panel por igual: es comportamiento del panel y queda
   reportado, no corregido aquí.

---

## Lo que esta ronda NO hizo

* No empujó runtime a `feature/upgrade-filament-v3`, que sigue en `a6e9db4`.
* No desplegó: ningún workflow escucha la rama candidata, y el último run de
  «Deploy a Contabo» sigue siendo el del 2026-10-06.
* No fusionó la rama supplhi completa: sólo los 7 archivos seleccionados.
* No corrió `migrate` ni ninguna sentencia mutante contra ninguna base viva.
* No creó, aprobó ni publicó ninguna categoría ni relación de taxonomía — medido.
* No envió correo a ningún destinatario real.
* No usó parches en caliente de aplicación ni de migraciones en la corrida.
* No tocó `main`, producción, APPLY de taxonomía, los payloads congelados ni la
  línea base de regresión.
* No reactivó el ejecutor autónomo, no usó subagentes y no hizo polling.

## Decisión que solicito

Aceptación del candidato de integración. Con eso, y sólo con autorización
explícita, el paso siguiente es el plan de §6.3: preflight de solo lectura contra
staging primero, parar ante cualquier `PARAR`, y recién después el push de runtime
que dispara el despliegue.

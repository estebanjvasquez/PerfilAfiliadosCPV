# TASK-0014 — Coherencia de búsqueda de taxonomía y asociación de términos desde la categoría

| Campo | Valor |
|---|---|
| Estado | **READY_FOR_REVIEW** (2026-11-09). Rama `feat/taxonomy-search-coherence`, REVIEW_HEAD `a8020cf`, BASE `90044f3`; suite completa verde en el runner aislado. **No desplegada** |
| Fuente | Issue #2 `6736283026` (estebanjvasquez, 2026-11-09T09:08:19Z): «[ORQUESTADOR — SOLICITUD DEL PROPIETARIO]», publicado por el orquestador a petición expresa del propietario |
| Relación | Nueva; no modifica TASK-0013 (calibración del buscador CIRA), que conserva su identificador y propósito |
| Código examinado | el que corre en staging: `feature/upgrade-filament-v3` (se verifica su SHA antes de concluir) |
| Gates | sin producción, sin publicación masiva de relaciones, sin cambios en datos reales; despliegue a staging solo con autorización explícita |

## Comentario fuente (íntegro)

[ORQUESTADOR — SOLICITUD DEL PROPIETARIO] Coherencia de búsqueda de taxonomía y gestión de términos desde la categoría

El propietario solicita facilitar la selección y verificación de categorías tanto para las empresas afiliadas como para los administradores. Caso observado: al buscar «balancín» en la ficha de una empresa aparecen dos opciones; al buscarlas desde la administración de categorías o familias no aparecen del mismo modo. También solicita poder asociar desde una categoría un término ya existente en el diccionario.

Diagnóstico preliminar de código, pendiente de verificar contra el entorno activo: el selector de empresas usa `TaxonomyCategorySearch` (nombre/sinónimos, relaciones aprobadas del diccionario y similitud semántica); los listados administrativos buscan principalmente por código y nombre ES. El filtro administrativo rotulado diccionario consulta `synonyms`, que es distinto de las relaciones término–CPV. El selector de empresa presenta el breadcrumb sin indicar de forma clara la fuente del resultado. No se ha confirmado si las dos opciones concretas de «balancín» son correctas ni por qué fuente aparecen.

Se solicita al desarrollador:

1. Reproducir en el entorno usado por el propietario el caso «balancín» y documentar las dos opciones efectivamente visibles, sus códigos, rutas, estado, fuente de coincidencia y relaciones aprobadas. Comparar con búsqueda de categoría, familia y grupo en administración. Distinguir evidencia directa de inferencias y del resultado semántico; no asumir que las dos opciones son equivalentes o correctas.
2. Diseñar e implementar una búsqueda coherente para las superficies pertinentes: código, nombres ES/EN, sinónimos y términos/alias del diccionario, con normalización de tildes y mayúsculas. Desde una coincidencia de categoría debe poder localizarse su familia/grupo. Documentar diferencias justificadas de alcance: por ejemplo, empresa solo ve categorías elegibles/activas, administración debe poder identificar estados. Evitar limitar la búsqueda administrativa a los primeros resultados de un selector o convertir resultados semánticos en relaciones aprobadas.
3. Mostrar para cada coincidencia el código, ruta y motivo comprensible: nombre, sinónimo, término/alias aprobado o sugerencia semántica. Hacer visible el estado cuando corresponda y distinguir sugerencias de vínculos aprobados, sin afirmar que una similitud prueba una capacidad.
4. Desde la ficha de categoría, permitir consultar y asociar un término existente del diccionario sin duplicarlo; mostrar sus relaciones y estados, tratar duplicados y relaciones previas de manera segura. La nueva relación debe quedar en revisión y la aprobación/publicación conservar los permisos, motivo y auditoría existentes. No publicar ni modificar masivamente relaciones o datos existentes.
5. Incluir verificaciones que reproduzcan el caso y prueben coincidencias entre superficies, alias, tildes, idiomas, jerarquía, estado activo/inactivo, diferencias entre sinónimo y término de diccionario, visibilidad de sugerencias, permisos y transiciones. Verificar que el identificador elegido por la empresa corresponde a la categoría mostrada al administrador. Preservar permisos y límites existentes; actualizar ayuda/manual de usuario y entregar evidencia y limitaciones.

Alcance: diagnóstico, cambio y pruebas en el entorno autorizado de desarrollo/pruebas conforme al protocolo. No se autoriza publicación masiva de relaciones, despliegue a producción ni cambios de datos reales por esta instrucción. Preservar la tarea activa y los cambios locales existentes; registrar esta nueva solicitud sin reutilizar identificadores. Si la rama o la versión desplegada difiere de la examinada, verificar la fuente real antes de concluir.

Autoría: comentario publicado por el orquestador a petición expresa del propietario en este chat.

## Traspaso (2026-11-09): dónde quedó y cómo retomar

El propietario pidió llevar esta tarea a una sesión nueva y dejar la sesión anterior para TASK-0013.

**Mensaje para la sesión nueva:**

> Trabajá en TASK-0014. Leé `docs/orquestador/tasks/0014-taxonomy-search-coherence-term-linking.md` (tiene el comentario fuente íntegro `6736283026` y el traspaso) y seguí desde «Próximos pasos». El código va en el worktree `C:\Proyectos\GitHub\PerfilAfiliadosCPV-wt-servicios`, rama `feat/taxonomy-search-coherence`. No toques TASK-0013 ni los cambios locales del propietario en el repo principal.

**Estado del código y de las ramas:**

| Qué | Valor |
|---|---|
| Staging (`origin/feature/upgrade-filament-v3`) | `90044f3adc8b086b1582adfecba290f1781d2d2e`, verificado el 2026-11-09. Es el código que ve el propietario |
| Rama de trabajo | `feat/taxonomy-search-coherence`, creada desde `90044f3` en el worktree `C:\Proyectos\GitHub\PerfilAfiliadosCPV-wt-servicios`. **Todavía sin cambios ni push** |
| Repo principal | rama `fix/sec-01-r4-01-role-editor-preserve`; solo documentación. Los cambios locales del propietario (`AGENTS.md`, `CLAUDE.md`, `PROTOCOL.md`, `SESSION_RESUME.md`) no se tocan |
| Código idéntico | `TaxonomyCategorySearch`, `EmbeddingClient` y `TaxonomyCategory` son iguales en las dos ramas, así que el bootstrap del repo principal sirve para reproducir |

**Reproducción interrumpida, sin efecto:**
- `0014-assets/balancin_probe.php` corre en solo lectura (`set transaction read only` y `rollBack`) contra la base compartida, por el puerto 6543.
- El propietario detuvo la primera corrida, que no dejó salida ni procesos abiertos. No hubo escrituras posibles.
- Correr:

  ```bash
  php docs/orquestador/tasks/0014-assets/balancin_probe.php > <carpeta temporal>/balancin_probe.json
  ```

  con el PHP de `ENVIRONMENT.md`. La salida tiene nombres de categorías, no de empresas.
- **Qué devuelve:**
  - el selector de la empresa para «balancín», «balancin», «Balancín», «BALANCIN», «balancines», «pumping unit» y «pump jack», con código, nivel, estado, ruta, fuente y explicación de cada resultado;
  - la búsqueda por nombre ES de la administración tal como está implementada, en categorías, familias y grupos;
  - la misma búsqueda normalizada;
  - los sinónimos de categoría y su total;
  - los términos del diccionario con sus alias y todas sus relaciones término→CPV, con estado, tipo, peso y origen.

## Diagnóstico (solo código, a confirmar con la reproducción)

1. **Selector de la empresa:** `EmpresaResource/RelationManagers/TaxonomyCategoriesRelationManager.php` llama a `opcionesBusqueda()`, que usa `TaxonomyCategorySearch::search($q, 15)`.
   - **Tres niveles, en este orden:**
     - `lexical`: nombre ES o EN, o `taxonomy_category_synonyms`, con `unaccent … ilike '%q%'`;
     - `dictionary`: `taxonomy_terms.term`, `canonical_term` o `taxonomy_term_aliases`, solo con relaciones `approved`;
     - `semantic`: embeddings de la categoría, que rellena hasta 15.
   - Filtra `is_active = true` y `level != 0`, así que deja fuera los grupos y deja entrar familias y categorías.
   - **Solo muestra la ruta.** `match_type` y `explanation` se calculan pero no se muestran, así que la empresa no distingue un nombre de una sugerencia semántica.
   - **Límite:** el `limit` del nivel léxico corta antes de deduplicar por categoría.
   - **Familias:** el explorador busca con `opcionesFamilia()` (nombre de traducción con `unaccent`, sin locale ni código) y lista sus hijas.
2. **Administración:** `TaxonomyCategoryResource` (nivel 2), `TaxonomyFamilyResource` (nivel 1) y `TaxonomyGroupResource` (nivel 0).
   - **Columna Código:** `searchable()` por defecto de Filament.
   - **Columna Nombre (ES):** busca con `whereHas('translations', locale es, name like "%q%")`. En Postgres `like` **distingue mayúsculas y tildes**: «balancin» no encuentra «Balancín». No busca en EN, en sinónimos ni en el diccionario.
   - **Filtro «Sinónimo / término local (diccionario)»:** consulta `taxonomy_category_synonyms` (pocas filas), **no** el diccionario `taxonomy_terms` ni sus relaciones término→CPV. El nombre confunde.
   - **Búsqueda global:** solo `code`, `branch` y `subbranch`.
   - **Familias y grupos:** verificar si su columna de padre y sus filtros usan la misma lógica.
3. **Diccionario:**
   - `TaxonomyTermResource` tiene relation managers de alias, relaciones CPV, servicios y fuentes.
   - `TaxonomyTermCpvRelationResource` administra las relaciones, con estados y aprobación.
   - **Falta en la ficha de categoría:** no muestra los términos asociados ni permite asociar uno existente. Sus relation managers son solo Traducciones y Sinónimos.

## Reproducción (2026-11-09, sesión nueva)

**Fuente verificada:** staging (`/opt/perfilafiliados`) está en `90044f3`, el mismo código que la rama de trabajo.
Corridas en solo lectura (transacción `read only` revertida, sin cuentas reales, solo nombres de categorías):
`balancin_probe.php` desde esta máquina contra la base compartida, y el mismo selector dentro del contenedor
`app` de staging. La sonda tenía un error (`taxonomy_terms.status` no existe; la columna es
`mapping_review_status`) y se corrigió.

**Evidencia directa:**

| Qué | Resultado |
|---|---|
| Nivel semántico | **apagado** en staging y en local: falta `MCP_EMBED_URL`/`MCP_EMBED_TOKEN`, `EmbeddingClient` devuelve null. Hoy ningún resultado del selector es semántico |
| Selector, «balancín», «balancin», «Balancín», «BALANCIN» | **una** opción: `CPV-29.04.02G` (id 2558), nivel 2, activa, *Belongs*, «Terminación de Pozos > Equipos > Balancín», por nombre (`lexical`) |
| Selector, «balan», «balanc», «Balanc» (mientras se escribe) | **dos** opciones: `CPV-29.04.02G` «Balancín» y `CPV-27.03.19S` (id 2437) «Servicios de Perforación > Servicios de Perforación Onshore > Perforación Bajobalanceada (UBD)», las dos por nombre |
| Selector, «balanci», «balancí» | una: `CPV-29.04.02G` |
| Selector, «bal» | 15 (tope), entre ellas «Gestión de Instalaciones > Servicio Integral» (Familia `CPV-44.01`), que coincide por un nombre que no se ve; ninguna muestra el motivo |
| Selector, «balancines», «pumping unit», «pump jack» | ninguna |
| Admin, Nombre (ES) con `like` | «Balancín» encuentra `CPV-29.04.02G`; «balancín» y «balancin» **no encuentran nada** (distingue mayúsculas y tildes). Familias y grupos: nada |
| Nombres ES/EN normalizados con «balanc» | `CPV-29.04.02G` (es «Balancín»), `CPV-27.03.19S` (es «Perforación Bajobalanceada (UBD)», en «Underbalanced Drilling (UBD)»), `CPV-21.02.04G` (**inactiva**, «Sistemas de Estabilización del Balanceo mediante el Timón») |
| Sinónimos de categoría | ninguno con «balanc»; la tabla tiene 3 filas en total |
| Diccionario | término «balancín», canónico «pumpjack», `mapping_review_status = auto_mapped`, sin alias. Una relación: `CPV-29.04.02G`, **approved**, `exact`, peso 0,99, fuente `auto_mapping_v2` |

**Conclusión:** las dos opciones que vio el propietario son, con alta probabilidad, las de la consulta parcial
(«balan»/«balanc»). Es inferencia: no se vio su pantalla, pero es la única forma de obtener exactamente dos
opciones con este código y estos datos. **No son equivalentes:**
- `CPV-29.04.02G` «Balancín» es la categoría correcta, coincide por nombre y además tiene la relación de
  diccionario aprobada;
- `CPV-27.03.19S` «Perforación Bajobalanceada (UBD)» es un servicio de perforación que entra solo porque
  «Bajo**balanc**eada» contiene la subcadena. Es ruido de la coincidencia por subcadena.

La administración no la encuentra «del mismo modo» porque su búsqueda por nombre usa `like` sin `unaccent` ni
`ilike`: «balancin» o «balancín» en minúscula no devuelven nada. Además no busca en EN, sinónimos ni diccionario,
y las familias y grupos no tienen ninguna coincidencia que mostrar (la jerarquía de «Balancín» es
`CPV-29` Terminación de Pozos > `CPV-29.04` Equipos).

**Otros hallazgos:**
- el diccionario no ayuda con «pumping unit»/«pump jack»: su canónico es «pumpjack» y no tiene alias;
- el plural «balancines» no encuentra nada (sin derivación de plurales).

Ninguno de los dos se corrige en esta tarea sin autorización: agregar alias es un cambio de datos.

## Próximos pasos (plan del traspaso)

> Hechos el 2026-11-09, salvo el despliegue a staging, que espera autorización. El resultado está en «Diseño y
> cambios» y en «Evidencia y limitaciones».

1. **Reproducir** con `balancin_probe.php` y documentar aquí las dos opciones que ve la empresa: código, ruta, estado, fuente, relaciones aprobadas y si son correctas. Comparar con la administración. Si hace falta, confirmar la vista real en staging con una cuenta del propietario, solo lectura.
2. **Diseñar un servicio común de búsqueda** (por ejemplo `TaxonomyLookup`) con un solo criterio:
   - **Campos:** código, nombres ES/EN, sinónimos de categoría, términos, `canonical_term` y alias del diccionario.
   - **Normalización:** `unaccent`, sin distinguir mayúsculas.
   - **Resultado:** el motivo de cada coincidencia (nombre, sinónimo, término o alias aprobado, sugerencia semántica), el estado y la jerarquía.
   - **Alcance de la empresa:** solo categorías activas y elegibles; lo semántico va marcado como sugerencia.
   - **Alcance de la administración:** todos los estados visibles, sin límite de selector y sin semántica que se haga pasar por vínculo. Las relaciones no aprobadas se ven con su estado.
   - **Usos:** las listas de categoría, familia y grupo, y el selector de la empresa.
   - **Desde una categoría:** llegar a su familia y su grupo.
3. **Mostrar el motivo en la interfaz:** en el selector de la empresa (etiqueta o texto bajo cada opción) y en la administración (columna o indicador). Renombrar el filtro de sinónimos para no confundirlo con el diccionario.
4. **Ficha de categoría:** nuevo relation manager «Términos del diccionario».
   - **Qué muestra:** los términos asociados, con el estado de cada relación.
   - **Asociar:** un término existente, buscado sin crear duplicados.
   - **Relaciones previas** (pendiente, rechazada, aprobada): se tratan sin duplicar.
   - **Estado de la relación nueva:** pendiente o en revisión, según los estados que ya use `TaxonomyTermCpvRelation`.
   - **Aprobar y publicar:** por el flujo existente, con sus permisos, motivo y auditoría (`TaxonomyAuditLog`). Revisar `TaxonomyTermCpvRelationResource` y la policy antes de diseñar.
5. **Pruebas:** el caso «balancín», tildes y mayúsculas, ES/EN, alias, jerarquía, activa e inactiva, sinónimo frente a término, visibilidad de sugerencias, permisos y transiciones de estado. También que el id elegido por la empresa sea la misma categoría que ve el administrador.
   - Correrlas en el runner aislado de Contabo (ver la memoria del proyecto y `ENVIRONMENT.md`). Localmente faltan `ext-intl` y `pdo_pgsql`.
6. **Cierre:**
   - actualizar el manual de usuario (`resources/manual/manual_usuario.md`) y la prueba de contenido del manual;
   - entregar la evidencia y las limitaciones en este archivo;
   - pasar a READY_FOR_REVIEW.
   - **Despliegue a staging:** solo con autorización explícita del propietario.

## Diseño y cambios

Rama `feat/taxonomy-search-coherence`. Commits: `2a171de` (código, manual y pruebas), `3eae680` y `a8020cf`
(ajustes de pruebas). Sin migraciones, sin cambios de datos, sin cambios de permisos.

### 1. Servicio común `App\Services\Taxonomy\TaxonomyLookup`

**Campos:**
- código;
- nombre ES y nombre EN;
- sinónimo de categoría;
- término, `canonical_term` y alias del diccionario, a través de su relación término→CPV.

**Normalización:** `unaccent` sin distinguir mayúsculas. Los comodines `%` y `_` de lo que se escribe se toman
literalmente.

**Resultado:** una fila por categoría, con todos sus motivos (campo, texto que coincidió y estado de la
relación) y una **calidad** que fija el orden:
- 0, el texto completo es igual;
- 1, empieza una palabra;
- 2, está dentro de una palabra.

**Alcances:**
- **Empresa:** activas, sin Grupos, del diccionario solo `approved`.
- **Administración:** todos los estados y niveles. La jerarquía se recorre hacia arriba: una Familia aparece si
  coincide una hija, y un Grupo si coincide una familia o una nieta.

**Sin semántica:** el servicio nunca escribe.

### 2. Selector de la empresa

**Búsqueda principal (`TaxonomyCategorySearch`):** los niveles léxico y de diccionario salen del servicio común.
- La semántica sigue de relleno.
- Cada opción muestra «ruta · código · motivo»:
  - «por nombre» o «por nombre en inglés «…»»;
  - «por código»;
  - «por sinónimo «…»»;
  - «término aprobado del diccionario «…»»;
  - «sugerencia por similitud, sin confirmar».
- Las subcadenas dentro de una palabra van al final con «(parte de una palabra)».
- Las rutas salen en 2 consultas para todos los resultados, en vez de 2 por resultado.

**Explorador de familias:** usa el mismo criterio. Escribir una categoría lleva a su Familia («contiene CPV-…»).

### 3. Administración (concern `SearchesTaxonomyCoherently`)

Se aplica a Categorías, Familias y Grupos.

**Búsqueda y columnas:**
- el cuadro de búsqueda usa el criterio común;
- columna **Coincidencia**, que solo aparece al buscar: dice por qué apareció cada fila, con el estado de la
  relación y «Contiene CPV-… (inactiva)» para las coincidencias de las hijas;
- columna **Familia / Grupo** en Categorías.

**Filtros:**
- **Término del diccionario** (filtro nuevo): término, canónico o alias, con estado opcional de la relación;
- **Sinónimo de la categoría (no incluye el diccionario)**: es el filtro de sinónimos, renombrado.

**Familias:** la búsqueda por código del Grupo pasa de `like` a `ilike`.

### 4. Pestaña «Términos del diccionario»

Está en la ficha de la categoría, la familia y el grupo (`TermRelationsRelationManager`).

**Qué muestra:** las relaciones término→CPV del nodo en cualquier estado, con el término, su canónico y sus alias.

**Asociar término existente:** busca por término, canónico o alias. Cada opción dice si ya está relacionado aquí
y en qué estado. Muestra las relaciones actuales del término y pide peso y motivo. Las reglas viven en
`TermCategoryLinker`:
- **Permiso:** `create_taxonomy::term::cpv::relation`, el mismo del alta manual.
- **Relación nueva:** queda `needs_review`, tipo `manual`, fuente `manual_category_link`, `confidence` 1,0, y se
  audita con el motivo.
- **Relación viva** (candidata, auto-mapeada, en revisión o aprobada): no se duplica ni se toca; se informa su
  estado. Se compara por `category_id` y por `cpv_code`, con `lockForUpdate`. Si hay una carrera, el
  `UNIQUE` existente corta y se informa la que quedó.
- **Relación rechazada o descartada:** solo se reabre a `needs_review` si se marca «Reabrir» y se tiene
  `taxonomy_edit_relations`. Se reabre la misma fila y se audita.

**Aprobar y Rechazar:** son las acciones existentes de `ManagesTaxonomyRelationReview`, con `taxonomy_publish`
o `taxonomy_edit_relations`, motivo obligatorio y auditoría.

**Fuera de la pestaña:** «Abrir relación» lleva a la ficha existente, y la edición y el borrado se hacen allí.

### 5. Ayuda y manual

- **Manual del afiliado**, sección «Búsqueda libre»: los motivos, el ejemplo «balanc», el consejo de terminar la
  palabra, y la búsqueda por categoría en el explorador.
- **Ayuda de administración** (`ayuda_solicitudes_admin.md`), sección nueva «Verificar si una categoría existente
  cubre la solicitud»: la búsqueda, la columna Coincidencia, los filtros, la pestaña y las reglas de asociar.

**Diferencias de alcance justificadas:**
- la empresa no ve categorías inactivas, Grupos ni relaciones sin aprobar;
- la administración ve todo con su estado y no ve semántica.

## Evidencia y limitaciones

**Pruebas en el runner aislado de Contabo:**

| Corrida | Resultado |
|---|---|
| `a8020cf`, suite completa | **OK (679 tests, 7542 assertions)**; staging 200 antes y después; ningún contenedor `cpvtest` al terminar; las tablas de taxonomía gobernadas quedaron iguales |

**Qué cubren las pruebas nuevas:**
- `TaxonomySearchCoherenceTest` (11 casos, uno con 5 variantes):
  - tildes y mayúsculas en las dos superficies;
  - el orden «balanc» con su rótulo;
  - ruta, código y motivo;
  - EN, código, alias y canónico;
  - sinónimo frente a término;
  - activa e inactiva, grupo y relación no aprobada;
  - la jerarquía en administración;
  - los comodines;
  - el rótulo de la semántica;
  - **el id que elige la empresa es la categoría que ve el administrador**: mismo id, código y ruta;
  - los tres listados de Livewire y el filtro del diccionario.
- `TermCategoryLinkTest` (7 casos):
  - una relación nueva queda en revisión, auditada y fuera del buscador;
  - sin permiso no se puede asociar;
  - no se duplica;
  - una relación rechazada se reabre solo si se pide y con permiso;
  - la pestaña lista y asocia;
  - la acción se oculta sin permiso;
  - aprobar exige `taxonomy_publish` y motivo, queda auditado, y después la relación sí lleva a la empresa a la
    categoría.
- `UserManualContentTest`: el manual explica los motivos con las palabras del código.

**Comprobaciones con datos reales, solo lectura:** `TaxonomyLookup` sobre la base compartida y en el contenedor
de staging, en transacción `read only` revertida.

| Comprobación | Resultado |
|---|---|
| Empresa, «balanc» | «Balancín» primero («por nombre; término aprobado del diccionario «balancín»»); «Perforación Bajobalanceada (UBD)» después («parte de una palabra») |
| Empresa, «balancin», «BALANCÍN», «pumpjack», «CPV-29.04.02G», «29.04.02» | `CPV-29.04.02G` |
| Administración, «balancin» | la categoría, la Familia `CPV-29.04` y el Grupo `CPV-29` |
| Tiempo en staging | ~70–80 ms por búsqueda (~310 ms con «a»), frente a 0,2–1,9 s del selector anterior, que hacía 2 consultas por resultado |

**Limitaciones:**
- **Sin semántica en staging:** el nivel semántico está apagado en staging y en local (falta
  `MCP_EMBED_URL`/`MCP_EMBED_TOKEN`), así que su rótulo solo se probó con un resultado construido. Configurarlo
  es decisión del propietario.
- **Sin pruebas de navegador:** las pruebas de Livewire cubren el HTML de los listados y la pestaña, no un
  navegador.
- **Datos no corregidos:** «pumping unit», «pump jack» y el plural «balancines» siguen sin encontrar
  «Balancín». Haría falta agregar alias o sinónimos, que es un cambio de datos no autorizado por esta tarea.
- **Búsqueda global del panel** (la lupa superior): sigue buscando solo código, branch y subbranch. No se cambió.
- **Coincidencias por subcadena:** se conservan, rotuladas y al final, en vez de ocultarse. Así no se pierden
  resultados legítimos como «Válvulas» con «valv».
- **Sin desplegar:** no hay despliegue a staging, merge ni producción. Desplegar requiere autorización explícita
  del propietario.

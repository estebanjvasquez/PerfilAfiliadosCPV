# TASK-0014 — Coherencia de búsqueda de taxonomía y asociación de términos desde la categoría

| Campo | Valor |
|---|---|
| Estado | IN_PROGRESS, en pausa para retomarla en una sesión nueva (2026-11-09; ver «Traspaso») |
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

## Próximos pasos

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

_Pendiente (sesión nueva)._

## Evidencia y limitaciones

_Pendiente (sesión nueva)._

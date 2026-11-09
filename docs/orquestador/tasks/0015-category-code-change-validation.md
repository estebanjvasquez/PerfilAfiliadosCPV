# TASK-0015 — Edición de categoría: jerarquía visible y cambio de código validado y auditado

| Campo | Valor |
|---|---|
| Estado | **READY_FOR_REVIEW, desplegada en staging** (2026-11-09). Rama `feat/category-code-change`, REVIEW_HEAD `d8e2aae`, BASE `a8020cf` (TASK-0014). Staging: `a8020cf..d0ecbf9` (run `42603503471`) y `d0ecbf9..d8e2aae` (run `42605041183`), los dos success, por instrucción del propietario en el chat («despliega y si agregalo»); verificado en `d8e2aae`. UAT del propietario pendiente; sin producción |
| Fuente | Pedidos del propietario en el chat de la sesión de TASK-0014, 2026-11-09: (1) la ficha de edición de `CPV-29.04.02G` (captura); (2) «si agregalo», que extiende la misma protección a Familias y Grupos. Sin comentario en el Issue #2 todavía |
| Relación | Nueva; no cambia el propósito ni el identificador de TASK-0014. Se apoya en su rama porque esa es la que está en staging |
| Gates | sin producción; despliegue a staging solo con autorización explícita del propietario; ningún cambio de datos reales en esta tarea |

## Pedido (palabras del propietario)

> si edito una categoria, no queda claro que gurpo->familia pertenece tambien el codigo permite cambiarlo manualmene si verificaciones solo indicando que no debe repetirse, en la pantalla de crear una categoria si estan claro el grupo y familia y el condigo lo genera siguiendo la nomenclatura y utilizando el corrrelativo al final, si se va a permitr cambiar el codigo debe hacerse la validacion correspondiente y dejar escrito en los registro por que s cambio

## Diagnóstico

**Antes, en la ficha de edición de una categoría:**
- se veía solo «Familia o Grupo padre», sin el Grupo;
- el código se podía teclear libremente: se validaba que fuera único y el observer exigía que colgara del padre;
- el tipo (Bien/Servicio) se podía cambiar sin tocar la letra G/S del código;
- no quedaba ningún registro de por qué se cambiaba.

**Medido en staging (2026-11-09, solo lectura):** las 3.116 categorías siguen la nomenclatura
`CPV-<familia>.<NN|NNN><G|S>` con la letra acorde a su tipo, y todas cuelgan de una Familia. Ninguna cuelga
directo de un Grupo.

**El código se guarda como texto fuera de la tabla de categorías en:**
- `taxonomy_term_cpv_relations.cpv_code` y `legacy_service_cpv_relations.cpv_code`, las dos con `category_id`;
- `company_term_matches.cpv_code`, sin `category_id`.

La edición de una relación término→CPV vuelve a resolver la categoría por `cpv_code`. Con un código viejo la
habría perdido.

## Cambios

**Formulario de edición (`TaxonomyCategoryResource`):**
- muestra **Grupo** y **Familia** con código y nombre;
- **Código** y **Tipo de oferta** quedan deshabilitados y no se envían; **Familia** queda oculta;
- `EditTaxonomyCategory::mutateFormDataBeforeSave` descarta además `code`, `parent_id` y `tipo_oferta` del lado
  del servidor.

**Acción «Cambiar código o familia»** en la página de edición. Requiere el permiso `update` de la categoría:
- sigue el flujo guiado del alta: Grupo → Familia → Tipo;
- el código sale del **correlativo siguiente** (`buildNextCode`, el mismo del alta) o se **escribe a mano**;
- muestra el impacto: empresas vinculadas, relaciones y coincidencias de rastreo;
- el **motivo** es obligatorio.

**Validación (`CategoryCodeChanger`):**
- empieza con `CPV-`;
- es hijo directo de la Familia elegida;
- el último segmento tiene 2 o 3 dígitos y termina en `G` o `S` según el tipo;
- es único sin distinguir mayúsculas;
- el padre es una Familia;
- hay algún cambio;
- el motivo tiene al menos 10 caracteres.

**Al aplicar,** en una transacción con bloqueo de la fila:
- guarda el código en mayúsculas; `path` y `level` los recalcula el observer;
- actualiza `cpv_code` en las relaciones término→CPV y de servicios de la categoría, y en las coincidencias de
  rastreo con el código viejo;
- registra en `taxonomy_audit_logs`, con el motivo, una fila por campo cambiado (`code`, `parent_id`,
  `tipo_oferta`) y una `code_references` con los conteos.

Las empresas vinculadas y los embeddings apuntan por id y no cambian.

## Evidencia

**Runner aislado, suite completa en `d0ecbf9`:** **OK (686 tests, 7607 assertions)**. Staging respondió 200
antes y después, y no quedaron contenedores `cpvtest`.

`CategoryCodeChangeTest` (7 casos) cubre:
- la ficha muestra Grupo y Familia y bloquea los tres campos;
- un guardado normal con esos campos manipulados no los cambia;
- **10 rechazos de validación:** otra familia, sin prefijo, sin letra, letra que no es del tipo, correlativo de
  1 dígito, repetido, repetido en minúsculas, padre Grupo, motivo corto y sin cambios;
- un cambio válido actualiza la relación del diccionario y la auditoría con el motivo;
- la acción mueve la categoría a otra familia con el correlativo siguiente;
- un código inválido en la acción no cambia nada;
- sin permiso `update` no se puede cambiar.

## Ampliación: Familias y Grupos (`d8e2aae`)

**Nomenclatura medida en staging:** 48 grupos `CPV-NN` y 333 familias `CPV-<grupo>.NN`, todos consistentes.

**Fichas de edición:**
- **Familia:** muestra el Grupo con código y nombre; código y Grupo quedan bloqueados.
- **Grupo:** código bloqueado.
- **Al crear:** se valida la forma, `CPV-NN.NN` para una Familia y `CPV-NN` para un Grupo.

**Acción común** (`ChangesTaxonomyCode`): «Cambiar código o grupo» en Familia y «Cambiar código» en Grupo.
Ofrece el siguiente código libre (`CategoryCodeChanger::nextParentCode`) o uno escrito a mano. Muestra cuántos
descendientes se renumeran.

**`CategoryCodeChanger` en los tres niveles:**
- **Renumeración:** todos los descendientes cambian el prefijo y conservan su correlativo y su letra:
  `CPV-29.04` → `CPV-29.07` lleva `CPV-29.04.02G` a `CPV-29.07.02G`.
- **Choques:** antes de aplicar comprueba que ningún código renumerado exista ya fuera del subárbol.
- **Referencias:** actualiza las de texto de todo el subárbol.
- **Auditoría:** registra cada código renumerado con «Renumerada por el cambio de X a Y: <motivo>».

**Familias protegidas.** `CPV-29.02`, `CPV-12.04` y `CPV-48.02` no se pueden renumerar, ni directamente ni a
través de su grupo:
- el código fuente las nombra fijas: `BuildEmpresaSearchDocuments::GENERIC_ATTRACTOR_FAMILY_CODES` y
  `HomologateServicesTaxonomy::CODIGOS_FAMILIA_EXCLUIDOS`, que ahora son públicas y que el servicio lee de su
  fuente;
- el Worker externo `empresa-tools.ts` también usa esos códigos;
- renumerarlas dejaría de excluirlas del índice de búsqueda sin aviso.

**Pruebas:** suite completa en `d8e2aae`, **OK (690 tests, 7662 assertions)**; staging respondió 200 antes y
después. `CategoryCodeChangeTest` suma 4 casos:
- renumerar una familia (4 categorías, referencias y auditoría);
- renumerar un grupo (familia y categoría, y rechazo de las formas inválidas);
- familias protegidas, directamente y por su grupo;
- fichas de Familia y Grupo bloqueadas, y mover una familia a otro grupo con el código siguiente.

## Limitaciones y pendientes

- **Familias protegidas:** para renumerarlas hay que cambiar primero las listas del código fuente y la del
  Worker externo.
- **Índice de búsqueda de empresas** (`empresa_search_documents`) y servicios externos (n8n, el Worker MCP): ven
  el código nuevo recién al reconstruir el índice. Las empresas no se pierden porque se vinculan por id.
- **Ediciones normales:** el resto de la ficha (nombres, relevancia) se sigue guardando sin registro en la
  auditoría, como antes.

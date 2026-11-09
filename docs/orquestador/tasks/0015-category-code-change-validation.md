# TASK-0015 — Edición de categoría: jerarquía visible y cambio de código validado y auditado

| Campo | Valor |
|---|---|
| Estado | **READY_FOR_REVIEW** (2026-11-09). Rama `feat/category-code-change`, REVIEW_HEAD `d0ecbf9`, BASE `a8020cf` (staging, TASK-0014). **No desplegada** |
| Fuente | Pedido del propietario en el chat de la sesión de TASK-0014, 2026-11-09, con una captura de la ficha de edición de `CPV-29.04.02G`. Sin comentario en el Issue #2 todavía |
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

## Limitaciones y pendientes

- **Familias y Grupos:** su código sigue editable a mano. Cambiarlo dejaría a las hijas con un código que ya no
  empieza por el del padre. Requiere la misma protección y, además, decidir si se renumeran las hijas: queda
  como propuesta para el propietario.
- **Índice de búsqueda de empresas** (`empresa_search_documents`) y servicios externos (n8n, el Worker MCP): ven
  el código nuevo recién al reconstruir el índice. Las empresas no se pierden porque se vinculan por id.
- **Ediciones normales:** el resto de la ficha (nombres, relevancia) se sigue guardando sin registro en la
  auditoría, como antes.

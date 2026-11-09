# TASK-0014 — Coherencia de búsqueda de taxonomía y asociación de términos desde la categoría

| Campo | Valor |
|---|---|
| Estado | IN_PROGRESS (2026-11-09) |
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

## Diagnóstico

_Pendiente._

## Diseño y cambios

_Pendiente._

## Evidencia y limitaciones

_Pendiente._

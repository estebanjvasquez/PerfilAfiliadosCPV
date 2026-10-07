# Paquete de UAT — buscador y revisión taxonómica

TASK-0008 (Issue #2, comentarios `6035726459` y `6035827197`). **Documentación y plantillas
únicamente**: nada de lo que hay acá modifica la aplicación, el Worker, la base de datos, la taxonomía
ni ningún secreto.

Son dos entregas independientes. Pueden avanzar en paralelo, con personas distintas.

---

## Entrega A — Prueba de aceptación del buscador

**Quién la hace:** una persona de la Cámara que conozca el sector y las empresas afiliadas. Sin perfil
técnico.
**Dónde:** <https://pruebas.camarapetrolera.app/cira-test/>
**Cuánto toma:** 60 a 90 minutos, divisibles en varias sesiones.

| Orden | Archivo | Para quién |
|---|---|---|
| 1 | [`GUIA_PRUEBAS_BUSCADOR_CLIENTE.md`](GUIA_PRUEBAS_BUSCADOR_CLIENTE.md) | **Empiece acá.** Cliente |
| 2 | [`FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv`](FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv) | Cliente — planilla (Excel) |
| 2' | [`FORMATO_RESULTADOS_BUSCADOR_CLIENTE.md`](FORMATO_RESULTADOS_BUSCADOR_CLIENTE.md) | Cliente — misma planilla en documento. **Use una de las dos** |
| 3 | [`GUIA_CLASIFICACION_RESULTADOS_UAT.md`](GUIA_CLASIFICACION_RESULTADOS_UAT.md) | SISTEG — triage técnico. **No es para el cliente** |
| 4 | [`RESUMEN_UAT_CLIENTE_TEMPLATE.md`](RESUMEN_UAT_CLIENTE_TEMPLATE.md) | SISTEG + Cámara — cierre y decisión final |

El cliente llena la planilla **hasta la columna `Comentario_cliente`** y se detiene ahí. Las columnas
siguientes son del triage técnico.

---

## Entrega B — Revisión taxonómica por el pasante

**Quién la hace:** el pasante de ingeniería de la Cámara.
**Qué revisa:** las relaciones propuestas entre términos del sector y categorías CPV. Hay **9.282** en
estado *"En revisión"* de un total de 9.749.

> **⚠️ Esta etapa es de REVISIÓN REGISTRADA, no de publicación.** El pasante **lee** la pantalla y anota
> su criterio en una planilla. **No usa *Aprobar*, ni *Rechazar*, ni *Editar*, ni *Eliminar*, ni
> acciones masivas**, y por lo tanto el lote produce una planilla y **cero cambios en la taxonomía**: al
> terminar, el sistema queda igual que antes. Habilitar decisiones operativas requiere una **tarea
> aparte con autorización explícita y separada** de la Cámara.

| Orden | Archivo | Para quién |
|---|---|---|
| 1 | [`GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`](GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md) | **Empiece acá.** Pasante. La sección 11 trae el texto listo para asignarle la tarea |
| 2 | [`FORMATO_REVISION_TAXONOMICA_PASANTE.csv`](FORMATO_REVISION_TAXONOMICA_PASANTE.csv) | Pasante — registro (Excel). **Borre las filas de ejemplo `EJ-`** |
| 2' | [`FORMATO_REVISION_TAXONOMICA_PASANTE.md`](FORMATO_REVISION_TAXONOMICA_PASANTE.md) | Pasante — mismo registro en documento. **Use uno de los dos** |
| 3 | [`RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`](RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md) | Pasante + supervisor — cierre de cada lote |

---

## Tres cosas que conviene saber antes de empezar

**1. La pantalla de pruebas es un chat, no un buscador con lista de resultados.** Se llama *"CIRA —
Asistente CPV"*. Hay que hacer clic en **"Nueva conversación"** antes de cada consulta, porque el chat
recuerda lo anterior y eso contamina la prueba.

**2. Antes de enviarle el enlace al cliente, verifique que el flujo de n8n esté activo.** La pantalla
conversa a través de un webhook de n8n; si ese flujo está detenido, **todas** las consultas fallan igual
y el UAT entero se ve como una falla catastrófica del buscador cuando no lo es.

**3. En la etapa de calibración, la pantalla de revisión taxonómica es de sólo lectura para el
pasante.** Las cinco decisiones se registran únicamente en la planilla y la fila queda intacta en *"En
revisión"*. La pantalla **sí tiene** botones *Aprobar* y *Rechazar* y **sí tiene acciones masivas**, y
puede que le aparezcan según sus permisos: igual no se usan. La sección 3 de la guía del pasante
documenta a qué acción real correspondería cada decisión **si** una tarea posterior lo autoriza.

---

## Alcance y gobernanza

- Esta tarea es **documentación y plantillas**. No otorga ningún permiso operativo.
- **La calibración del pasante es estrictamente de revisión registrada:** no modifica ninguna relación
  Término↔CPV, no aprueba ni rechaza nada en el sistema y no publica nada al buscador.
- Habilitar al pasante para aprobar relaciones de verdad requiere una **autorización explícita y
  separada**, posterior a la revisión de la guía de calibración y de sus resultados. Alcanzar la tasa de
  coincidencia sugerida **no** la otorga ni la anticipa.
- El pasante **no** recibe acceso directo a la base de datos.
- Ninguna corrección que surja del UAT se ejecuta en el mismo ciclo que el diagnóstico.
- Toda corrección de ranking o pesos exige volver a correr la regresión congelada de 32 consultas antes
  de aceptarse.

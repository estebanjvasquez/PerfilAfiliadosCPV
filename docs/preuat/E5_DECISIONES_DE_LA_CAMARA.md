# E5 — Decisiones pendientes de la Cámara

**TASK-0033** · 2026-11-17 · Para: la Cámara Petrolera de Venezuela, por medio del propietario · Índice: [`00_INDICE.md`](00_INDICE.md)

**Qué es:** las decisiones de datos y de criterio que solo puede tomar la Cámara. Para cada una hay una pregunta concreta y un
formato de respuesta. **No se cambió ningún dato.** Las cifras se verificaron hoy con consultas de solo lectura y se
comparan con las anteriores, que se conservan como antecedente.

**Regla del propietario que rige todo el documento:**
- `chamber_empresa` registra afiliaciones de una empresa a **otras cámaras**. **No** indica si pertenece a la CPV.
- Las empresas del Perfil de Afiliados son afiliadas a la CPV según su estado: `empresas.status_id = 1`, la casilla
  «Activo» del panel (TASK-0032, verificado en código y datos).
- **VIVO hoy:** 406 empresas, las 406 con `status_id = 1`. `chamber_empresa` tiene 173 filas de 92 empresas, y 314
  empresas activas no tienen ninguna; eso no les quita la condición de afiliadas.

**Dónde están los formatos:**
- [`formatos/`](formatos/), con el id de la empresa (sin nombres, porque el repositorio es público).
- La versión **con nombres**, para enviar a la Cámara, la tiene el propietario fuera del repositorio:
  `docs/uat/privado/2026-11-17_preuat/` (excluida de git).
- Se abren con Excel; el separador es `;`.

## D1. Empresas sin sector principal

| Dato | Hoy (VIVO, 2026-11-17 11:10Z) | Antes (DOC) |
|---|---:|---|
| Empresas activas sin sector principal | **191** de 406 | 194 de 406 (TASK-0011, `tasks/0011`) |
| … de ellas, con sector secundario | 3 | — |
| … de ellas, **solo con servicios «X»** (ningún servicio real) | **103** | — |

**Por qué importa:**
- El sector principal ordena la ficha, los filtros y parte del buscador.
- El sistema no puede deducirlo: es un dato de la Cámara (`tasks/0013`, decisiones del propietario).

**Pregunta 1:** para cada empresa de la lista, ¿cuál es su sector principal y, si lo tiene, su sector secundario?

**Formato:** [`E5_F1_empresas_sin_sector_principal.csv`](formatos/E5_F1_empresas_sin_sector_principal.csv). Columnas `RESPUESTA_*`.

**Sectores válidos** (escribir el nombre tal como está):
- Ingeniería y servicios integrados
- Servicios tecnológicos
- Servicios asociados
- Servicios a pozos
- Operadores
- Fabricantes
- Suplidores
- Construcción

## D2. Servicio «X» (marcador sin significado)

| Dato | Hoy (VIVO) | 2026-11-07 (DOC, `tasks/0013`) |
|---|---:|---:|
| Servicios «X» en el catálogo | 8 (uno por sector) | 8 |
| Vínculos empresa–«X» | **249** en **235** empresas | 250 en 236 |
| «X» es la única pertenencia de la empresa a ese sector | 243 | 244 |
| … pero el sector ya está declarado como principal o secundario (**prioridad media**) | 124 | 125 |
| … y el sector **no** está declarado (**prioridad alta**: perdería el sector si se retira «X») | **119 vínculos en 109 empresas** | 119 en 109 |
| La empresa tiene otro servicio en ese sector (**prioridad baja**) | 6 | 6 |

Desde el 07/11 desapareció un vínculo de prioridad media. Hoy no se puede saber por qué: no hay auditoría de las ediciones
normales de la ficha (deuda de TASK-0015).

**Vínculos de prioridad alta por sector:**

| Sector | Prioridad alta |
|---|---:|
| Servicios asociados | 34 |
| Suplidores | 18 |
| Construcción | 15 |
| Ingeniería y servicios integrados | 13 |
| Servicios tecnológicos | 13 |
| Fabricantes | 13 |
| Servicios a pozos | 11 |
| Operadores | 2 |

**Decisión previa del propietario (DEC, 2026-11-07):** corregir las empresas que tienen «X» y **después** retirar «X» del
catálogo. Antes de cambiar datos, la Cámara revisa (`6802438608`, punto 6).

**Pregunta 2:** para cada vínculo, ¿cuál es el servicio real de la empresa en ese sector? Si no tiene ninguno, ¿se la quita
de ese sector? Empiecen por los de prioridad alta.

**Formato:** [`E5_F2_vinculos_servicio_X.csv`](formatos/E5_F2_vinculos_servicio_X.csv), ordenado por prioridad.

**Pregunta 3:** ¿confirma la Cámara que «X» se retira del catálogo una vez corregidos los vínculos de prioridad alta?
Respuesta: sí o no, y fecha.

## D3. Capacidades frente a experiencias

**Cómo lo muestra CIRA hoy** (TASK-0029/0030, aceptación funcional del propietario en `6945403230`):
- **Capacidades:** lo que la empresa declara que ofrece, por servicio o categoría CPV.
- **Experiencias relacionadas:** proyectos registrados que se parecen a la búsqueda. Llevan la nota «La experiencia no
  acredita por sí sola fabricación o comercialización».
- Hay dos vistas que se excluyen entre sí: «Solo capacidades» y «Empresas con experiencias relacionadas».

**Caso observado por el propietario** («búsqueda directa de empresa», ejecución n8n `23163`; VIVO, leída hoy por la API):

| Empresa | Grupo | Cómo entró | Experiencias relacionadas |
|---|---|---|---|
| 177 (I.O.E.D) | capacidad, nivel «declarado» | coincidencia literal con «international offshore» en el nombre | 1 |
| 72 (Geohidra) | capacidad, nivel «similar» | **parecido de significado** entre «offshore» y su servicio «Costa afuera – plataformas, estaciones de flujo, plantas compresoras, hospedaje» | 1 |

**Qué explica la doble presencia:**
- El agente trató el nombre como una búsqueda de servicio, no como una consulta de ficha.
- La 72 entra por similitud y no por el nombre.
- Las dos aparecen en la vista de experiencias porque cada una tiene una experiencia relacionada.

**No es un defecto confirmado.** Decidir si el resultado es el correcto le corresponde a la Cámara.

**Pregunta 4:**
- a) Cuando se busca **el nombre de una empresa**, ¿deben aparecer también empresas parecidas por servicio, como la 72, o
  solo la empresa buscada?
- b) Una empresa con la capacidad declarada **y además** con experiencia relacionada, ¿debe aparecer en las dos vistas o
  solo en «capacidades»?
- c) Una empresa **sin** capacidad declarada pero con experiencia en el tema, ¿debe aparecer en la vista de experiencias?
  Es el caso de «macolla»: 0 capacidades y la opción de consultar experiencias.

**Formato:** [`E5_F6_capacidades_experiencias.csv`](formatos/E5_F6_capacidades_experiencias.csv), una fila por pregunta,
con una respuesta y un ejemplo.

## D4. Términos, alias y vocabulario regional

| Término | En el diccionario hoy (VIVO, `taxonomy_terms`) | Situación |
|---|---:|---|
| balancín / balancin | 1 | Existe |
| balancines | 0 | Hoy funciona porque el agente lo corrige (V1b, ejecución 23156: `resolvedPhrases` «balancines» y «balancín»). No está en el diccionario |
| pumping unit | 0 | No encuentra la empresa (`tasks/0014`, limitaciones). Agregarlo es un **cambio de datos no autorizado** sin decisión |
| pump jack | 0 | Igual |

**Pregunta 5:** ¿qué términos coloquiales, regionales, en inglés y siglas usa el personal de la Cámara para cada actividad?
¿Con qué categoría o servicio corresponde cada uno? Es el paso 3 del plan del buscador: un glosario regional validado por
el cliente (`tasks/0013`, «Plan propuesto»).

**Formato:** [`E5_F3_terminos_y_alias.csv`](formatos/E5_F3_terminos_y_alias.csv) (término; significa lo mismo que; región
o uso; ejemplo de búsqueda; comentario).

## D5. Resultados dudosos y casos de datos

| Tema | Hecho | Fuente | Pregunta |
|---|---|---|---|
| La Cámara como empresa | La empresa **451** es la propia Cámara: figura **activa** en el Perfil (VIVO) y es la autora de la solicitud de categoría n.º 1 | Sonda de hoy; DEC pendiente de `tasks/0013` | **Pregunta 6:** ¿debe la Cámara aparecer como empresa en las búsquedas y en el Perfil? |
| Ruido por «similar a» | En la evaluación del cliente (lote 001), 22 de los 41 resultados no relevantes del top 10 entraban por parecido de significado | DOC, `tasks/0013` paso 3 (2026-11-07) | **Pregunta 7:** en la UAT, señalar los resultados que sobran (lo hace el módulo de evaluación, E3) |
| Doble presencia I.O.E.D./Geohidra | Ver D3 | `6945403230`; ejecución 23163 | Pregunta 4 |
| Votos 👍/👎 | 13 votos, todos 👍, todos del personal; no cambian el orden | VIVO (`cira_search_feedback`) | Ninguna: informativo |

**Formato para casos que no caben en el módulo de evaluación:**
[`E5_F4_resultados_dudosos.csv`](formatos/E5_F4_resultados_dudosos.csv) (búsqueda; empresa_id; sobra, falta u orden;
comentario).

## D6. Solicitudes de categoría nuevas

| Solicitud | Empresa | Fecha | Motivo | Estado | Aviso por correo |
|---|---|---|---|---|---|
| 1 | 451 (la Cámara) | 2026-10-15 | No encuentra categoría | Pendiente de revisión | **Falló** |
| 2 | 76 | 2026-11-04 | No encuentra categoría | Pendiente de revisión | Enviado |
| 3 | 213 | 2026-11-06 | No encuentra categoría | Pendiente de revisión | Enviado |

Fuente: VIVO, `taxonomy_category_requests`. Además hay un análisis con IA guardado (TASK-0016); el propietario hizo la
UAT en `6802438608`.

**Pregunta 8:** para cada solicitud, ¿se crea una categoría nueva, se usa una existente (¿cuál?) o se rechaza?

**Formato:** [`E5_F5_solicitudes_de_categoria.csv`](formatos/E5_F5_solicitudes_de_categoria.csv). El texto de cada solicitud
está en la versión privada.

## Cómo responder y qué pasa después

1. El propietario envía a la Cámara las versiones **con nombres**, desde la carpeta privada.
2. La Cámara completa las columnas `RESPUESTA_*` y las devuelve. No hace falta hacerlo todo de una vez: lo urgente es la
   prioridad alta de D2 y D1.
3. Cada lote de respuestas se aplica con una **asignación nueva y su gate de escritura en la BD**, con conteos antes y
   después. Nada de esto se aplica solo.
4. Mientras tanto, CIRA sigue mostrando a esas empresas con los datos actuales.

Consultas que respaldan las cifras:
- [`sonda_solo_lectura.php`](../cira/task0033/bd/sonda_solo_lectura.php) y
  [`sonda_decisiones_camara.php`](../cira/task0033/bd/sonda_decisiones_camara.php), en transacción `READ ONLY`;
- resultado público en [`evidencia/bd_decisiones_camara_publico.json`](evidencia/bd_decisiones_camara_publico.json);
- resumen en [`formatos/E5_resumen_cifras.json`](formatos/E5_resumen_cifras.json).

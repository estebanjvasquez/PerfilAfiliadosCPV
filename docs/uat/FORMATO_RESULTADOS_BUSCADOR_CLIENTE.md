# Formato de resultados del buscador — versión Markdown

Misma planilla que `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv`, para quien prefiera escribir en un
documento en vez de en Excel. **Use una de las dos, no las dos.** El CSV es más cómodo si va a filtrar
o contar; este archivo es más cómodo si va a escribir comentarios largos.

Instrucciones de uso: `GUIA_PRUEBAS_BUSCADOR_CLIENTE.md`.

---

## 1. Diccionario de campos

Las columnas están en dos bloques. **El cliente llena hasta `Comentario_cliente` y se detiene ahí.**

### Bloque A — lo llena el revisor de la Cámara

| Campo | Qué poner | Valores |
|---|---|---|
| `Test_ID` | Identificador de la fila | Ya viene puesto (`G1-01`…`G8-10`) |
| `Grupo` | Grupo de la consulta | Ya viene puesto |
| `Consulta` | El texto exacto que escribió | Libre. En el Grupo 8 lo escribe usted |
| `Consulta_propuesta_por` | Quién propuso la consulta | `SISTEG` o `CLIENTE` |
| `Fecha` | Cuándo la probó | `AAAA-MM-DD` |
| `Evaluador` | Su nombre o iniciales | Libre |
| `Resultado_general` | Veredicto | `PASS` / `PARCIAL` / `FALLA` / `N/A` |
| `Puntuacion_1_a_5` | Nota | `5` `4` `3` `2` `1` o `N/A` |
| `Empresa_esperada` | Una empresa que esperaba ver. **Vacío si no tenía ninguna en mente** | Libre |
| `Empresa_esperada_aparece` | ¿Apareció? | `SI` / `NO` / `N/A` |
| `Posicion_empresa_esperada` | En qué lugar | `1`–`5`, `>5`, o vacío |
| `Resultados_relevantes_top5` | De las 5 primeras, cuáles sí corresponden | Libre |
| `Resultados_no_relevantes_top5` | De las 5 primeras, cuáles **no** corresponden | Libre |
| `Empresas_relevantes_ausentes` | Las que faltan y deberían estar | Libre |
| `Comentario_cliente` | Una o dos frases: qué pasó y por qué le parece mal | Libre |

### Bloque B — lo llena el equipo técnico (SISTEG) en el triage

**No hace falta que el cliente complete nada de esto.** Criterios en
`GUIA_CLASIFICACION_RESULTADOS_UAT.md`.

| Campo | Qué pone el equipo técnico | Valores |
|---|---|---|
| `Tipo_de_problema` | Clasificación de la causa | Lista cerrada, abajo |
| `Severidad` | Impacto | `CRITICA` / `ALTA` / `MEDIA` / `BAJA` / `NINGUNA` |
| `Requiere_revision_tecnica` | ¿Hay que investigarlo? | `SI` / `NO` |
| `Revision_tecnica` | Qué se encontró al investigar | Libre |
| `Clasificacion_tecnica` | Dónde está la causa raíz | `DATOS` / `TAXONOMIA` / `CPV` / `MOTOR` / `EVIDENCIA_WEB` / `UI` / `NO_ES_DEFECTO` |
| `Accion_recomendada` | Qué hacer | Libre |
| `Estado_seguimiento` | En qué va | `ABIERTO` / `EN_ANALISIS` / `EN_CORRECCION` / `RESUELTO` / `DESCARTADO` / `DIFERIDO` |
| `Referencia_issue` | Issue o comentario donde se gobierna | Libre |

### Valores permitidos de `Tipo_de_problema`

| Valor | Significado en una línea |
|---|---|
| `SIN_PROBLEMA` | El resultado fue correcto |
| `FALSO_POSITIVO` | Apareció una empresa que no corresponde |
| `FALSO_NEGATIVO` | Faltó una empresa que sí corresponde |
| `RANKING` | Están las correctas, pero en mal orden |
| `TERMINO_TAXONOMIA` | El término no está reconocido, o está mal asociado |
| `MAPEO_CPV` | El término apunta a una categoría CPV equivocada |
| `DATOS_EMPRESA` | La empresa no declara en su perfil lo que hace |
| `EVIDENCIA_WEB_FALTANTE` | No hay evidencia externa que respalde la capacidad |
| `CONSULTA_AMBIGUA` | La consulta admite varias lecturas razonables |
| `UI_USABILIDAD` | Problema de interfaz, no de relevancia |
| `OTRO` | Nada de lo anterior. **Explicar siempre en `Revision_tecnica`** |

---

## 2. Fichas de registro

Copie el bloque de abajo una vez por consulta. Hay 45 consultas en el CSV (35 propuestas + 10 suyas);
acá se incluyen las fichas de las 35 propuestas y 10 en blanco.

> Deje vacío lo que no aplique. **Un campo vacío es información válida**; un campo inventado no.

### Plantilla

```
Test_ID:
Grupo:
Consulta:
Consulta_propuesta_por:      SISTEG | CLIENTE
Fecha:                       AAAA-MM-DD
Evaluador:
Resultado_general:           PASS | PARCIAL | FALLA | N/A
Puntuacion_1_a_5:            5 | 4 | 3 | 2 | 1 | N/A
Empresa_esperada:
Empresa_esperada_aparece:    SI | NO | N/A
Posicion_empresa_esperada:   1..5 | >5 | (vacío)
Resultados_relevantes_top5:
Resultados_no_relevantes_top5:
Empresas_relevantes_ausentes:
Comentario_cliente:
--- de acá para abajo lo completa SISTEG ---
Tipo_de_problema:
Severidad:
Requiere_revision_tecnica:
Revision_tecnica:
Clasificacion_tecnica:
Accion_recomendada:
Estado_seguimiento:
Referencia_issue:
```

### Ejemplo ya llenado (ficticio, sólo para mostrar el nivel de detalle útil)

```
Test_ID:                     G3-03
Grupo:                       G3_INTENCION_MAS_CAPACIDAD
Consulta:                    transporte de gandolas
Consulta_propuesta_por:      SISTEG
Fecha:                       2026-10-09
Evaluador:                   J. Pérez
Resultado_general:           PARCIAL
Puntuacion_1_a_5:            3
Empresa_esperada:            (no tenía una en mente)
Empresa_esperada_aparece:    N/A
Posicion_empresa_esperada:
Resultados_relevantes_top5:  1ra y 3ra sí son de transporte de carga pesada
Resultados_no_relevantes_top5: 2da es una empresa de transporte de personal, no de carga
Empresas_relevantes_ausentes: falta al menos una que sé que mueve taladros en Zulia
Comentario_cliente:          Mezcla transporte de personal con transporte de carga. Para un afiliado
                             son dos servicios distintos y no deberían salir juntos.
--- de acá para abajo lo completa SISTEG ---
Tipo_de_problema:
Severidad:
...
```

Note cómo el comentario explica **por qué** está mal en términos del negocio. Eso es lo que permite
clasificar el hallazgo después.

---

## 3. Fichas de las consultas propuestas

### Grupo 1 — Productos y servicios directos

- [ ] **G1-01** — `tratamiento de aguas de perforación`
- [ ] **G1-02** — `levantamiento artificial`
- [ ] **G1-03** — `revestidor`
- [ ] **G1-04** — `mecha`

### Grupo 2 — Terminología petrolera venezolana y regional

- [ ] **G2-01** — `cabria`
- [ ] **G2-02** — `cabrias`
- [ ] **G2-03** — `mechurrio`
- [ ] **G2-04** — `macolla`
- [ ] **G2-05** — `guaya fina`
- [ ] **G2-06** — `guaya eléctrica`
- [ ] **G2-07** — `balancín`
- [ ] **G2-08** — `caballito`
- [ ] **G2-09** — `camión chupón`
- [ ] **G2-10** — `gandola`

### Grupo 3 — Intención + capacidad

- [ ] **G3-01** — `mantenimiento de cabrias`
- [ ] **G3-02** — `fabricantes de cabrias`
- [ ] **G3-03** — `transporte de gandolas`
- [ ] **G3-04** — `alquiler de gandolas`
- [ ] **G3-05** — `mantenimiento de levantamiento artificial`

### Grupo 4 — Términos amplios o genéricos

- [ ] **G4-01** — `operadores`
- [ ] **G4-02** — `dame todas las empresas`
- [ ] **G4-03** — `servicios petroleros`
- [ ] **G4-04** — `construcción`

### Grupo 5 — Consultas técnicas compuestas

- [ ] **G5-01** — `tratamiento de aguas de perforación en el lago`
- [ ] **G5-02** — `empresas que fabriquen y mantengan balancines`
- [ ] **G5-03** — `inspección y mantenimiento de guaya eléctrica`
- [ ] **G5-04** — `transporte de crudo por gandola`

### Grupo 6 — Variantes en inglés y bilingües

- [ ] **G6-01** — `pipeline`
- [ ] **G6-02** — `refinery`
- [ ] **G6-03** — `refinería` ← comparar con G6-02; deberían ser equivalentes
- [ ] **G6-04** — `drilling` ← comparar con `perforación`

### Grupo 7 — Consultas difíciles o fuera de alcance

- [ ] **G7-01** — `como me afilio a la camara?`
- [ ] **G7-02** — `necesito arbolitos para pozos petroleros`
- [ ] **G7-03** — `venta de zapatos` ← lo correcto es **no** devolver empresas petroleras
- [ ] **G7-04** — `xyzqw`

### Grupo 8 — Consultas propuestas por el cliente

- [ ] **G8-01** — `________________`
- [ ] **G8-02** — `________________`
- [ ] **G8-03** — `________________`
- [ ] **G8-04** — `________________`
- [ ] **G8-05** — `________________`
- [ ] **G8-06** — `________________`
- [ ] **G8-07** — `________________`
- [ ] **G8-08** — `________________`
- [ ] **G8-09** — `________________`
- [ ] **G8-10** — `________________`

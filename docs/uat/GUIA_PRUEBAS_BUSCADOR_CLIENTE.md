# Guía de pruebas del buscador — para el revisor de la Cámara Petrolera

**Para quién es esta guía:** una persona de la Cámara que conozca el sector petrolero y las empresas
afiliadas. **No hace falta ningún conocimiento técnico ni de programación.**

**Qué le pedimos:** que use el asistente de búsqueda como lo usaría un afiliado real, y que nos diga si
los resultados le parecen correctos. Su criterio de negocio es exactamente lo que no podemos sustituir
con pruebas automáticas.

**Cuánto tiempo toma:** entre 60 y 90 minutos para las consultas sugeridas. Puede hacerlo en varias
sesiones; no hay que terminarlo de una sola vez.

---

## 1. Objetivo de la prueba

Queremos saber **si el asistente encuentra las empresas correctas** cuando alguien busca un producto,
un servicio o una capacidad del sector petrolero, usando las palabras que se usan de verdad en
Venezuela.

Concretamente, para cada consulta nos interesan tres cosas:

1. ¿Aparecen las empresas que usted esperaría ver?
2. ¿Falta alguna empresa relevante que usted sepa que debería aparecer?
3. ¿Aparece alguna empresa que **no** debería aparecer ahí?

No está evaluando si el sistema "funciona" en el sentido informático. Está evaluando si **acierta**.

> **Importante:** esta guía a propósito **no** le dice qué empresas tendría que encontrar en cada
> consulta. No existe todavía una lista de expectativas aprobada por la Cámara, y si se la
> sugiriéramos nosotros estaríamos contaminando la prueba. La expectativa la pone usted, desde su
> conocimiento del sector.

---

## 2. Dónde se prueba

**Dirección:** <https://pruebas.camarapetrolera.app/cira-test/>

Es un **entorno de pruebas**, separado del sistema definitivo. Puede escribir con confianza: nada de
lo que haga acá afecta datos reales de las empresas.

Se abre en cualquier navegador (computadora o teléfono). No necesita usuario ni contraseña.

### Qué va a ver

La pantalla se llama **"CIRA — Asistente CPV"** y funciona como un **chat**, no como un buscador con
lista de resultados:

| Elemento | Para qué sirve |
|---|---|
| Caja de texto abajo, *"Escribe una consulta de prueba…"* | Acá escribe lo que quiere buscar |
| Botón ➤ a la derecha de la caja | Envía la consulta |
| Botones de atajo arriba (*operadores*, *dame todas las empresas*, …) | Consultas de ejemplo, con un clic |
| **"Nueva conversación"** (arriba a la derecha) | Borra la conversación y empieza de cero |
| *sesión: …* | Identificador de su sesión. No hay que hacer nada con esto |

### Una sola regla técnica, y es importante

**Haga clic en "Nueva conversación" antes de cada consulta nueva.**

Es un chat y **recuerda lo que se habló antes**. Si pregunta por *cabrias* y después escribe
*mantenimiento*, el asistente va a suponer que sigue hablando de cabrias. Eso está bien en un uso
real, pero arruina la prueba: ya no sabríamos si acertó por la consulta nueva o por lo anterior.

Una consulta → una conversación nueva.

---

## 3. Cómo hacer cada prueba, paso a paso

1. Haga clic en **"Nueva conversación"**.
2. Escriba **una** consulta de la lista de la sección 5 (o una propia) y envíe con ➤.
3. Espere la respuesta. Puede tardar unos segundos.
4. **Mire las primeras 5 empresas que el asistente mencione**, en el orden en que las menciona. Si
   menciona menos de 5, trabaje con las que haya.
5. Anote en la planilla (`FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv` o la versión `.md`):
   - **Resultado_general** y **Puntuacion_1_a_5** (la escala está en la sección 4).
   - **Empresa_esperada**: una empresa que usted esperaba ver. Si no tenía ninguna en mente, déjelo
     vacío — no hay que inventar.
   - **Empresa_esperada_aparece**: `SI` / `NO` / `N/A`.
   - **Posicion_empresa_esperada**: en qué lugar apareció (1 a 5), o `>5` si apareció más abajo, o
     vacío si no apareció.
   - **Resultados_relevantes_top5**: cuáles de esas 5 sí tienen sentido.
   - **Resultados_no_relevantes_top5**: cuáles **no** tienen sentido, y por qué.
   - **Empresas_relevantes_ausentes**: las que faltan y usted sabe que deberían estar.
   - **Comentario_cliente**: una o dos frases explicando el problema, si hubo alguno.
6. Repita desde el paso 1 con la consulta siguiente.

### Las explicaciones son la parte más valiosa

Un "2" sin explicación nos dice que algo está mal pero no qué. Un "2" con *"aparece una empresa de
transporte urbano y acá se busca transporte de crudo"* nos dice exactamente qué corregir.

No hacen falta frases largas. Una línea concreta sirve más que un párrafo general.

### Si algo no funciona

Si al enviar una consulta aparece un error, un mensaje vacío, o la pantalla se queda esperando mucho
tiempo: **no es su culpa y no es parte de lo que evaluamos acá.** Descríbalo en
**`Comentario_cliente`** —qué hizo y qué pasó— y avise a SISTEG. Es un problema de plataforma,
distinto de un problema de relevancia, y lo clasifica el equipo técnico después.

**No complete ninguna columna posterior a `Comentario_cliente`**, tampoco en este caso. Describir el
síntoma es todo lo que necesitamos de su parte.

---

## 4. Cómo puntuar

| Puntuación | Significado | Cuándo usarla |
|---|---|---|
| **5** | Excelente | Las primeras empresas son justo las que esperaba. No sobra ni falta nada importante |
| **4** | Bueno | Mayormente correcto. Algún detalle de orden, o falta algo menor |
| **3** | Aceptable | Sirve, pero hay ruido evidente o faltan empresas que deberían estar |
| **2** | Deficiente | La mayoría de lo que aparece no corresponde, o falta lo más importante |
| **1** | Incorrecto | El resultado no tiene relación con lo que se buscó |
| **N/A** | No puede juzgar | No conoce el tema lo suficiente, o la consulta no aplica |

**`N/A` es una respuesta legítima y útil.** Es mucho mejor que una nota inventada: nos dice que esa
consulta necesita a otra persona, no que el sistema falló.

Y en **Resultado_general** ponga una de estas tres:

- `PASS` — correcto, sin observaciones (típicamente 4 o 5).
- `PARCIAL` — sirve pero con problemas (típicamente 3).
- `FALLA` — no sirve (típicamente 1 o 2).
- `N/A` — no evaluable.

---

## 5. Consultas sugeridas

Son **35 consultas propuestas** por nosotros, repartidas en 7 grupos. **Las 10 últimas (Grupo 8) están
en blanco a propósito**, para las consultas que usted considere importantes y que no se nos
ocurrieron: ésas suelen ser las que más valor aportan. En total, 45 filas en la planilla.

### Grupo 1 — Productos y servicios directos

| Test_ID | Consulta |
|---|---|
| G1-01 | tratamiento de aguas de perforación |
| G1-02 | levantamiento artificial |
| G1-03 | revestidor |
| G1-04 | mecha |

### Grupo 2 — Terminología petrolera venezolana y regional

Acá es donde el criterio local importa más: son las palabras que se usan en el campo, no en los
manuales.

| Test_ID | Consulta |
|---|---|
| G2-01 | cabria |
| G2-02 | cabrias |
| G2-03 | mechurrio |
| G2-04 | macolla |
| G2-05 | guaya fina |
| G2-06 | guaya eléctrica |
| G2-07 | balancín |
| G2-08 | caballito |
| G2-09 | camión chupón |
| G2-10 | gandola |

### Grupo 3 — Intención + capacidad

La misma pieza, distinta actividad. Lo que probamos es si el asistente distingue **fabricar** de
**mantener** de **alquilar** de **transportar**.

| Test_ID | Consulta |
|---|---|
| G3-01 | mantenimiento de cabrias |
| G3-02 | fabricantes de cabrias |
| G3-03 | transporte de gandolas |
| G3-04 | alquiler de gandolas |
| G3-05 | mantenimiento de levantamiento artificial |

### Grupo 4 — Términos amplios o genéricos

Acá **no** esperamos precisión quirúrgica. Lo que queremos saber es si el asistente responde algo
razonable o si se dispara con cualquier cosa.

| Test_ID | Consulta |
|---|---|
| G4-01 | operadores |
| G4-02 | dame todas las empresas |
| G4-03 | servicios petroleros |
| G4-04 | construcción |

### Grupo 5 — Consultas técnicas compuestas

Varias ideas en una sola frase. Nos interesa si entiende la frase completa o si se queda con una
palabra suelta tomada al azar.

| Test_ID | Consulta |
|---|---|
| G5-01 | tratamiento de aguas de perforación en el lago |
| G5-02 | empresas que fabriquen y mantengan balancines |
| G5-03 | inspección y mantenimiento de guaya eléctrica |
| G5-04 | transporte de crudo por gandola |

### Grupo 6 — Variantes en inglés y bilingües

Muchos afiliados buscan en inglés. El mismo concepto debería dar resultados equivalentes en los dos
idiomas.

| Test_ID | Consulta | Qué observar |
|---|---|---|
| G6-01 | pipeline | |
| G6-02 | refinery | Compare con G6-03 |
| G6-03 | refinería | **Debería dar algo equivalente a G6-02.** Si una funciona y la otra no, anótelo |
| G6-04 | drilling | Compare con "perforación" |

### Grupo 7 — Consultas difíciles o fuera de alcance

Acá lo correcto puede ser que el asistente **diga que no sabe** o que no corresponde. Una respuesta
honesta vale 5; una respuesta inventada vale 1.

| Test_ID | Consulta | Qué observar |
|---|---|---|
| G7-01 | como me afilio a la camara? | No es una búsqueda de empresas. ¿Responde con sensatez? |
| G7-02 | necesito arbolitos para pozos petroleros | ¿Entiende "arbolito" como cabezal de pozo, o se va a navidad? |
| G7-03 | venta de zapatos | Fuera del sector. **Lo correcto es no devolver empresas petroleras** |
| G7-04 | xyzqw | Sin sentido. ¿Falla con elegancia? |

### Grupo 8 — Sus propias consultas (10 filas en blanco)

| Test_ID | Consulta |
|---|---|
| G8-01 | |
| G8-02 | |
| G8-03 | |
| G8-04 | |
| G8-05 | |
| G8-06 | |
| G8-07 | |
| G8-08 | |
| G8-09 | |
| G8-10 | |

En la planilla, estas filas llevan `Consulta_propuesta_por = CLIENTE`.

---

## 6. Qué hacemos con sus respuestas

Las columnas de la planilla **terminan, para usted, en `Comentario_cliente`.** Todo lo que viene
después (`Tipo_de_problema`, `Severidad`, `Revision_tecnica`, etc.) lo completa el equipo técnico
cuando clasifica cada hallazgo. **No hace falta que llene nada de eso**, y si lo deja vacío no está
dejando la planilla incompleta.

Cada hallazgo se clasifica después para saber si se corrige en los datos de la empresa, en la
taxonomía de términos, en el mapeo de categorías CPV o en el motor de búsqueda — que son arreglos
distintos, con responsables distintos.

---

## 7. Resumen de una página

1. Entre a <https://pruebas.camarapetrolera.app/cira-test/>.
2. **"Nueva conversación"** antes de cada consulta.
3. Escriba la consulta, envíe con ➤.
4. Mire las **primeras 5 empresas** mencionadas.
5. Puntúe de 1 a 5 (o `N/A`).
6. Diga **qué falta**, **qué sobra** y **por qué**, en una línea.
7. Pase a la siguiente.

Gracias. Lo que no se puede automatizar es exactamente esto.

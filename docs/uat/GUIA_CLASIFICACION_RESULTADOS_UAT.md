# Guía de clasificación de hallazgos del UAT — para el revisor técnico

**Para quién es:** quien hace el triage técnico de la planilla que devuelve el cliente (SISTEG / equipo
de taxonomía). **No es un documento para el cliente.**

**Qué resuelve:** el cliente reporta un **síntoma** ("falta una empresa", "sale algo raro"). Este
documento sirve para convertir ese síntoma en una **causa**, porque cada causa se arregla en un lugar
distinto, con un responsable distinto y con un riesgo distinto.

**Regla de oro:** clasificar es *diagnosticar*, no *arreglar*. En el triage no se cambia nada: ni
datos, ni taxonomía, ni pesos, ni código. Las correcciones se abren como tareas gobernadas aparte.

---

## 1. Por qué importa distinguir

Un mismo síntoma —"la empresa X no aparece cuando busco *cabrias*"— puede tener cinco causas que no
se parecen en nada:

| Causa real | Dónde se arregla | Riesgo de arreglarlo |
|---|---|---|
| La empresa nunca declaró que hace cabrias | Perfil de la empresa | Bajo. No afecta a nadie más |
| El término *cabria* no está en la taxonomía | Taxonomía de términos | Medio. Afecta todas las consultas con ese término |
| *cabria* apunta a una categoría CPV equivocada | Mapeo TERM→CPV | **Alto.** Afecta a todas las empresas de esa categoría |
| Está, pero sale en el puesto 14 | Pesos y ranking | **Alto.** Afecta el orden de todo el buscador |
| El motor falló (error, timeout) | Código | Variable |

Si se clasifica mal, se arregla en el lugar equivocado: se toca el ranking global para resolver lo que
era un dato faltante de una sola empresa. Ése es el error que esta guía existe para evitar.

---

## 2. Árbol de decisión

Recorrerlo **en orden**. La primera pregunta que da "sí" determina la clasificación.

```
1. ¿El sistema devolvió un error, se quedó colgado o no respondió?
   SI → MOTOR (o UI_USABILIDAD si es visual).  No siga; esto es un defecto de plataforma.
   NO → siga.

2. ¿La consulta admite más de una lectura razonable, incluso para un experto?
   SI → CONSULTA_AMBIGUA.  Puede no ser un defecto.
   NO → siga.

3. ¿La empresa ausente DECLARA en su perfil la capacidad buscada?
   NO → DATOS_EMPRESA.  No hay nada que el buscador pueda adivinar.
   SI → siga.

4. ¿El término de la consulta existe en la taxonomía y está asociado al concepto correcto?
   NO → TERMINO_TAXONOMIA.
   SI → siga.

5. ¿El término apunta a la categoría CPV correcta, y la empresa está en esa categoría?
   NO → MAPEO_CPV.
   SI → siga.

6. ¿La empresa aparece, pero más abajo de lo razonable?
   SI → RANKING.
   NO → siga.

7. ¿Aparece una empresa que NO corresponde?
   SI → FALSO_POSITIVO (y vuelva a 4 y 5 para encontrar por qué entró).
   NO → siga.

8. ¿Falta una empresa que SÍ corresponde, y pasó 3/4/5?
   SI → FALSO_NEGATIVO.
   NO → SIN_PROBLEMA.
```

---

## 3. Cada clasificación en detalle

### `FALSO_POSITIVO` — aparece algo que no corresponde

**Síntoma típico:** "la segunda es una empresa de transporte de personal y yo busqué transporte de
crudo".

**Cómo confirmarlo:** mirar por qué entró. Normalmente es una de dos cosas:
- un término demasiado genérico arrastra una categoría muy poblada (*transporte*, *servicios*);
- una relación TERM→CPV aprobada con peso alto que no debería estar aprobada.

**Cuidado:** un falso positivo **no** se arregla bajando el peso global del término, porque eso rompe
las consultas donde ese término sí servía. Se arregla en la relación concreta que lo introdujo.

**Si la causa es una relación TERM→CPV mal aprobada**, la clasificación técnica es `CPV`, no `MOTOR`.

---

### `FALSO_NEGATIVO` — falta algo que sí corresponde

**Síntoma típico:** "sé que la empresa X hace esto y no aparece".

**El orden de verificación importa**, porque la causa más frecuente es la menos interesante:

1. ¿La empresa **declara** la capacidad en su perfil? Si no → `DATOS_EMPRESA`. Es, con diferencia,
   la causa más común, y no es un defecto del buscador.
2. ¿Está en la categoría CPV correcta? Si no → `MAPEO_CPV`.
3. ¿El término de búsqueda está reconocido? Si no → `TERMINO_TAXONOMIA`.
4. Si pasó las tres y sigue sin aparecer → `FALSO_NEGATIVO` propiamente dicho, y hay que mirar el
   motor.

**No se marca `FALSO_NEGATIVO` sin haber descartado los tres primeros.** Hacerlo manda a investigar el
motor por lo que era un perfil incompleto.

---

### `RANKING` — están las correctas, pero en mal orden

**Síntoma típico:** "sí aparece, pero en el puesto 12, y hay tres irrelevantes antes".

**Cómo distinguirlo de `FALSO_NEGATIVO`:** en ranking la empresa **sí está en los resultados**. Si no
está en ninguna posición, no es ranking.

Para distinguirlos hace falta ver más allá de los 5 primeros. Si el cliente sólo reportó el top-5, el
triage tiene que verificar la posición real antes de clasificar.

**Es la clasificación de mayor riesgo al corregir**, porque los pesos son globales: subir un término
para arreglar una consulta puede degradar otras diez. Toda corrección de ranking exige volver a correr
la regresión congelada de 32 consultas (`perfilafiliados-mcp/scripts/regression-suite.mjs`) antes de
aceptarse.

---

### `DATOS_EMPRESA` — la empresa no declara lo que hace

**Síntoma típico:** el cliente sabe de la empresa algo que el sistema no tiene por qué saber.

**Cómo confirmarlo:** abrir el perfil de la empresa y buscar el servicio/producto. Si no está escrito
en ninguna parte, el buscador no lo puede inferir legítimamente.

**No es un defecto del buscador.** Es una brecha de cobertura de datos, y la acción recomendada
normalmente es pedirle a la empresa que complete su perfil, no tocar el motor.

Marcarlo como defecto del motor es el error de clasificación más costoso que se puede cometer acá:
lleva a inflar la búsqueda semántica para "adivinar" capacidades, que es exactamente lo que produce
falsos positivos después.

---

### `TERMINO_TAXONOMIA` — problema de término

**Síntoma típico:** una palabra del campo no da nada, o da algo sin relación.

Tres sub-casos distintos:
- **El término no existe** en la taxonomía → hay que proponerlo.
- **Existe pero está aislado**, sin concepto canónico que lo una a su equivalente (p. ej. el término en
  inglés y el término en español sin vincular) → hay que vincularlo.
- **Existe y está mal vinculado** → el concepto al que pertenece no es el correcto.

**Señal diagnóstica útil:** si la consulta en español funciona y la misma en inglés no (o al revés),
casi siempre es un problema de concepto canónico, no de CPV.

---

### `MAPEO_CPV` — problema de categoría

**Síntoma típico:** el término se reconoce, pero trae empresas de un rubro vecino y equivocado.

**Cómo distinguirlo de `TERMINO_TAXONOMIA`:** el término **sí** se reconoce. Lo que está mal es a qué
categoría CPV apunta, o con qué fuerza.

**Es la clasificación de mayor alcance**, porque una relación TERM→CPV aprobada afecta a **todas** las
empresas de esa categoría a la vez. Antes de recomendar un cambio hay que mirar cuántas empresas están
vinculadas a esa categoría: la pantalla de edición de la relación lo muestra en *"Preview de impacto"*.

---

### `EVIDENCIA_WEB_FALTANTE` — no hay respaldo externo

**Síntoma típico:** la empresa probablemente hace lo que el cliente dice, pero no hay nada que lo
respalde: ni el perfil, ni el sitio web, ni evidencia recolectada.

**Cómo distinguirlo de `DATOS_EMPRESA`:** en `DATOS_EMPRESA` falta la declaración **del afiliado**. En
`EVIDENCIA_WEB_FALTANTE` falta la **confirmación externa** que permitiría aprobar una relación con
confianza.

Importa porque marca el límite de lo que se puede aprobar de forma responsable: sin evidencia, lo
correcto es dejarlo en revisión, no aprobarlo porque "suena bien".

---

### `CONSULTA_AMBIGUA` — la consulta admite varias lecturas

**Síntoma típico:** *mecha* es broca de perforación y también es cabello; *caballito* es una pieza y
también un animal; *operadores* puede ser empresas operadoras o personal operario.

**Esto no siempre es un defecto.** Si la consulta es genuinamente ambigua, devolver las dos lecturas
puede ser el comportamiento correcto.

Se clasifica así, en vez de `FALSO_POSITIVO`, cuando **un experto del sector tampoco sabría** cuál es
la respuesta única correcta. Y en ese caso la acción no es "corregir el motor", es decidir una política
de desambiguación, que es una decisión de negocio.

---

### `UI_USABILIDAD` — problema de interfaz

Se ve mal, no se entiende, no se puede copiar, el texto se corta, el chat arrastra contexto de la
consulta anterior. **No es relevancia.** Se separa a propósito para que no contamine las métricas de
calidad de búsqueda.

---

### `MOTOR` (fallo real del motor) — en `Clasificacion_tecnica`

Reservado para fallos **demostrables**: un error HTTP, un timeout, una excepción, una respuesta vacía
donde la base sí tiene datos, un resultado que contradice lo que la consulta directa a la base
devuelve.

**No se usa como cajón de sastre.** Si la causa no se identificó todavía, lo correcto es
`Estado_seguimiento = EN_ANALISIS` con `Clasificacion_tecnica` vacía, no declarar un fallo de motor por
descarte.

> **Nota operativa sobre esta plataforma:** la pantalla `/cira-test/` conversa a través de un webhook de
> n8n. Si ese flujo está inactivo, **todas** las consultas fallan igual y de la misma manera. Antes de
> clasificar cualquier cosa como `MOTOR`, confirme que el flujo de n8n está activo. Un UAT entero puede
> verse como un fallo catastrófico del buscador cuando lo que pasaba era eso.

---

## 4. Severidad

La severidad es del **impacto en el negocio**, no de la dificultad de arreglarlo.

| Severidad | Criterio |
|---|---|
| `CRITICA` | Una consulta frecuente y central no funciona, o devuelve algo que haría quedar mal a la Cámara frente a un afiliado |
| `ALTA` | Falta sistemáticamente una empresa o rubro relevante, o un falso positivo se repite en varias consultas |
| `MEDIA` | Problema real pero acotado a un término o a un caso |
| `BAJA` | Detalle de orden o de presentación, sin consecuencia práctica |
| `NINGUNA` | `SIN_PROBLEMA`, o `CONSULTA_AMBIGUA` resuelta como comportamiento correcto |

**Un hallazgo que se repite en varias consultas sube de severidad**, aunque individualmente parezca
menor: indica una causa estructural, no un caso aislado.

---

## 5. De la clasificación a la acción

| `Clasificacion_tecnica` | Acción típica | Gobernanza que exige |
|---|---|---|
| `DATOS` | Pedir a la empresa que complete el perfil | Ninguna técnica |
| `TAXONOMIA` | Proponer término o vínculo de concepto | Flujo de propuestas revisadas (congelar → confirmar → aplicar) |
| `CPV` | Revisar la relación TERM→CPV concreta | Revisión humana por ítem; **nunca** aprobación masiva |
| `MOTOR` | Issue técnico con reproducción | Regresión congelada de 32 consultas antes de aceptar |
| `EVIDENCIA_WEB` | Recolectar evidencia, o dejar en revisión | Ninguna técnica |
| `UI` | Ajuste de interfaz | Smoke de staging |
| `NO_ES_DEFECTO` | Documentar y cerrar | Ninguna |

**Ninguna acción de esta tabla se ejecuta durante el triage.** El triage llena la planilla; las
correcciones se abren después, con su propia autorización.

---

## 6. Antes de cerrar el triage

- [ ] Toda fila con `Puntuacion_1_a_5 <= 2` tiene `Tipo_de_problema` distinto de `SIN_PROBLEMA`.
- [ ] Todo `FALSO_NEGATIVO` descartó explícitamente `DATOS_EMPRESA`, `MAPEO_CPV` y `TERMINO_TAXONOMIA`.
- [ ] Todo `RANKING` verificó la posición real más allá del top-5.
- [ ] Todo `OTRO` tiene explicación en `Revision_tecnica`.
- [ ] Todo `MOTOR` tiene reproducción, y se confirmó que el flujo de n8n estaba activo.
- [ ] Ningún hallazgo quedó sin `Estado_seguimiento`.
- [ ] Los hallazgos que se repiten se agruparon, en vez de contarse como N casos sueltos.
- [ ] No se cambió ningún dato, término, peso ni código durante el triage.

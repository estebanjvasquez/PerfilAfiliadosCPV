# Guía de revisión taxonómica — para el pasante de ingeniería

**Para quién es:** un pasante de ingeniería de petróleo (o afín) de la Cámara Petrolera, con
conocimiento del sector pero sin experiencia previa en este sistema. **No hace falta saber programar.**

**Para quién NO es:** no es un documento técnico de SISTEG. Está escrito para que el cliente lo pueda
entregar tal cual.

---

> # ⚠️ ESTA ETAPA ES DE REVISIÓN REGISTRADA, NO DE PUBLICACIÓN
>
> Durante esta etapa —la **calibración**— usted **anota sus decisiones en una planilla y nada más**.
>
> **No hace clic en ningún botón que cambie el estado de una relación.** Ni *Aprobar*, ni *Rechazar*,
> ni *Editar*, ni *Eliminar*, ni ninguna acción masiva. **Ninguno.**
>
> La pantalla se usa **sólo para leer**: para ver el término, la categoría, la jerarquía y la
> evidencia. Sus cinco decisiones —`APPROVE`, `REJECT`, `NEEDS_CONTEXT`, `ESCALATE`,
> `POSSIBLE_NEW_CATEGORY`— se registran **únicamente** en
> `FORMATO_REVISION_TAXONOMICA_PASANTE.csv`.
>
> **Por qué.** El botón *Aprobar* no guarda una opinión: **publica la relación al buscador en vivo**,
> en el acto. Mientras no haya una autorización formal y separada de la Cámara, su revisión tiene que
> poder equivocarse sin consecuencias — y eso sólo es cierto si no se toca nada. Primero su
> supervisor compara las primeras 30–50 decisiones con las suyas; recién después, y como **tarea
> aparte explícitamente autorizada**, se evaluará habilitar decisiones operativas.
>
> Anotar `APPROVE` en la planilla **no aprueba nada**. Es exactamente lo que queremos de usted en
> esta etapa: su criterio, por escrito, sin riesgo.

---

## 1. Para qué sirve esta tarea

El sistema de búsqueda de la Cámara conecta **términos** del sector petrolero (*cabria*, *mechurrio*,
*guaya fina*) con **categorías CPV**, que es la clasificación con la que se organizan los productos y
servicios de las empresas afiliadas. Cuando esa conexión es correcta, un afiliado que busca "cabrias"
encuentra empresas de cabrias. Cuando es incorrecta, encuentra ruido — o no encuentra nada.

Un proceso automático ya **propuso** miles de esas conexiones comparando textos y significados. Las
propuestas son un punto de partida razonable, pero una máquina no sabe si *mecha* es una broca de
perforación o una vela. **Eso lo sabe usted.**

### Cuánto hay por revisar

Estado real de las relaciones Término→CPV en el sistema:

| Estado | Cantidad | Qué significa |
|---|---|---|
| **En revisión** (`needs_review`) | **9.282** | Propuestas automáticas esperando validación humana. **Éste es su trabajo** |
| Aprobada (`approved`) | 212 | Ya validadas. Son las que el buscador usa de verdad |
| Candidata (no urgente) (`candidate`) | 17 | Propuestas de baja prioridad |
| Descartada (`deprecated`) | 238 | Ya descartadas |
| **Total** | **9.749** | |

Fíjese en la proporción: de 9.749 relaciones, **sólo 212 están aprobadas**. El buscador funciona hoy
con poco más del 2% de la taxonomía validada. Cada relación que usted valide bien **amplía
directamente** lo que el buscador puede encontrar.

### Lo que esta tarea NO es

- **No** se le pide rediseñar la taxonomía ni proponer una clasificación nueva.
- **No** se le pide tocar el código del buscador, ni los pesos, ni la base de datos.
- **No** se le pide decidir casos difíciles solo. Para eso existe el escalamiento (sección 10).
- **No** se le pide aprobar ni rechazar nada **en el sistema**. En esta etapa no se modifica ninguna
  relación: usted registra su criterio en la planilla y el sistema queda igual que antes.

Se le pide una cosa concreta y acotada: **mirar una relación propuesta a la vez, decir si es correcta,
y anotarlo.**

---

## 2. Lo que puede y lo que no puede hacer

### Puede

- **Leer** la pantalla de relaciones: navegar, filtrar, ordenar y abrir filas para verlas.
- Revisar **una** relación término↔CPV a la vez.
- Mirar el término, el código y la categoría CPV, la jerarquía, el tipo de relación, el peso, la
  confianza y la evidencia disponible.
- Clasificar la relación con una de las cinco decisiones de la sección 3, **anotándola en la
  planilla**.
- Escribir un comentario técnico breve.
- Marcar términos ambiguos o demasiado generales para que los revise alguien más.
- Señalar categorías que faltan o huecos evidentes de la taxonomía.

### No puede

- **No** usar el botón **"Aprobar"**. Ni una vez, ni como prueba.
- **No** usar el botón **"Rechazar"**.
- **No** usar **"Editar"** ni **"Eliminar"**.
- **No** usar **ninguna acción masiva** (*"Aprobar seleccionadas"*, *"Rechazar seleccionadas"*).
  Existen en la pantalla y usted puede llegar a verlas: **no se usan.** Ver el aviso de la sección 5.
- **No** inventar códigos CPV nuevos.
- **No** aprobar porque las palabras *se parecen*. Esto es la regla más importante de todas; la
  sección 4 la desarrolla.
- **No** inferir capacidades que la evidencia no respalda.
- **No** modificar código, ranking, pesos de búsqueda ni la base de datos.
- **No** resolver solo los casos ambiguos o de alto impacto.
- **No** cambiar definiciones de conceptos canónicos fuera de este flujo.

Dicho de la forma más corta posible: **en esta pantalla usted lee; escribir es en la planilla.**

> **Sobre los permisos.** Según los permisos que le asigne la Cámara, puede que algunos de esos
> botones ni siquiera le aparezcan. Eso es correcto y deliberado: *Aprobar* publica la relación al
> buscador en vivo y requiere un permiso específico, distinto del de *Rechazar*. **Si un botón no
> está, no es un error**, no hay nada que reportar y no hay que pedir que se lo habiliten por cuenta
> propia. Y si los botones **sí** aparecen —porque su cuenta ya tenía esos permisos por otro motivo—
> **igual no se usan**: la restricción es de esta tarea, no de la configuración de la pantalla.

---

## 3. Las cinco decisiones, y cómo se corresponden con la pantalla real

Éste es el vocabulario que usará para registrar sus decisiones:

| Decisión | Cuándo usarla |
|---|---|
| **`APPROVE`** | El término claramente pertenece a / representa esa categoría CPV |
| **`REJECT`** | La relación es incorrecta o engañosa |
| **`NEEDS_CONTEXT`** | El término es demasiado amplio o ambiguo; necesita contexto antes de mapearse |
| **`ESCALATE`** | Hace falta que lo valide un experto en CPV |
| **`POSSIBLE_NEW_CATEGORY`** | El concepto es legítimo pero no se ve ninguna categoría existente adecuada |

### ⚠️ En esta etapa, las CINCO decisiones se registran igual: en la planilla

| Su decisión | Qué hacer **en la pantalla**, durante la calibración | Qué pasa con la fila |
|---|---|---|
| `APPROVE` | **Nada.** Anótelo en la planilla | Queda en **"En revisión"** |
| `REJECT` | **Nada.** Anótelo en la planilla | Queda en **"En revisión"** |
| `NEEDS_CONTEXT` | **Nada.** Anótelo en la planilla | Queda en **"En revisión"** |
| `ESCALATE` | **Nada.** Anótelo en la planilla | Queda en **"En revisión"** |
| `POSSIBLE_NEW_CATEGORY` | **Nada.** Anótelo en la planilla | Queda en **"En revisión"** |

**Las cinco se tratan igual, y eso simplifica su trabajo:** no tiene que recordar cuál lleva botón y
cuál no. Ninguna lo lleva. Todas van a `FORMATO_REVISION_TAXONOMICA_PASANTE.csv` y la fila de la
pantalla queda intacta, en **"En revisión"**, que es la verdad: *todavía no decidido por la Cámara*.

### Correspondencia con la pantalla real, para cuando se habilite

La Cámara nos pidió documentar cómo se corresponde su vocabulario con la pantalla real, para el día en
que una **tarea aparte, formalmente autorizada**, habilite decisiones operativas. Esa correspondencia
es:

| Su decisión | A qué acción real correspondería, si se autoriza más adelante |
|---|---|
| `APPROVE` | Botón **"Aprobar"** (✓ verde): confirmación + **"Motivo"** obligatorio → la fila pasa a **"Aprobada"** y **se publica al buscador en vivo** |
| `REJECT` | Botón **"Rechazar"** (✗ rojo): confirmación + **"Motivo"** obligatorio → la fila pasa a **"Rechazada"** |
| `NEEDS_CONTEXT` | No existe acción equivalente. La fila se deja en **"En revisión"** |
| `ESCALATE` | No existe acción equivalente. La fila se deja en **"En revisión"** |
| `POSSIBLE_NEW_CATEGORY` | No existe acción equivalente. La fila se deja en **"En revisión"** |

**Esta tabla es informativa. Durante TASK-0008 / la calibración, NO use esos botones.**

Y note, además, que no existe ningún botón "Necesita contexto", "Escalar" ni "Categoría nueva": tres
de las cinco decisiones no tienen equivalente en la pantalla ni lo tendrán. Si alguna vez busca uno,
no lo va a encontrar, y **no hay que improvisar con otro botón** para simular el efecto.

> **En particular, no use *"Editar"*.** La pantalla permite cambiar el Estado a mano desde ahí (por
> ejemplo a *"Candidata (no urgente)"*), y podría parecer una forma inofensiva de registrar un
> `NEEDS_CONTEXT`. No lo es: se salta la confirmación y el motivo obligatorio, modifica una relación
> gobernada y deja la auditoría incompleta. Para usted, *Editar* y *Eliminar* no existen.

---

## 4. Estándar de evidencia

**Esta sección es la más importante de la guía.** Una relación aprobada afecta a **todas** las empresas
de esa categoría CPV a la vez. Aprobar de más es peor que no aprobar: un afiliado que recibe resultados
equivocados pierde confianza en el sistema, y rastrear después por qué apareció una empresa que no
correspondía cuesta mucho más que haber dejado la relación en revisión.

### Para `APPROVE` hace falta **al menos una** de estas cuatro

1. **Texto explícito en los datos de la empresa:** el servicio o producto está escrito en el perfil o
   registro del afiliado.
2. **Significado industrial inequívoco:** el término tiene un significado establecido en el sector que
   corresponde sin ambigüedad a esa categoría CPV.
3. **Evidencia del sitio web oficial** de la empresa, cuando esté disponible.
4. **Terminología CPV de referencia** que respalde la correspondencia.

### Nunca se aprueba basándose sólo en

- ❌ **Parecido de palabras.** *"Guaya"* se parece a *"guayaba"*. No tienen nada que ver.
- ❌ **Asociación genérica con petróleo y gas.** Que algo sea "del sector" no lo pone en una categoría
  específica.
- ❌ **Suposición.** Si está adivinando, no es `APPROVE`.
- ❌ **Similitud semántica o vectorial por sí sola.** Que un algoritmo diga que dos textos se parecen
  es un punto de partida, **no** una validación.

### Un caso real que va a encontrar

Las relaciones propuestas automáticamente traen, en el campo **"Coincidió con"**, textos como:

```
Similitud semántica (distancia coseno 0.254) con la categoría CPV-37.06.16S
```

Eso es exactamente el cuarto punto de la lista prohibida. Es la **propuesta** del algoritmo, no la
evidencia. Si lo único que respalda la relación es una línea así, la decisión correcta **no** es
`APPROVE`: es `NEEDS_CONTEXT` o `ESCALATE`, según el caso.

> Dato real para calibrar: los términos *refinery* y *refinería* tienen hoy una relación propuesta a
> `CPV-37.06.16S` con peso 0,7455, originada exactamente en ese tipo de similitud semántica. Sigue
> **sin aprobar**, a propósito. Un peso alto no es evidencia.

### Señales de que la evidencia es débil

En la pantalla, estas señales deberían hacerle desconfiar:

| Señal | Dónde se ve | Qué sugiere |
|---|---|---|
| Tipo de relación **"Contextual"** o **"Léxico"** | Columna *"Tipo"* | Coincidencia débil, no significado |
| **Peso** bajo | Columna *"Peso"* | El propio algoritmo no está seguro |
| *"Sin evidencia registrada"* | Campo *Evidencia* al abrir la fila | No hay nada que respalde |
| *"— sin categoría —"* | Columna *"Categoría"* | El código CPV no resuelve a ninguna categoría real |

Las de tipo **"Exacto"** o **"Sinónimo explícito"** son las más sólidas, pero **tampoco se aprueban sin
mirar**: el tipo lo asignó el mismo proceso automático.

---

## 5. Flujo de trabajo

### Dónde está la pantalla

1. Entre al panel de administración en el entorno que le indique la Cámara.
2. Menú lateral → grupo **"Taxonomía CPV"** → **"Relaciones Término↔CPV"**.

La pantalla ya viene filtrada por **Estado = "En revisión"** y ordenada por **Peso, de mayor a menor**.
Eso es a propósito: las propuestas más fuertes primero. **No cambie el filtro** salvo que su supervisor
se lo pida.

### Qué muestra cada fila

| Columna | Qué es |
|---|---|
| **Término** | La palabra o frase del sector |
| **Código CPV** | El código propuesto |
| **Categoría** | La ruta completa de la categoría (jerarquía), en español |
| **Tipo** | Cómo se propuso: Exacto, Sinónimo explícito, Léxico fuerte, Léxico, Contextual, Ancestro, Manual |
| **Peso** | Fuerza semántica propuesta (0 a 1) |
| **Confianza** | Confianza del algoritmo. **No es un criterio de negocio** |
| **Estado** | En revisión / Aprobada / Rechazada / Candidata / Descartada |

Hay columnas ocultas que conviene activar con el selector de columnas: **"Coincidió con"** y
**"Fuente"**. Son las que le dicen *por qué* se propuso la relación.

### Los 8 pasos, por cada fila

**A.** Lea el **término**. Si no lo reconoce, no siga: eso ya es `ESCALATE`.

**B.** Lea el **código y la categoría CPV**, incluida la jerarquía completa. La ruta importa: una
categoría puede sonar bien y estar colgada de una familia que no corresponde.

**C.** Lea el **contexto y la evidencia**: *"Coincidió con"*, *"Fuente"*, y el campo *Evidencia* si
abre la fila.

**D.** Hágase **esta** pregunta, que es la que decide:

> ### «¿Un afiliado o cliente de la Cámara esperaría razonablemente que este término le devuelva empresas de esta categoría?»

Si la respuesta es "sí, claramente" → `APPROVE`. Si es "no" → `REJECT`. Si es "depende" → no es
`APPROVE`.

**E.** Elija **una** decisión de las cinco.

**F.** Escriba el **motivo en 1 a 3 frases**. Concreto, no genérico.

- ✅ *"Guaya fina es el cable delgado de intervención de pozos; la categoría corresponde a servicios de
  cable de pozo."*
- ❌ *"Es correcto."* / *"Tiene sentido."*

**G.** **Registre en la planilla y guárdela.** Una fila por relación revisada, con su decisión, su
confianza, la evidencia que consultó y su motivo. **No toque la pantalla**: cualquiera de las cinco
decisiones se registra igual, sólo en la planilla, y la fila queda en *"En revisión"*.

**H.** Pase a la siguiente.

### ⚠️ Aviso sobre las acciones masivas

La pantalla **tiene** acciones masivas: si marca varias filas con las casillas, aparece un menú con
**"Aprobar seleccionadas"** y **"Rechazar seleccionadas"**.

**No las use. Nunca, en ninguna circunstancia, en ninguna etapa.**

Se lo advertimos explícitamente porque el botón existe, es fácil de encontrar, y con 9.282 filas
pendientes la tentación es real. Aprobar en masa publica al buscador relaciones que nadie miró, y
deshacerlo después no es tan simple como volver a marcarlas: ya afectaron resultados reales, y cada
cambio queda en la auditoría con su nombre.

Durante la calibración esto ni se plantea, porque **no se usa ninguna** acción que modifique filas. El
aviso queda igual, por dos razones: para que no haya ambigüedad si en el futuro se habilitan
decisiones operativas, y porque es el error más fácil de cometer sin querer al explorar la pantalla.

Una fila, una mirada, una decisión **anotada**.

---

## 6. Fase de calibración

**No empiece por 9.282 filas.** Empiece por un lote controlado.

### Primer lote: 30 a 50 relaciones

Este primer lote es una **revisión semántica registrada**: produce una planilla con su criterio y
**cero cambios en el sistema**. Al terminarlo, la pantalla queda exactamente como estaba.

1. Usted revisa esas 30–50 aplicando esta guía, anotando en la planilla y sin tocar ninguna fila.
2. **Antes de seguir**, CPV/SISTEG revisan las mismas y comparan.
3. Se calcula la **tasa de coincidencia** sobre los casos **no ambiguos**.
4. Se identifican las ambigüedades que se repiten.
5. Se ajustan las instrucciones y los ejemplos **de esta guía** con lo aprendido.
6. Sólo entonces se amplía el volumen.

### Referencia de calidad sugerida

**≥ 90% de coincidencia en los casos no ambiguos** antes de aumentar el ritmo.

> **Esto es un indicador de calidad, no un permiso automático.** Llegar al 90% **no** habilita por sí
> solo a publicar sin supervisión, y no convierte las aprobaciones en automáticas. Es una señal de que
> la calibración va bien y de que tiene sentido ampliar el lote.
>
> Una tasa baja en la calibración **no es un mal resultado del pasante**: lo más probable es que
> signifique que esta guía no era lo bastante clara, y eso es información valiosa. Para eso existe la
> fase de calibración.

---

## 7. Ritmo de trabajo y lotes

Después de la calibración, y **siempre que siga siendo revisión registrada**:

- Trabaje en **lotes de 50 a 100** relaciones.
- **Nunca** anote `APPROVE` en filas que no miró una por una.
- Si la **misma ambigüedad aparece varias veces**, pare y escale. No decida veinte veces una duda que
  no se resolvió una.
- Al cerrar cada lote, llene `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`.

> Ampliar el tamaño del lote **no** habilita decisiones operativas. Son dos cosas independientes:
> cuántas relaciones revisa por lote lo decide su supervisor según la calibración; poder modificar
> relaciones en el sistema requiere una autorización formal y separada de la Cámara (sección 14).

**La calidad vale más que la cantidad.** Un lote de 50 bien revisado aporta más que 300 aprobados a
medias, porque los 300 hay que auditarlos de nuevo.

Si al terminar un lote tiene muchas más `ESCALATE` que `APPROVE`, eso **no** es un fracaso: es la señal
de que esa zona de la taxonomía necesita a un experto, y haberlo detectado es parte del trabajo.

---

## 8. Ejemplos

### 8.1 Términos regionales con significado técnico claro

Casos donde el término venezolano tiene un equivalente técnico inequívoco. Son los **mejores
candidatos a `APPROVE`**, siempre que la categoría CPV realmente corresponda.

| Término | Significado técnico | Por qué es sólido |
|---|---|---|
| **cabria** | *derrick* — torre/estructura de perforación | Significado industrial establecido, sin otra lectura en el sector |
| **mechurrio** | *flare* — quemador de gas | Inequívoco en el contexto petrolero venezolano |
| **guaya fina** | *slickline* — cable fino de intervención de pozos | Término técnico preciso, no genérico |
| **macolla** | *well pad* — plataforma con varios pozos | Concepto físico concreto |

**Aun así, no se aprueban a ciegas.** *cabria* corresponde a *derrick*, pero la relación concreta que
está revisando puede apuntar a una categoría CPV equivocada. Lo que se valida es **la relación**, no el
término.

### 8.2 Capacidades compuestas: no reducir

**`tratamiento de aguas de perforación`** es el ejemplo clave.

Es **una sola capacidad técnica**: tratar el agua que se usa y se produce en la perforación. No es
"agua" + "perforación".

| Mapeo propuesto | Decisión | Por qué |
|---|---|---|
| A una categoría de tratamiento de aguas **industriales de perforación** | `APPROVE` | Corresponde a la capacidad completa |
| A una categoría genérica de **"agua"** o **"suministro de agua"** | `REJECT` | Traería empresas de agua potable, que no hacen esto |
| A una categoría genérica de **"perforación"** | `NEEDS_CONTEXT` | Pierde la parte de tratamiento; una perforadora no necesariamente trata aguas |

**La regla:** si el término describe una capacidad compuesta, **no lo reduzca** a una de sus palabras.
Reducirlo es la forma más común de generar falsos positivos masivos, porque las categorías genéricas
tienen muchísimas empresas.

### 8.3 Términos genéricos o estratégicos: casi nunca `APPROVE`

| Término | Por qué es problemático |
|---|---|
| **petroleum** | Nombra el sector entero, no una capacidad |
| **oil and gas** | Igual: describe la industria, no un producto ni un servicio |
| **upstream** | Segmento de la cadena de valor. Abarca decenas de capacidades distintas |
| **downstream** | Igual, en el otro extremo de la cadena |
| **exploration** | Fase de actividad, no un producto o servicio concreto |

Si se fuerza *upstream* a una categoría específica, pasan dos cosas malas a la vez: las empresas de esa
categoría aparecen en consultas donde no corresponden, y las de las **otras** capacidades upstream
quedan afuera. Se pierde por los dos lados.

**La decisión correcta para estos términos es `NEEDS_CONTEXT`**, no una categoría forzada.

> **Precedente real, no hipotético:** estos mismos términos ya fueron revisados por la Cámara en el
> sistema de propuestas, y la decisión humana fue exactamente **"requiere contexto"** para *petroleum*,
> *crude oil*, *oil and gas*, *exploration*, *upstream*, *midstream* y *downstream*. Si llega a una
> relación con uno de estos términos, está pisando terreno ya transitado, y la respuesta esperada es la
> misma.

### 8.4 Cuándo usar `POSSIBLE_NEW_CATEGORY`

Úselo cuando el término describe algo **legítimo y concreto** del sector y, después de buscar, no
aparece ninguna categoría razonable.

No lo use porque la categoría buena sea difícil de encontrar. Primero busque; `POSSIBLE_NEW_CATEGORY`
es la conclusión de haber buscado, no un atajo.

Señal de apoyo: la pantalla tiene un filtro **"Código sin categoría (huérfano)"**, que lista códigos
que no resuelven a ninguna categoría existente. Si el caso aparece ahí, su sospecha tiene respaldo.

---

## 9. Lista de verificación por ítem

Antes de guardar **cada** decisión:

- [ ] Entiendo el término.
- [ ] Entiendo la categoría CPV propuesta.
- [ ] Revisé el contexto y la evidencia disponibles.
- [ ] El mapeo está técnicamente justificado.
- [ ] **No** aprobé basándome sólo en el parecido de las palabras.
- [ ] Si era ambiguo, escalé en vez de forzar un mapeo.
- [ ] Escribí un motivo concreto y breve.

Si alguna casilla queda sin marcar, la decisión no es `APPROVE`.

---

## 10. Cuándo escalar

Escale —`ESCALATE`, sin tocar la fila— en cualquiera de estos casos:

1. El término puede corresponder a **más de una categoría CPV válida**.
2. La **jerarquía** que muestra la pantalla está incompleta o no se entiende.
3. **No existe** ninguna categoría adecuada (y considere `POSSIBLE_NEW_CATEGORY`).
4. La decisión **ampliaría mucho los resultados** del buscador.
5. El término es **genérico o estratégico**, no una capacidad concreta.
6. **No tiene certeza técnica.** Este motivo solo ya es suficiente.

### Cómo medir el punto 4

Al abrir una relación, la pantalla muestra un campo **"Preview de impacto"** con un texto parecido a:

```
N empresa(s) ya vinculada(s) a esta categoría verían cambiar su relevancia en búsquedas
relacionadas con este término.
```

Ese número es el alcance real de su decisión. **Si es alto y usted tiene cualquier duda, escale.**
Si dice que el código no resuelve a ninguna categoría, tampoco es momento de aprobar.

**Escalar no es no haber podido.** Es el resultado correcto cuando la certeza no alcanza, y es mucho
más valioso que una aprobación dudosa.

---

## 11. Texto sugerido para asignar la tarea al pasante

> *(Para que la Cámara lo envíe tal cual, por correo o mensaje.)*

---

**Asunto: Revisión de relaciones Término–CPV del buscador de la Cámara**

Hola [nombre],

Te asignamos una tarea de validación técnica para el buscador de empresas afiliadas de la Cámara.

**Objetivo.** El sistema generó automáticamente miles de relaciones propuestas entre términos del
sector petrolero (*cabria*, *mechurrio*, *guaya fina*) y categorías CPV, que es la clasificación de
productos y servicios de las empresas. Esas propuestas necesitan validación humana con criterio
técnico del sector. Tu tarea es revisarlas de a una y decir si cada relación es correcta. No se trata
de rediseñar nada ni de programar.

**Primer lote (calibración).** Empezá con **30 a 50 relaciones**, no más. Ese primer lote lo vamos a
revisar nosotros en paralelo para comparar criterios y ajustar las instrucciones antes de ampliar. Es
normal y esperado que en esta etapa aparezcan dudas: anotalas, nos sirven.

**Esta etapa es de revisión registrada, no de publicación.** Es la parte más importante de la
consigna: **no vas a hacer clic en ningún botón que cambie una relación.** Ni *Aprobar*, ni
*Rechazar*, ni *Editar*, ni *Eliminar*, ni acciones masivas. La pantalla se usa **sólo para leer**, y
tus decisiones van a la planilla. El botón *Aprobar* no guarda una opinión: publica la relación al
buscador en vivo, y mientras no haya una autorización formal para eso, tu revisión tiene que poder
equivocarse sin consecuencias. Si los botones te aparecen en pantalla, igual no se usan.

**Cómo registrar las decisiones.** Para cada relación elegís una de cinco opciones —`APPROVE`,
`REJECT`, `NEEDS_CONTEXT`, `ESCALATE`, `POSSIBLE_NEW_CATEGORY`— y escribís un motivo de una a tres
frases. **Las cinco se registran igual: en la planilla
`FORMATO_REVISION_TAXONOMICA_PASANTE.csv`, y la fila de la pantalla queda intacta.** Anotar
`APPROVE` no aprueba nada todavía: es tu criterio por escrito, que es exactamente lo que necesitamos.
La guía explica el detalle.

**Cuándo escalar.** Si el término puede ir a más de una categoría, si es muy genérico (*upstream*,
*oil and gas*), si no encontrás categoría adecuada, o si simplemente no estás seguro: marcá
`ESCALATE` y seguí. **No decidas solo los casos dudosos.** Escalar es la respuesta correcta, no una
falla.

**Dos reglas que no se negocian.** Primera: no se anota `APPROVE` sólo porque las palabras se
parezcan o porque un algoritmo diga que son similares; hace falta evidencia real. Segunda: **no se
toca ningún botón que modifique relaciones**, en particular las acciones masivas ("Aprobar
seleccionadas"), aunque existan y aunque haya muchas filas pendientes.

**La calidad importa más que la velocidad.** Preferimos 40 relaciones bien revisadas que 300 aprobadas
a medias: las segundas hay que auditarlas de nuevo y pueden degradar el buscador mientras tanto. No hay
una cuota diaria.

Leé la guía completa antes de empezar: `GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`. Cualquier duda,
preguntá antes de decidir.

Gracias,
[nombre / Cámara Petrolera de Venezuela]

---

## 12. Dónde se registra cada decisión

| Archivo | Para qué |
|---|---|
| `FORMATO_REVISION_TAXONOMICA_PASANTE.csv` | Una fila por relación revisada |
| `FORMATO_REVISION_TAXONOMICA_PASANTE.md` | Lo mismo, en formato documento |
| `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md` | Un resumen al cerrar cada lote |

Las decisiones permitidas en la columna `Decision` son exactamente cinco: `APPROVE`, `REJECT`,
`NEEDS_CONTEXT`, `ESCALATE`, `POSSIBLE_NEW_CATEGORY`. No se agregan valores nuevos sin acordarlo.

---

## 13. Resumen de una página

0. **La pantalla es de sólo lectura para usted.** Ningún botón que cambie una relación: ni *Aprobar*,
   ni *Rechazar*, ni *Editar*, ni *Eliminar*, ni acciones masivas.
1. Panel → **"Taxonomía CPV"** → **"Relaciones Término↔CPV"**. Ya viene filtrado por *"En revisión"*.
2. Una fila a la vez. Active las columnas *"Coincidió con"* y *"Fuente"*.
3. Pregunta clave: **¿un afiliado esperaría que este término devuelva empresas de esta categoría?**
4. `APPROVE` sólo con **evidencia real**. Nunca por parecido de palabras ni por similitud semántica.
5. Términos genéricos (*upstream*, *oil and gas*) → `NEEDS_CONTEXT`.
6. Duda → `ESCALATE`. Siempre.
7. **Las cinco decisiones se anotan igual: sólo en la planilla.** La fila queda en *"En revisión"*.
8. **Jamás** las acciones masivas.
9. Primer lote: 30–50 y pare para calibrar.
10. Al cerrar el lote, llene el resumen.

---

## 14. Nota de gobernanza

Esta guía es **documentación y capacitación**. Por sí sola no otorga ningún permiso operativo sobre el
sistema.

**La etapa que esta guía describe es estrictamente de revisión registrada.** El pasante lee la
pantalla y anota su criterio en una planilla; **no se modifica ninguna relación Término↔CPV, no se
aprueba ni se rechaza nada en el sistema, y no se publica nada al buscador.** Al terminar un lote, el
estado de la taxonomía es idéntico al de antes de empezar. Lo que el lote produce es la planilla.

Habilitar al pasante para tomar decisiones **operativas** —usar *Aprobar* o *Rechazar* de verdad—
requiere una **tarea aparte, con autorización explícita y separada** de la Cámara, posterior a la
revisión de esta guía de calibración y de sus resultados. Esa autorización es un acto distinto de
cualquier métrica de calidad: alcanzar la tasa de coincidencia sugerida **no** la otorga ni la
anticipa.

En ningún caso el pasante recibe acceso directo a la base de datos.

### Por qué está planteado así

El botón *Aprobar* no registra una opinión: ejecuta una transición de estado que **publica la relación
al buscador en vivo** y queda asentada en la auditoría con el nombre de quien la hizo. Una etapa de
calibración existe precisamente para que las decisiones puedan compararse y discutirse **antes** de
tener consecuencias. Si la calibración ya publicara, no sería calibración.

Separar «revisar» de «publicar» también protege al pasante: en esta etapa, equivocarse no cuesta nada
más que una línea corregida en una planilla.

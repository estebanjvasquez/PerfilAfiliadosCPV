# Formato de revisión taxonómica — versión Markdown

Mismo registro que `FORMATO_REVISION_TAXONOMICA_PASANTE.csv`, para quien prefiera un documento. **Use
uno de los dos, no los dos.** El CSV es mejor para contar y calcular la tasa de coincidencia del lote;
este archivo es mejor para escribir comentarios largos.

Instrucciones completas: `GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`.

> El CSV incluye tres filas de ejemplo con `Review_ID` que empieza en `EJ-` y `Revisor = EJEMPLO (borrar
> esta fila)`. **Bórrelas antes de empezar** para que no entren en las cuentas del lote.

> ### ⚠️ Esta planilla ES el registro de la decisión
>
> Durante la calibración, **las cinco decisiones se anotan únicamente acá** y la pantalla se usa sólo
> para leer. No se usa *Aprobar*, ni *Rechazar*, ni *Editar*, ni *Eliminar*, ni ninguna acción masiva.
>
> Escribir `APPROVE` en esta planilla **no aprueba nada en el sistema**: la relación queda en *"En
> revisión"* y el `Estado` del ítem queda en `PENDIENTE_SUPERVISOR` hasta que el supervisor lo compare.

---

## 1. Diccionario de campos

### Bloque A — lo llena el pasante

| Campo | Qué poner | Dónde se lee en la pantalla |
|---|---|---|
| `Review_ID` | Identificador correlativo, p. ej. `L1-001` (lote 1, ítem 1) | — |
| `Fecha` | `AAAA-MM-DD` | — |
| `Revisor` | Su nombre | — |
| `Termino` | El término exacto, tal como aparece | Columna **"Término"** |
| `Idioma` | `es` / `en`. Si no se ve, déjelo vacío | No está en esta pantalla; se ve en la pantalla de Términos (`taxonomy_terms.language`). **No lo adivine** |
| `Concepto_Canonico` | Concepto al que pertenece el término, si se ve | No está en esta pantalla. Vacío es válido |
| `Codigo_CPV` | El código propuesto | Columna **"Código CPV"** |
| `Grupo_CPV` | Nivel *Grupo* de la jerarquía | Columna **"Categoría"** (ruta completa) |
| `Familia_CPV` | Nivel *Familia* de la jerarquía | Columna **"Categoría"** (ruta completa) |
| `Categoria_CPV` | Nivel *Categoría* (el más específico) | Columna **"Categoría"** (ruta completa) |
| `Decision` | Una de las cinco. Lista abajo | — |
| `Confianza_1_a_5` | **Su** confianza en su propia decisión, de 1 a 5 | — |
| `Evidencia_Consultada` | Qué miró. Varios valores separados por `;` | Lista abajo |
| `Comentario_Tecnico` | 1 a 3 frases con el motivo | — |
| `Escalar_A` | A quién escala. Sólo si `Decision = ESCALATE` | — |
| `Motivo_Escalamiento` | Por qué escala. Sólo si `Decision = ESCALATE` | — |

> **`Confianza_1_a_5` es suya, no del algoritmo.** La pantalla tiene su propia columna *"Confianza"*,
> que es la del proceso automático y **no** es un criterio de negocio. No las copie: si la pantalla dice
> 0,95 y usted no está seguro, su confianza es baja.

### Bloque B — lo llena el supervisor (CPV / SISTEG)

| Campo | Qué pone el supervisor | Valores |
|---|---|---|
| `Revisado_Por_Supervisor` | Quién revisó | Libre |
| `Decision_Final` | La decisión que queda | Las mismas cinco |
| `Observaciones_Supervisor` | Por qué coincide o no | Libre |
| `Estado` | En qué va el ítem | Lista abajo |

### Valores permitidos de `Decision`

| Valor | Significado | Acción en la pantalla, durante la calibración |
|---|---|---|
| `APPROVE` | El término pertenece claramente a esa categoría | **Ninguna.** La fila queda en *"En revisión"* |
| `REJECT` | La relación es incorrecta o engañosa | **Ninguna.** La fila queda en *"En revisión"* |
| `NEEDS_CONTEXT` | Demasiado amplio o ambiguo para mapearse así | **Ninguna.** La fila queda en *"En revisión"* |
| `ESCALATE` | Necesita un experto en CPV | **Ninguna.** La fila queda en *"En revisión"* |
| `POSSIBLE_NEW_CATEGORY` | Concepto legítimo sin categoría adecuada visible | **Ninguna.** La fila queda en *"En revisión"* |

**No hay más valores**, y **las cinco se registran igual: sólo acá.** En esta etapa la pantalla se usa
sólo para leer; no se usa *Aprobar*, ni *Rechazar*, ni *Editar*, ni *Eliminar*, ni ninguna acción
masiva. Anotar `APPROVE` en esta planilla **no aprueba nada en el sistema**.

> Si una tarea posterior, formalmente autorizada, habilita decisiones operativas, `APPROVE`
> correspondería al botón *"Aprobar"* y `REJECT` al botón *"Rechazar"*, ambos con *Motivo* obligatorio.
> Las otras tres no tienen equivalente en la pantalla. **Durante la calibración, no se usan esos
> botones.** Ver la sección 3 de `GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`.

### Valores sugeridos de `Evidencia_Consultada`

Separe varios con `;`.

| Valor | Qué significa |
|---|---|
| `PERFIL_EMPRESA` | Texto explícito en el perfil o registro del afiliado |
| `SIGNIFICADO_INDUSTRIAL` | Significado establecido e inequívoco en el sector |
| `SITIO_WEB_OFICIAL` | Evidencia en el sitio web de la empresa |
| `TERMINOLOGIA_CPV` | Terminología CPV de referencia |
| `COINCIDIO_CON` | El campo *"Coincidió con"* de la pantalla |
| `EVIDENCIA_AUTO_MAPPER` | El campo *Evidencia* generado por el proceso automático |
| `JERARQUIA_CPV` | La ruta completa de la categoría |
| `PREVIEW_IMPACTO` | El campo *"Preview de impacto"* (cuántas empresas afecta) |
| `NINGUNA` | No había evidencia disponible |

> `COINCIDIO_CON` y `EVIDENCIA_AUTO_MAPPER` solos **no alcanzan para `APPROVE`.** Son la propuesta del
> algoritmo, no evidencia. Si son lo único que miró, la decisión es `NEEDS_CONTEXT` o `ESCALATE`.
>
> Y si la evidencia fue `NINGUNA`, la decisión **nunca** puede ser `APPROVE`.

### Valores permitidos de `Estado`

| Valor | Significado |
|---|---|
| `PENDIENTE_SUPERVISOR` | Revisado por el pasante, esperando al supervisor |
| `REVISADA` | Supervisor revisó y coincidió |
| `CORREGIDA` | Supervisor revisó y cambió la decisión |
| `ESCALADA` | Derivada a un experto, sin resolver todavía |
| `CERRADA` | Resuelta y aplicada |

**Durante la calibración, todo ítem nace `PENDIENTE_SUPERVISOR`.** Ése es el punto de la calibración.

---

## 2. Ficha por ítem

Copie este bloque una vez por relación revisada.

```
Review_ID:                 L_-___
Fecha:                     AAAA-MM-DD
Revisor:
Termino:
Idioma:                    es | en | (vacío)
Concepto_Canonico:
Codigo_CPV:
Grupo_CPV:
Familia_CPV:
Categoria_CPV:
Decision:                  APPROVE | REJECT | NEEDS_CONTEXT | ESCALATE | POSSIBLE_NEW_CATEGORY
Confianza_1_a_5:           1..5   (la suya, no la de la pantalla)
Evidencia_Consultada:
Comentario_Tecnico:
Escalar_A:                 (sólo si ESCALATE)
Motivo_Escalamiento:       (sólo si ESCALATE)
--- de acá para abajo lo completa el supervisor ---
Revisado_Por_Supervisor:
Decision_Final:
Observaciones_Supervisor:
Estado:
```

---

## 3. Tres ejemplos llenados

Los mismos que trae el CSV. Muestran el nivel de detalle útil y, sobre todo, **cómo se ve una decisión
que no es `APPROVE`**.

### Ejemplo 1 — `APPROVE` con evidencia sólida

```
Review_ID:                 L1-001
Termino:                   guaya fina
Idioma:                    es
Categoria_CPV:             Servicios de cable de pozo
Decision:                  APPROVE
Confianza_1_a_5:           5
Evidencia_Consultada:      PERFIL_EMPRESA;SIGNIFICADO_INDUSTRIAL
Comentario_Tecnico:        Guaya fina es el cable delgado de intervención de pozos (slickline). La
                           categoría corresponde a servicios de cable de pozo.
Estado:                    PENDIENTE_SUPERVISOR
```

Por qué está bien: hay significado industrial inequívoco **y** respaldo en el perfil. El comentario dice
qué es el término, no "es correcto".

Y note el `Estado`: **`PENDIENTE_SUPERVISOR`, aunque la decisión sea `APPROVE`.** En la pantalla no se
tocó nada, la relación sigue en *"En revisión"*, y el ítem espera la comparación del supervisor. Durante
la calibración **todos** los ítems nacen así.

### Ejemplo 2 — `NEEDS_CONTEXT` por término genérico

```
Review_ID:                 L1-002
Termino:                   upstream
Idioma:                    en
Categoria_CPV:             (categoría específica de perforación)
Decision:                  NEEDS_CONTEXT
Confianza_1_a_5:           4
Evidencia_Consultada:      COINCIDIO_CON;EVIDENCIA_AUTO_MAPPER
Comentario_Tecnico:        Upstream nombra un segmento entero de la cadena de valor y abarca decenas de
                           capacidades distintas. Forzarlo a una categoría específica deja afuera las
                           demás.
Estado:                    PENDIENTE_SUPERVISOR
```

Note que la **confianza es 4**: está bastante seguro de que *no* hay que mapearlo así. La confianza es
en su decisión, no en la relación.

### Ejemplo 3 — `ESCALATE` por evidencia insuficiente

```
Review_ID:                 L1-003
Termino:                   refinery
Idioma:                    en
Concepto_Canonico:         refinería / refinery
Codigo_CPV:                CPV-37.06.16S
Decision:                  ESCALATE
Confianza_1_a_5:           2
Evidencia_Consultada:      COINCIDIO_CON
Comentario_Tecnico:        La única evidencia es similitud semántica (distancia coseno 0.254) y el peso
                           0.7455 no es evidencia. No tengo certeza de que la categoría corresponda.
Escalar_A:                 EXPERTO_CPV
Motivo_Escalamiento:       Sin evidencia más allá de la similitud semántica del auto mapper.
Estado:                    PENDIENTE_SUPERVISOR
```

**Éste es un caso real del sistema**, no inventado: esa relación existe hoy con ese peso y ese origen, y
sigue sin aprobar a propósito. Es el ejemplo exacto de por qué un peso alto no es evidencia.

---

## 4. Verificación antes de cerrar el lote

- [ ] Borré las filas de ejemplo `EJ-`.
- [ ] Todo ítem tiene `Decision` y `Comentario_Tecnico`.
- [ ] Ningún `APPROVE` tiene `Evidencia_Consultada = NINGUNA`.
- [ ] Ningún `APPROVE` se apoya **sólo** en `COINCIDIO_CON` o `EVIDENCIA_AUTO_MAPPER`.
- [ ] Todo `ESCALATE` tiene `Escalar_A` y `Motivo_Escalamiento`.
- [ ] **No modifiqué ninguna fila en la pantalla**, con ninguna de las cinco decisiones.
- [ ] **No usé *Aprobar*, *Rechazar*, *Editar*, *Eliminar* ni ninguna acción masiva.**
- [ ] Todos los ítems quedaron en `Estado = PENDIENTE_SUPERVISOR` (salvo que el supervisor ya los haya
      revisado y completado sus campos).
- [ ] `Review_ID` sin repetidos.
- [ ] Llené `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`.

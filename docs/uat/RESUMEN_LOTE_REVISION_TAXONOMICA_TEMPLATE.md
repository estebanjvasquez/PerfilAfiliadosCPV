# Resumen de lote — revisión taxonómica

Se llena **al cerrar cada lote**, después de que el supervisor revisó. Reemplace cada `___`. Lo que no
se pudo medir se escribe `no medido` — **no se estima**.

> **Etapa de revisión registrada.** En esta etapa el lote produce una planilla de decisiones y **cero
> cambios en el sistema**: ninguna relación Término↔CPV se aprueba, se rechaza ni se publica. Las
> cuentas de `APPROVE` de abajo son **criterio registrado**, no relaciones publicadas. La sección 7
> verifica explícitamente que así fue.

| | |
|---|---|
| **ID del lote** | `___` (p. ej. L1) |
| **Tipo de lote** | `CALIBRACION` / `PRODUCCION` |
| **Revisor** | `___` |
| **Supervisor** | `___` |
| **Fechas** | del `___` al `___` |
| **Planilla de origen** | `___` |
| **Rango de `Review_ID`** | de `___` a `___` |

---

## 1. Volumen

| Métrica | Valor |
|---|---|
| Relaciones revisadas | `___` |
| Revisadas por el supervisor | `___` |
| Horas aproximadas | `___` |

> Para un lote de calibración lo esperado es **30 a 50**. Para producción, **50 a 100**. Si el lote salió
> fuera de ese rango, diga por qué.

---

## 2. Decisiones del revisor

| Decisión | Cantidad | % del lote |
|---|---|---|
| `APPROVE` | `___` | `___`% |
| `REJECT` | `___` | `___`% |
| `NEEDS_CONTEXT` | `___` | `___`% |
| `ESCALATE` | `___` | `___`% |
| `POSSIBLE_NEW_CATEGORY` | `___` | `___`% |
| **Total** | `___` | 100% |

**Confianza propia promedio del revisor:** `___` de 5

> **Un porcentaje bajo de `APPROVE` no es un mal lote.** Si la mayoría de las propuestas automáticas eran
> débiles, lo correcto es que haya pocos `APPROVE`. Lo que sí es señal de alarma es un `APPROVE` muy alto
> con confianza promedio baja: sugiere que se aprobó sin certeza.

---

## 3. Tasa de coincidencia con el supervisor

**Se calcula sólo sobre los casos NO ambiguos**, es decir excluyendo los que el supervisor marcó como
genuinamente ambiguos.

| Métrica | Valor |
|---|---|
| Ítems revisados por el supervisor | `___` |
| Ítems considerados ambiguos (excluidos del cálculo) | `___` |
| **Base del cálculo** (revisados − ambiguos) | `___` |
| Coincidencias (`Decision` = `Decision_Final`) | `___` |
| **Tasa de coincidencia** | `___`% |

**Referencia sugerida:** ≥ 90% antes de aumentar el volumen.

> Esto es un **indicador de calidad, no un permiso automático de publicación.** Alcanzar el 90% no
> habilita aprobar sin supervisión ni convierte las aprobaciones en automáticas.

### Discrepancias

Listarlas **todas**. Son el material con el que se mejora la guía.

| `Review_ID` | Término | Decisión del revisor | Decisión final | Por qué difirieron |
|---|---|---|---|---|
| `___` | `___` | `___` | `___` | `___` |

### Patrón en las discrepancias

¿Se equivocaron siempre en la misma dirección?

- [ ] El revisor aprobó de más (riesgo de falsos positivos). **Es el patrón más grave.**
- [ ] El revisor aprobó de menos (demasiado conservador). Es el patrón menos riesgoso.
- [ ] Discrepancias por interpretar la jerarquía CPV de forma distinta.
- [ ] Discrepancias por el estándar de evidencia.
- [ ] Sin patrón claro.

**Comentario:** `___`

---

## 4. Ambigüedades recurrentes

Términos que generaron duda **más de una vez**. Si la misma duda apareció varias veces, es un problema
de la guía, no del revisor.

| Término | Veces que apareció | Por qué es ambiguo | Cómo resolverlo en adelante |
|---|---|---|---|
| `___` | `___` | `___` | `___` |

**¿Hay que agregar algún ejemplo a la sección 8 de la guía?** `SI` / `NO` → cuál: `___`

---

## 5. Categorías faltantes

Ítems marcados `POSSIBLE_NEW_CATEGORY`, y códigos huérfanos detectados con el filtro *"Código sin
categoría (huérfano)"*.

| Término o código | Qué describe | Por qué no encaja en ninguna categoría existente | ¿Se verificó con el filtro de huérfanos? |
|---|---|---|---|
| `___` | `___` | `___` | `SI` / `NO` |

> Crear categorías nuevas **no** es parte de esta tarea. Acá sólo se registra el hueco.

---

## 6. Escalamientos

| `Review_ID` | Término | `Escalar_A` | Motivo | Estado |
|---|---|---|---|---|
| `___` | `___` | `___` | `___` | `___` |

**Escalamientos sin resolver al cerrar el lote:** `___`

---

## 7. Verificación de reglas

Confirmación explícita de que las restricciones se respetaron. **La primera es la que define esta
etapa.**

- [ ] **CERO modificaciones en la pantalla.** Ninguna de las relaciones revisadas en este lote cambió
      de estado: todas siguen en *"En revisión"*, con las cinco decisiones registradas sólo en la
      planilla.
- [ ] No se usó **"Aprobar"** en ninguna fila.
- [ ] No se usó **"Rechazar"** en ninguna fila.
- [ ] No se usó **"Editar"** ni **"Eliminar"** en ninguna fila.
- [ ] **No se usó ninguna acción masiva** (*"Aprobar seleccionadas"* / *"Rechazar seleccionadas"*).
- [ ] Todos los ítems quedaron en `Estado = PENDIENTE_SUPERVISOR`, salvo los que el supervisor ya
      revisó y completó.
- [ ] Ningún `APPROVE` se apoya sólo en similitud semántica o parecido de palabras.
- [ ] No se crearon códigos CPV nuevos.
- [ ] No se modificó código, pesos, ranking ni la base de datos.

**Si alguna casilla quedó sin marcar, explique:** `___`

> **Si la primera casilla no se puede marcar**, pare y avise a SISTEG antes de continuar con otro lote.
> Una relación modificada durante la calibración no es un error de forma: publicó o descartó algo que
> todavía no estaba autorizado, y hay que registrarlo y revisarlo explícitamente.

### Verificación independiente del supervisor

| Verificación | Resultado |
|---|---|
| Relaciones del lote que siguen en *"En revisión"* | `___` de `___` |
| Relaciones del lote con `reviewed_at` o cambio de estado | `___` (lo esperado es **0**) |

> La segunda fila es la comprobación objetiva: si es distinta de 0, hubo modificación en pantalla, por
> más que las casillas de arriba estén marcadas.

---

## 8. Recomendaciones para el lote siguiente

| # | Recomendación | Motivo |
|---|---|---|
| 1 | `___` | `___` |

### Decisión sobre el volumen

- [ ] **Mantener** el tamaño de lote. Tasa de coincidencia aún por debajo de la referencia.
- [ ] **Aumentar** a `___` ítems. Coincidencia ≥ 90% en casos no ambiguos.
- [ ] **Reducir** a `___` ítems. Demasiadas discrepancias o ambigüedades.
- [ ] **Pausar** y revisar la guía antes de continuar.

**Fundamento:** `___`

> Esta decisión es **sólo sobre el tamaño del lote**. Habilitar decisiones operativas —que el revisor
> pueda usar *Aprobar* o *Rechazar* de verdad— es una **tarea aparte con autorización explícita y
> separada** de la Cámara, y no se decide en esta plantilla por más alta que sea la coincidencia.

### Ajustes a la guía

| Sección de la guía | Qué ajustar | Por qué |
|---|---|---|
| `___` | `___` | `___` |

---

## 9. Cierre

| | |
|---|---|
| **Revisor** | `___` **Fecha:** `___` |
| **Supervisor** | `___` **Fecha:** `___` |
| **Estado del lote** | `CERRADO` / `CERRADO_CON_PENDIENTES` / `ABIERTO` |

**Pendientes al cierre:** `___`

# Resumen de lote — revisión taxonómica

Se llena **al cerrar cada lote**, después de que el supervisor revisó. Reemplace cada `___`. Lo que no
se pudo medir se escribe `no medido` — **no se estima**.

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

Confirmación explícita de que las restricciones se respetaron.

- [ ] **No se usó ninguna acción masiva** (*"Aprobar seleccionadas"* / *"Rechazar seleccionadas"*).
- [ ] Todo `APPROVE` y `REJECT` registrado en pantalla lleva su *Motivo*.
- [ ] Los `NEEDS_CONTEXT`, `ESCALATE` y `POSSIBLE_NEW_CATEGORY` dejaron la fila en *"En revisión"*, sin
      tocarla.
- [ ] No se usó *Editar* ni *Eliminar* en ninguna fila.
- [ ] Ningún `APPROVE` se apoya sólo en similitud semántica o parecido de palabras.
- [ ] No se crearon códigos CPV nuevos.
- [ ] No se modificó código, pesos, ranking ni la base de datos.

**Si alguna casilla quedó sin marcar, explique:** `___`

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

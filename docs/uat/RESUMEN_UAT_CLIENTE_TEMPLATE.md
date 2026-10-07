# Resumen del UAT del buscador — plantilla

**Cómo se usa:** se llena **después** de que el cliente devuelve la planilla y el equipo técnico
completa el triage. Reemplace cada `___` por el valor real. Lo que no se pudo medir se escribe
`no medido` — **no se estima**.

| | |
|---|---|
| **Ciclo de UAT** | `___` (p. ej. UAT-01) |
| **Entorno probado** | <https://pruebas.camarapetrolera.app/cira-test/> |
| **Fechas de prueba** | del `___` al `___` |
| **Evaluador(es) del cliente** | `___` |
| **Triage técnico por** | `___` |
| **Planilla de origen** | `___` |
| **Fecha de este resumen** | `___` |

---

## 1. Cobertura

| Métrica | Valor |
|---|---|
| Consultas propuestas por SISTEG | `___` de 35 |
| Consultas propuestas por el cliente | `___` de 10 |
| **Total probadas** | `___` |
| Total sin probar | `___` |

> Si quedaron consultas sin probar, decir **por qué**. Un UAT parcial declarado es información; un UAT
> parcial presentado como completo no lo es.

---

## 2. Resultado general

| Resultado | Cantidad | % del total probado |
|---|---|---|
| `PASS` | `___` | `___`% |
| `PARCIAL` | `___` | `___`% |
| `FALLA` | `___` | `___`% |
| `N/A` | `___` | `___`% |

**Puntuación promedio:** `___` de 5

> El promedio se calcula **sólo sobre las filas con nota numérica**. Las `N/A` se excluyen del promedio
> y se informan aparte: contarlas como 0 o como 3 falsea el resultado en direcciones opuestas.
>
> Filas incluidas en el promedio: `___`. Filas `N/A` excluidas: `___`.

### Distribución de notas

| Nota | Cantidad |
|---|---|
| 5 — Excelente | `___` |
| 4 — Bueno | `___` |
| 3 — Aceptable | `___` |
| 2 — Deficiente | `___` |
| 1 — Incorrecto | `___` |
| `N/A` | `___` |

---

## 3. Consultas con nota ≤ 2

Las que concentran el problema. **Listarlas todas**, no una muestra.

| Test_ID | Consulta | Nota | Tipo_de_problema | Severidad | Comentario del cliente (resumido) |
|---|---|---|---|---|---|
| `___` | `___` | `___` | `___` | `___` | `___` |

---

## 4. Hallazgos por tipo

| `Tipo_de_problema` | Cantidad | Severidad máxima | Consultas afectadas |
|---|---|---|---|
| `SIN_PROBLEMA` | `___` | — | — |
| `FALSO_POSITIVO` | `___` | `___` | `___` |
| `FALSO_NEGATIVO` | `___` | `___` | `___` |
| `RANKING` | `___` | `___` | `___` |
| `TERMINO_TAXONOMIA` | `___` | `___` | `___` |
| `MAPEO_CPV` | `___` | `___` | `___` |
| `DATOS_EMPRESA` | `___` | `___` | `___` |
| `EVIDENCIA_WEB_FALTANTE` | `___` | `___` | `___` |
| `CONSULTA_AMBIGUA` | `___` | `___` | `___` |
| `UI_USABILIDAD` | `___` | `___` | `___` |
| `OTRO` | `___` | `___` | `___` |

### 4.1 Falsos positivos

Empresas que aparecieron y no correspondían.

| Test_ID | Consulta | Qué apareció de más | Causa identificada |
|---|---|---|---|
| `___` | `___` | `___` | `___` |

### 4.2 Falsos negativos

Empresas relevantes que faltaron, **después** de descartar que fuera un perfil incompleto.

| Test_ID | Consulta | Qué faltó | ¿Declara la capacidad en su perfil? | Causa identificada |
|---|---|---|---|---|
| `___` | `___` | `___` | `SI` / `NO` | `___` |

### 4.3 Quejas de ranking

| Test_ID | Consulta | Posición observada | Posición esperada | Verificado más allá del top-5 |
|---|---|---|---|---|
| `___` | `___` | `___` | `___` | `SI` / `NO` |

### 4.4 Brechas de cobertura de datos

Casos donde la empresa no declara lo que el cliente sabe que hace. **No son defectos del buscador**;
son trabajo de completitud de perfiles.

| Empresa | Capacidad ausente del perfil | Consultas donde se notó |
|---|---|---|
| `___` | `___` | `___` |

### 4.5 Problemas de taxonomía y de CPV

| Test_ID | Término | ¿Término o CPV? | Qué está mal | Alcance estimado (empresas afectadas) |
|---|---|---|---|---|
| `___` | `___` | `TERMINO` / `CPV` | `___` | `___` |

> El alcance se lee del *"Preview de impacto"* de la pantalla de la relación, no se estima a ojo.

### 4.6 Brechas de evidencia web

| Empresa o término | Qué evidencia falta | Impide aprobar qué |
|---|---|---|
| `___` | `___` | `___` |

---

## 5. Bloqueantes

Un bloqueante es algo que impide aceptar el buscador tal como está, **no** simplemente algo molesto.

| # | Bloqueante | Test_ID que lo evidencian | Severidad | Por qué bloquea |
|---|---|---|---|---|
| 1 | `___` | `___` | `___` | `___` |

**Si no hubo bloqueantes, decirlo explícitamente:** `Sin bloqueantes.`

---

## 6. Observaciones del cliente que no son defectos

Pedidos de mejora, expectativas distintas, funcionalidad futura. Se registran para no perderlos y para
no mezclarlos con los defectos.

| # | Observación | ¿Entra en alcance actual? |
|---|---|---|
| 1 | `___` | `SI` / `NO` / `A DEFINIR` |

---

## 7. Decisión final del cliente

> Marque **una**. La decisión es del cliente, no del equipo técnico.

- [ ] **`ACCEPTED`** — El buscador cumple lo esperado. Puede avanzar sin correcciones previas.
- [ ] **`ACCEPTED_WITH_OBSERVATIONS`** — Cumple lo esencial. Hay observaciones registradas que se
      atienden después, sin detener el avance.
- [ ] **`REQUIRES_CORRECTIONS`** — Hay al menos un bloqueante. Requiere corrección y un nuevo ciclo de
      UAT sobre los puntos corregidos.

**Decisión:** `___`

**Fundamento (2–4 líneas):**

```
___
```

**Firma / responsable por la Cámara:** `___`  **Fecha:** `___`

---

## 8. Próximos pasos acordados

| # | Acción | Responsable | Clasificación técnica | Fecha objetivo |
|---|---|---|---|---|
| 1 | `___` | `___` | `___` | `___` |

### Recordatorios de gobernanza

- Toda corrección de **ranking o pesos** exige volver a correr la regresión congelada de 32 consultas
  y comparar contra `audit/regression_baseline_2026-09-23.json` antes de aceptarse.
- Toda corrección de **taxonomía** pasa por el flujo de propuestas revisadas (congelar → confirmar →
  aplicar), con autorización explícita para la aplicación real.
- Toda corrección de **mapeo CPV** se revisa **ítem por ítem**. No se usa aprobación masiva.
- Las correcciones **no** se hacen en el mismo ciclo que el diagnóstico.

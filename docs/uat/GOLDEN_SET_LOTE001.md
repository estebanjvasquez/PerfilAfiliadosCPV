# Lista de referencia del buscador: lote 001

[`golden_set_lote001.json`](golden_set_lote001.json) convierte la primera ronda de pruebas del cliente
([`RESULTADOS_BUSCADOR_LOTE001.xlsx`](RESULTADOS_BUSCADOR_LOTE001.xlsx), 3 y 4 de noviembre de 2026) en
juicios por empresa con su `empresas.id`. Así cualquier cambio al buscador se puede medir contra lo que
el cliente ya dijo, sin pedirle otra ronda.

## Qué contiene

- 35 consultas evaluadas en los grupos G1 a G7. Las 10 filas G8, que iba a proponer el cliente,
  quedaron vacías y se omiten.
- 49 juicios positivos (`relevant`) y 66 negativos (`not_relevant`).
- Por caso: consulta, grupo, calificación 1–5, comentario del cliente, comportamiento esperado y
  etiquetas de análisis.
- `recurring_noise_companies`: empresas que el cliente marcó como no relevantes en dos o más consultas.
- `client_general_observations`: los comentarios generales que acompañaron la entrega.

Las evaluadoras figuran con seudónimo (`EVAL_A`, `EVAL_B`) porque el repositorio es público.

## Cómo leerla

| Campo | Significado |
|---|---|
| `relevant` | El cliente dijo que la empresa **sí** corresponde. |
| `not_relevant` | La empresa apareció y el cliente dijo que **no** corresponde. |
| Empresa ausente de ambas listas | **Desconocida, no negativa.** El cliente no pudo indicar empresas faltantes. |
| Orden dentro de cada lista | El que escribió la evaluadora. **No** es la posición en el ranking. |
| `expected_behavior` | `results` en consultas de dominio; `clarification`, `no_results`, `out_of_scope_message` o `institutional_answer_no_companies` donde el cliente aprobó esa salida (G4-02, G7). |
| `analysis_tags` | Etiquetas técnicas puestas al convertir el Excel; no las escribió el cliente. |

## Métricas que permite

[`tools/evaluate_search.mjs`](tools/evaluate_search.mjs) las calcula (uso en [`tools/README.md`](tools/README.md)).
Cada métrica se calcula sobre la salida de `search_empresas` para la `query` de cada caso:

- **Aciertos en los primeros 5 y 10**: cuántas empresas `relevant` aparecen en ese tramo.
- **Ruido conocido en los primeros 5 y 10**: cuántas empresas `not_relevant` aparecen en ese tramo.
  Debe bajar sin que bajen los aciertos.
- **Posición de la primera relevante.**
- **Comportamiento esperado**: los casos que no son `results` deben seguir dando su salida aprobada.

Como hay pocos juicios por consulta, la lista sirve para detectar empeoramientos y comparar versiones.
No alcanza para declarar que una consulta "quedó resuelta". Complementa la regresión congelada del
Worker (`scripts/fixtures/regression-cases.json`) y no la reemplaza.

## Pendientes de resolución

- **INSERVEN** (G3-05, no relevante): hay dos empresas, la 122 y la 286. Falta confirmar con el cliente.
- **ECOPEC** (G7-02, no relevante): no hay ninguna empresa con ese nombre ni una variante cercana.

Seis nombres se resolvieron a mano porque tenían errores de tipeo: ADVANCE LOGGING, MARITIME CONTRACTOS,
METAL MECANICA DE MERIDA, TOTAL CLEAM, Consorcio Padalca e Ineletcra. Están en `MANUAL` dentro del
generador.

## Regenerar

[`tools/build_golden_set_lote001.js`](tools/build_golden_set_lote001.js) recibe tres rutas:

```
node build_golden_set_lote001.js <filas_excel.json> <empresas.json> <salida.json>
```

- `filas_excel.json`: la hoja como arreglo de objetos con los encabezados de la fila 1 como claves.
- `empresas.json`: `select id, name, status_id from empresas` (solo lectura).

Si un nombre deja de resolverse a una sola empresa, el generador falla en lugar de adivinar.

# TASK-0031: evidencia del benchmark aislado de latencia (sin deploy)

El informe está en [`audit/CIRA_TASK0031_BENCHMARK_LATENCIA.md`](../../../audit/CIRA_TASK0031_BENCHMARK_LATENCIA.md). Esta carpeta no contiene credenciales, URLs de webhooks ni flujos completos; los resultados usan ids de empresa y las entradas son textos de búsqueda.

| Ruta | Contenido |
|---|---|
| `bench/build_variant.mjs` | Copia aislada del flujo para cada variante (V0, V1, V1b, M41, M54, V1M41), con registro desactivado y webhook propio |
| `bench/bench_variant.ps1` | Ciclo de una variante: crear, activar, correr, desactivar, recoger y borrar |
| `bench/run_variant.mjs` | Envía las entradas en serie, una sesión nueva por consulta |
| `bench/collect.mjs` | Ms por nodo, llamadas al modelo, tokens, parámetros y resultados de cada ejecución (API de n8n, lectura) |
| `bench/eval_variants.mjs` | Latencia p50/p95, calidad con etiquetas del cliente y coincidencia con la línea base |
| `bench/worker_w1.mjs` | W1 frente a la versión desplegada: equivalencia y latencia en frío |
| `resultados/entradas.json` | 55 entradas del corpus y 4 de la UAT |
| `resultados/ejecuciones_*.json` | Una fila por búsqueda y variante |
| `resultados/comparacion_variantes.json` | Resumen por variante y matriz por consulta |
| `resultados/uat_limpia_v0_v1b.json` | UAT repetida sin otra carga (V0 frente a V1b) |
| `resultados/worker_w1.json` | Resultado de W1 |

El código de W1 está en `estebanjvasquez/perfilafiliados-mcp`, rama `perf/t31-worker-parallel` (`988f87f`), y su vista previa sin tráfico es `3539deec`.

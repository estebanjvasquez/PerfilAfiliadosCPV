# TASK-0032: evidencia (fase A: V1b; fase B: ficha por id)

Informes: [`audit/CIRA_TASK0032_FASE_A_V1B.md`](../../../audit/CIRA_TASK0032_FASE_A_V1B.md) (fase A) y [`audit/CIRA_TASK0032_FASE_B_FICHA_EMPRESA.md`](../../../audit/CIRA_TASK0032_FASE_B_FICHA_EMPRESA.md) (fase B). Esta carpeta no contiene credenciales, URLs de webhooks ni tokens; los resultados usan ids de empresa y textos de búsqueda.

| Ruta | Contenido |
|---|---|
| `n8n/build_v1b.mjs` | Fase A: arma V1b desde el export vivo (2 nodos); con `--copia-prueba [V0]`, la copia aislada |
| `n8n/zbVLoCdR09IA9yQK_c6c70402_sanitizado.json` | Versión publicada en la fase A, sanitizada |
| `n8n/build_detail.mjs` | Fase B: pedido de ficha con `[ID n]` y `get_empresa {id}` (3 nodos) |
| `n8n/zbVLoCdR09IA9yQK_2d0a1ee8_sanitizado.json` | Versión publicada en la fase B, sanitizada |
| `n8n/test_detail_copy.mjs` | Fase B: casos contra la copia aislada y recogida de ejecuciones |
| `worker/4599217_get_empresa.diff` | Fase B: cambio del Worker (`get_empresa`), rama `fix/t32-get-empresa-id` de `perfilafiliados-mcp` |
| `worker/get_empresa_regresion.mjs`, `worker/search_equivalencia.mjs` | Fase B: regresión del Worker vivo frente al candidato (solo lectura) |
| `resultados/fase_b_*.json`, `resultados/e2e_faseB.json` | Fase B: regresión, copia aislada (nombres sustituidos por ids) y E2E |
| `bench/bench_t32.ps1` | Ciclo de una copia aislada (crear, activar, correr, desactivar, recoger, borrar); reutiliza los scripts de `docs/cira/task0031/bench/` |
| `e2e/e2e_cira.mjs` | E2E con Playwright en staging (escritorio y Pixel 5) |
| `resultados/entradas*.json` | Corpus de 55, UAT de 4, control de 5; entradas dirigidas |
| `resultados/ejecuciones_*.json` | Una fila por búsqueda: V0, V1b r1, r2, r3 y pruebas dirigidas |
| `resultados/comparacion_variantes.json` | Resumen y matriz por consulta (V0, r1, r2, r3) |
| `resultados/e2e_faseA.json` | E2E de la fase A |
| `capturas/` | Capturas del E2E |

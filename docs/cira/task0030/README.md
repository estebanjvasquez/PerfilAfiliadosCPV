# TASK-0030: evidencia de las correcciones de la UAT de TASK-0029

El informe está en [`audit/CIRA_TASK0030_CORRECCIONES_UAT.md`](../../../audit/CIRA_TASK0030_CORRECCIONES_UAT.md). Esta carpeta no contiene credenciales, URLs de webhooks ni tokens; los resultados usan ids de empresa.

| Ruta | Contenido |
|---|---|
| `n8n/format_cards2_vistas.js` | Bloque de `Format Cards2` con las dos vistas excluyentes |
| `n8n/build_flow.mjs` | Arma el `PUT` desde el export vivo; con `--copia-prueba`, la copia aislada |
| `n8n/zbVLoCdR09IA9yQK_7feb48bc_sanitizado.json` | Versión publicada, sanitizada |
| `latencia/n8n_timings.mjs`, `n8n_ejecuciones_*.json` | Tiempos por nodo de ejecuciones reales (API de n8n, lectura) |
| `latencia/resumen.mjs`, `resumen.json` | Medianas por período y tramo; consultas de la asignación |
| `latencia/worker_latency.mjs`, `worker_por_defecto_vs_grupos.json` | Worker directo, en frío y en caliente |
| `latencia/e2e_navegador.json` | E2E en staging: vistas, latencia de navegador, voto, perfil, evaluación |
| `experimentos/resultados_55.json`, `metricas.json` | Batería de 55 consultas (scripts de `docs/cira/task0029/experimentos/`) |
| `capturas/` | E2E en staging: `e2e30_<dispositivo>_<0 perforación · 1 balancín · 2 macolla>_<cap · exp>.png` |

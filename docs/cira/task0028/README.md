# TASK-0028 — artefactos de la auditoría CIRA

El informe está en [`audit/CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md`](../../../audit/CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md).
Nada de esta carpeta está desplegado ni lo usa el sistema vivo.

| Ruta | Contenido |
|---|---|
| `n8n/zbVLoCdR09IA9yQK_bafd597a_sanitizado.json` | Copia de solo lectura del flujo vivo de CIRA Test (`versionId bafd597a…`), sin credenciales, contraseña de comandos, hosts, rutas de webhook, ids internos ni correos |
| `mockup/cira_test_dos_grupos.html` | Mockup estático de los dos grupos (capacidades / experiencias) con el resultado real de la variante B para «balancín». Se abre directamente en el navegador |
| `experimentos/t28_clone.sh` | Volcado de solo lectura (`pg_dump -Fc -n public`) y copia aislada sin puertos publicados. Requiere `CLONE_PG_PASSWORD` |
| `experimentos/noexp.sql` | Construye `esd_noexp` (documento de `fulltext` sin experiencias), **solo en la copia** |
| `experimentos/fetch_live.mjs` | Aportes vectoriales de `/debug-search` (lectura; no escribe `cira_search_log`). Requiere `CPV_DEBUG_TOKEN` en el entorno |
| `experimentos/harness.ts` | Arnés Actual / A / B / dedup con el código del Worker `77ce273` |
| `experimentos/metrics.mjs` | Métricas contra las etiquetas del cliente (lote 001) |
| `experimentos/roles.sql` | Evidencia declarada de rol para las empresas de las consultas de intención |
| `experimentos/sanitize_n8n.mjs` | Sanitizador de la copia del flujo |
| `experimentos/cases.json` | 55 consultas: 49 reales (parámetros del agente en `cira_search_log`) y 6 sintéticas |
| `experimentos/live_vectorial.json`, `results.json`, `metrics.json` | Datos y resultados (solo ids de empresa, sin nombres) |

## Reproducir

1. **Copia aislada.** En el VPS, como root:
   - copiar `t28_clone.sh`;
   - definir `CLONE_PG_PASSWORD`;
   - ejecutarlo.
   El volcado queda en `/root/backups/cira-t28-<ts>/`.
2. **Documento sin experiencias.** En la copia: `psql -f noexp.sql`.
3. **Aportes vectoriales vivos.** Con el acceso del proyecto cargado (`bootstrap_access.ps1`): `node fetch_live.mjs cases.json live_vectorial.json`.
4. **Arnés.** Empaquetarlo con el `node_modules` del repositorio del Worker:
   - `esbuild harness.ts --bundle --platform=node --format=esm --outfile=harness.mjs`, con `NODE_PATH=<worker>/node_modules` y el código de `77ce273` extraído en `./mcp`;
   - ejecutarlo en `node:20-alpine`, en la red de la copia: `node harness.mjs cases.json live_vectorial.json results.json`.
5. **Métricas.** `node metrics.mjs results.json live_vectorial.json metrics.json`.

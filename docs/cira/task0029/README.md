# TASK-0029: evidencia de la alternativa B (capacidades / experiencias)

El informe está en [`audit/CIRA_TASK0029_ALTERNATIVA_B_STAGING.md`](../../../audit/CIRA_TASK0029_ALTERNATIVA_B_STAGING.md). Esta carpeta no contiene credenciales, URLs de webhooks ni tokens. Los resultados solo traen ids de empresa; las capturas muestran nombres de empresas afiliadas tal como los muestra CIRA Test.

| Ruta | Contenido |
|---|---|
| `n8n/format_cards2_grupos.js` | Bloque nuevo de `Format Cards2`: dos grupos y selector |
| `n8n/build_flow.mjs` | Arma el cuerpo del `PUT` **desde el export vivo**: cambia solo `Call MCP Empresas`, `Build Search Log` y `Format Cards2`. Con `--copia-prueba` arma la copia aislada (otro webhook, sin registro, Worker de vista previa) |
| `n8n/render_preview.mjs` | Vista previa local de `Format Cards2` con respuestas reales, dentro del CSS de CIRA Test |
| `n8n/zbVLoCdR09IA9yQK_d2f8efb5_sanitizado.json` | Versión publicada del flujo, sanitizada |
| `experimentos/run_cases.mjs` | Corre las 55 consultas de TASK-0028 (modo por defecto y `grupos`). Los tokens solo se leen de variables de entorno |
| `experimentos/metrics.mjs` | Métricas con las etiquetas del cliente, invariantes y casos fijados |
| `experimentos/resultados_55_vivo_vs_b.json` | Vivo `1005d61f` (por defecto) frente a `8796f292` (por defecto y `grupos`), ids solamente |
| `experimentos/metricas.json` | Resumen y matriz por consulta |
| `capturas/` | Antes (una lista) y después (dos grupos), escritorio y Pixel 5, del E2E en staging |

El código del Worker está en `estebanjvasquez/perfilafiliados-mcp`, rama `feat/cira-two-groups`: `57db8a1` (r1) y `1ea9175` (r2, desplegado como `8796f292`).

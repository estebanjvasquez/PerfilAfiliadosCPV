# Paquete pre-UAT institucional de CPV (TASK-0033)

**Fecha de corte:** 2026-11-17 ~11:15Z.
**Asignación:** Issue #2 [`6945631895`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6945631895).
**Referencia de UAT:** [`6945403230`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6945403230).
**Tarea:** [`docs/orquestador/tasks/0033-pre-uat-institutional-readiness.md`](../orquestador/tasks/0033-pre-uat-institutional-readiness.md).
**Rama:** `fix/sec-01-r4-01-role-editor-preserve`, BASE `2cdb53d`.

**Para qué sirve:** decidir qué falta antes de convocar al personal de la Cámara. **Todo es análisis y documentación**: no hubo
despliegues ni cambios en n8n, el Worker, el modelo, la BD, la taxonomía, los roles, las empresas, las credenciales ni la
caché.

## Entregables

| # | Documento | Para quién | Qué responde |
|---|---|---|---|
| E1 | [Inventario consolidado de pendientes](E1_INVENTARIO_PENDIENTES.md) | Propietario, orquestador | Qué queda abierto, por área, con estado, riesgo, dependencia, quién decide, prioridad y siguiente acción |
| E2 | [Matriz de seguridad y bloqueos preproducción](E2_MATRIZ_SEGURIDAD_PREPRODUCCION.md) | Propietario, orquestador | Qué hallazgos siguen abiertos, qué se verificó hoy, qué bloquea la UAT y qué bloquea producción, y en qué orden cerrarlos |
| E3 | [Paquete institucional de UAT](E3_PAQUETE_UAT_INSTITUCIONAL.md) + [hoja de resultados](formatos/E3_hoja_de_resultados.csv) | Personal de la Cámara y organizador | Cómo hacer las pruebas, qué esperar, cómo anotar y cuándo se acepta |
| E4 | [Diagnóstico de latencia de CIRA](E4_DIAGNOSTICO_LATENCIA_CIRA.md) | Propietario, orquestador | Dónde se van los ~10 s, por qué difiere del benchmark y qué opciones hay |
| E5 | [Decisiones de la Cámara](E5_DECISIONES_DE_LA_CAMARA.md) + [formatos](formatos/) | La Cámara, por medio del propietario | Qué datos y criterios debe decidir la Cámara, con preguntas y formatos de respuesta |
| E6 | [Informe ejecutivo y plan de acciones](E6_INFORME_EJECUTIVO_Y_PLAN.md) | Propietario | Si se puede convocar ya (GO/NO-GO), qué hacer, en qué orden y con qué riesgos |

## Cómo leer la evidencia

| Etiqueta | Significa |
|---|---|
| **VIVO** | Verificado hoy en la fuente viva, con hora y comando. Evidencia en [`evidencia/`](evidencia/) |
| **DOC** | Documentado antes en el repo o en el Issue #2 (se cita la fuente). Puede no reflejar el estado actual |
| **PEND** | Pendiente de comprobar |
| **BLOQ** | Bloqueado por falta de acceso o por un gate no autorizado |
| **DEC** | Decisión del propietario o de la Cámara |
| **HIP** | Hipótesis: se indica la evidencia que la apoya |

Ningún elemento pasa aquí a PASS, CLOSED o APPROVED por inferencia.

## Evidencia y scripts (todos de solo lectura)

| Ruta | Contenido |
|---|---|
| [`evidencia/vivo_2026-11-17.md`](evidencia/vivo_2026-11-17.md) | Versiones vivas, `monitor_check.sh`, cabeceras HTTP, permisos e invitados, incidencia propia en el log |
| [`evidencia/bd_sonda_1.json`](evidencia/bd_sonda_1.json), [`bd_sonda_2.json`](evidencia/bd_sonda_2.json), [`bd_decisiones_camara_publico.json`](evidencia/bd_decisiones_camara_publico.json) | Resultados de las sondas SQL (`READ ONLY`, rollback), solo conteos e ids |
| [`../cira/task0033/bd/`](../cira/task0033/bd/) | Sondas SQL y generador de formatos |
| [`../cira/task0033/latencia/`](../cira/task0033/latencia/) | Scripts de latencia (n8n, Worker, análisis) |
| [`../cira/task0033/resultados/`](../cira/task0033/resultados/) | Resultados de latencia (ids de ejecución; la consulta de ficha se sustituyó por un marcador) |
| `docs/uat/privado/2026-11-17_preuat/` | **Fuera de git:** versiones con nombres de empresas y textos de solicitudes, para enviar a la Cámara |

## Consultas y pruebas realizadas (resumen)

- **SQL:** 4 sondas en transacción `READ ONLY` con rollback, más una consulta corta de términos. Una consulta falló por
  nombre de columna y se repitió.
- **Instantánea del servidor:** `monitor_check.sh` una vez; `git rev-parse` y `git status` en el servidor.
- **APIs de solo lectura:**
  - n8n: `GET` del flujo y de 22 ejecuciones con datos, más 14 lecturas de tokens y 1 de grupos;
  - `wrangler deployments status` y `wrangler hyperdrive get`.
- **Worker vivo:** 51 llamadas de solo lectura (`/mcp` con `tools/list` y `tools/call`, y `/debug-search`), entre 10:59Z y
  11:12Z.
- **HTTP:** 7 `GET` sin sesión a staging.
- **Sin tráfico de búsqueda por n8n:** `cira_search_log` tenía 142 filas antes y después.

# TASK-0029 — CIRA: implementar la alternativa B (capacidades / experiencias) y desplegarla en staging para UAT del propietario

| Campo | Valor |
|---|---|
| Estado | ~~IN_PROGRESS~~ → **DEPLOYED_FOR_OWNER_UAT** (2026-11-16 ~11:30Z) |
| Fuente | Issue #2 `6914028986` (estebanjvasquez, 2026-11-16T10:36:06Z): «AUTORIZACIÓN EXPRESA DEL PROPIETARIO — IMPLEMENTAR ALTERNATIVA B DE CIRA Y DESPLEGAR A STAGING PARA UAT MANUAL» |
| Tipo | Implementación y despliegue **solo en staging**, con gates |
| Antecedente | TASK-0028 (auditoría `6913187637`, entrega `6913769146`), que conserva su identificador, su historia y su estado |
| Rama de documentación | `fix/sec-01-r4-01-role-editor-preserve`, BASE `d628d2f` |
| Código | Worker `perfilafiliados-mcp`, rama nueva desde `77ce273`; flujo n8n `zbVLoCdR09IA9yQK` (solo los nodos necesarios) |
| Gates | sin producción, `main`, taxonomía, roles, datos de empresas ni credenciales; sin regeneración masiva de `empresa_search_documents`, DDL ni escrituras masivas en la BD compartida; sin tocar los flujos MySQL del kiosco ni otras copias de n8n; sin *force-push*; STOP al entregar |

## Estado vivo verificado al arrancar (2026-11-16 ~10:45Z)

- Árbol: solo los cambios locales del propietario (`AGENTS.md`, `CLAUDE.md`, `PROTOCOL.md`, §0 de `SESSION_RESUME.md`, `docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`), sin tocar. HEAD `d628d2f` = `origin`.
- Última tarea: TASK-0028 (READY_FOR_REVIEW). Siguiente identificador libre: **0029**.
- Staging (Laravel): `feature/upgrade-filament-v3` @ `5e6e3fc`, `/cira-test/` 200. B no necesita desplegar Laravel: `public/cira-test/index.html` inserta el HTML que arma n8n.
- Worker: despliegue vivo `1005d61f` (100 %, 2026-11-09 16:10Z); código `77ce273` (`feat/declared-first`). `wrangler` con sesión del propietario.
- n8n: CIRA Test = `zbVLoCdR09IA9yQK`, `versionId bafd597a`, sin cambios desde 2026-11-09 16:11:20Z.
- Aislamiento: de los 49 flujos de n8n, solo 2 activos llaman al Worker, `zbVLoCdR09IA9yQK` (CIRA Test) y `iVDEEo0oaEHDOvlp` (votos de staging). El kiosco (`Z2OvMPk0zj2MSYwL`, MySQL) no lo usa. El comportamiento nuevo del Worker se activa solo con un parámetro que únicamente enviará CIRA Test: sin ese parámetro, la respuesta no cambia.
- Monitorizaciones: las ventanas de 24 h de TASK-0026 y TASK-0027 terminaron el 2026-11-14; este cambio no toca Laravel ni la BD compartida.

## Comentario fuente (íntegro)

# AUTORIZACIÓN EXPRESA DEL PROPIETARIO — IMPLEMENTAR ALTERNATIVA B DE CIRA Y DESPLEGAR A STAGING PARA UAT MANUAL

**Decisión:** el propietario **APRUEBA la alternativa B** (dos grupos: capacidades y experiencias) descrita en la auditoría TASK-0028. Esta es una **nueva asignación de implementación**, distinta de la auditoría autorizada en el comentario [6913187637](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6913187637) y entregada en [6913769146](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6913769146). **Se autoriza implementar, modificar de forma controlada el workflow n8n de CIRA Test y el Worker pertinente, y desplegar la alternativa B exclusivamente a staging**, una vez superados los gates indicados. **La aceptación funcional final queda reservada al propietario tras su comprobación visual y manual.** No implica aprobación de producción, main, taxonomía ni operaciones masivas de BD.

## 0. Arranque, fuente de verdad y materialización
1. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, encabezado vigente de `docs/orquestador/current_task.md` y **solo** la auditoría `audit/CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md` con sus referencias directas necesarias (`docs/cira/task0028/`). Leer este comentario completo una vez. El checkpoint consultado por el orquestador puede estar desactualizado; **prevalece estado vivo**.
2. Verificar `git status -sb`, rama, HEAD, remotos, tareas activas, último ID de `docs/orquestador/tasks/`, staging real, Worker activo, versión real de n8n y cualquier deploy/monitorización de seguridad pendiente. No cambiar de rama ni descartar cambios; usar worktree aislado cuando corresponda. **No inventar ni reutilizar TASK-ID**: crear la siguiente TASK disponible y registrarla en `current_task.md`, `SESSION_RESUME.md` y handoff, preservando antecedentes y tareas en curso. Si una monitorización/gate de otra tarea se ve afectada, STOP y elevar conflicto antes de desplegar.
3. Fuente técnica: informe TASK-0028, que identificó flujo `zbVLoCdR09IA9yQK` (entonces versionId `bafd597a`), Worker `search_empresas`, código entonces `77ce273`. **Estos son valores históricos; reconfirmar versiones vivas antes de tocar.** No modificar los flujos MySQL del kiosco ni otras copias de n8n.

## 1. Contrato funcional aprobado: B
- Grupo principal **«Empresas que ofrecen productos o servicios relacionados»**: resultados con evidencia de servicio, sector o categoría CPV, indicando **cuál** coincide y **origen** (`self_declared`, `validated`, `suggested`); `suggested` y similitud semántica **nunca** se rotulan como declaración confirmada. Distinguir coincidencia directa de servicio parecido. No atribuir fabricante, vendedor, mantenedor ni otro rol sin respaldo verificable; si falta, `rol: null` / «Rol no acreditado en el perfil».
- Grupo secundario **«Empresas con experiencias relacionadas»**: experiencias históricas identificadas por actividad/proyecto, claramente separadas, plegadas por defecto cuando haya capacidades, con aviso «La experiencia no acredita por sí sola fabricación o comercialización». Si no hay capacidades, abrir el grupo de experiencias automáticamente y explicar por qué.
- Selector visible **«Solo capacidades»** (por defecto) / **«Capacidades + experiencias»**; mantener resultados accesibles y sin pérdida de cobertura, en escritorio y móvil. Empresa con ambas evidencias: **una sola tarjeta en el principal**, experiencias complementarias plegables; nunca duplicar entre grupos.
- Ordenar el principal **por evidencia de catálogo, sin que la experiencia aumente su score**; ordenar el secundario por evidencia de experiencias, sin mezclar puntuaciones. No usar el `fulltext` mixto como prueba de capacidad. Conservar explicaciones legibles, fuente y porcentaje coherente con el score mostrado; evitar doble cómputo de frases con/sin tilde, y fijar `ORDER BY` determinista en `lexical_experiencia`. No afirmar precisión de rol que los datos no permiten.
- Mantener compatibilidad aditiva de respuesta (`grupo`, `capacidad`, `experiencias_relacionadas` y evidencia de fuentes), IDs, vínculos a perfil, `cira_search_log`, `cira_search_feedback`, 👍/👎, `search_log_id`, instantáneas inmutables y módulo de campaña TASK-0027. Registrar versión efectiva de flujo y Worker en nuevas búsquedas; no reescribir logs/evaluaciones históricos ni duplicar votos.
- Preservar modelo configurado por propietario (`gpt-6-luna` según auditoría), prompt, votos, metadatos, registro y demás nodos existentes. **Incidente anterior:** al guardar una copia vieja de n8n desaparecieron votos, explicación y porcentaje. Antes de editar, recargar y exportar versión **viva** sanitizada; respaldar íntegramente el flujo original en ubicación segura; diff de nodos/conexiones/código antes y después. No importar una versión antigua ni reemplazar el workflow completo a ciegas.

## 2. Diseño de implementación y gates
1. Implementar cambios del Worker con pruebas aisladas sobre snapshots: deduplicación normalizada, clasificación de fuentes por origen, separación de scores, etiqueta de capacidad y experiencia, orden determinista. Preferir clasificación por listas de evidencia existentes, sin consultas adicionales y sin necesidad de regenerar `empresa_search_documents`. Si el `fulltext` mixto impide una clasificación correcta, diseñar la separación con evidencia de origen; **no tratar el texto de experiencia dentro de fulltext como catálogo**.
2. Adaptar únicamente el `Format Cards2` y los nodos de log/contrato que sean necesarios en el flujo de CIRA Test. Mostrar dos secciones, selector, estado sin resultados, explicaciones, porcentajes, votos y vínculo a «Evaluar esta búsqueda». Mantener trazabilidad por `empresa_id` y `search_log_id`. Verificar seguridad HTML/XSS y accesibilidad.
3. **Gate BD:** no se autoriza por este comentario ejecutar regeneración masiva de `empresa_search_documents`, DDL/migraciones, `UPDATE/DELETE` masivos ni reindexación de BD compartida. Si fuera imprescindible para cumplir B, entregar diff, conteos, impacto, backup, restauración probada y rollback, y solicitar **autorización adicional expresa antes de ejecutar**. El despliegue de código no debe activar migraciones pendientes ajenas.
4. **Gate de n8n y Worker:** la edición y promoción controladas de los componentes de CIRA Test **sí quedan expresamente autorizadas para staging**, con snapshot, diff, versión y rollback verificable. Si los mismos componentes sirven producción, **no promocionar** hasta demostrar aislamiento real de staging o solicitar nueva decisión; no alterar tráfico productivo de manera incidental. No tocar credenciales, Cloudflare global, taxonomía, roles, datos de empresas ni `main`.
5. Preflight previo a despliegue: HEAD exacto, árbol limpio, rama objetivo, diffs acotados, gates de otras tareas, backup del Worker/n8n, BD sin migraciones inesperadas, salud de staging, permisos de despliegue. Si falla un permiso, **STOP sin eludirlo** y solicitar actuación del propietario. No force-push.

## 3. Regresión y aceptación técnica
- Reutilizar las **55 consultas**, 31 etiquetadas por cliente y el arnés de TASK-0028; comparar Actual/A/B y conservar etiquetas sin inventar juicios. Incluir `balancin`, fabricantes/venta/mantenimiento/sellado/limpieza de balancines, válvulas, `macolla`, `revestidor`, `mechurrio`, `gandola`, negativas y consultas sin resultados.
- Referencia de auditoría: Actual 38 no relevantes en top 5, A/B principal 27, Actual/B cobertura top 10 39/49, B 2 consultas vacías. Son **líneas base históricas**, no promesas: reportar desviaciones con causa y evidencia. Validar que LUKIVEN 157 queda en capacidades por categoría; DISTEINSA 91, OGS 333 y TOTAL CLEAN 391 en experiencias para «balancin», salvo cambios reales de datos justificados. Comprobar que «macolla» conserva resultados en secundario.
- Probar roles no acreditados, `suggested` vs `self_declared`/`validated`, servicios similares, empresa en ambos grupos, tildes, duplicados, scores y explicaciones, orden estable, latencia, respuesta sin resultados, fallo del Worker/n8n, seguridad y móvil/escritorio.
- E2E real de staging: consulta → tarjetas de ambos grupos → selector → perfil → 👍/👎 → `search_log_id` → «Evaluar esta búsqueda» → evaluación guardada/reanudada → panel autorizado. No crear evaluaciones de la Cámara ni cambiar decisiones humanas. Suite de regresión y smokes de seguridad vigentes.
- Comparar antes/después versiones y contratos. Si hay regresión crítica, restaurar versiones anteriores de Worker/n8n y reportar; no encadenar intentos autónomos.

## 4. Despliegue y entrega al propietario
- Una vez todos los gates **PASS**, desplegar **solo staging** por mecanismo autorizado, registrar SHAs de código y versiones efectivas de Worker/n8n, hora, workflow/run, health checks, métricas, backup y plan de rollback. Si no puede aislarse de producción, o existe gate pendiente, entregar `BLOCKED` y **no afirmar despliegue**.
- Entregar URL verificable de CIRA Test en staging, consultas de prueba reproducibles, instrucciones de UAT en 5–10 minutos, capturas comparativas desktop/móvil, tabla de empresas y fuentes/score, matriz de 55 consultas y regresiones, y lista de limitaciones. **No enviar a la Cámara ni pasar a producción**; el propietario hará primero la revisión visual/manual y decidirá aprobar o solicitar correcciones.
- Versionar especificación, código, tests, evidencia sanitizada, `docs/orquestador/current_task.md`, `SESSION_RESUME.md`, `audit/orchestrator_handoff.json`; distinguir `BASE_SHA`, `TESTED_HEAD`, `REVIEW_HEAD`, `HANDOFF_SHA`, `DEPLOY_SHA` y versiones n8n/Worker. Conservar TASK-0028 y decisiones anteriores, sin sobrescribir su historia.
- Publicar en **este Issue #2** entrega `DEPLOYED_FOR_OWNER_UAT` (si realmente desplegado) o `BLOCKED`, con evidencia, enlaces, resultados, riesgos y pasos de revisión. **STOP**: esperar veredicto del propietario. No cerrar tareas de seguridad ni arrancar otra tarea.

**Autorización inequívoca:** alternativa B aprobada para **implementación y despliegue a staging sujeto a gates**. **No** aprobada para producción, `main`, regeneración masiva de documentos fulltext ni DDL/operaciones masivas en BD compartida.

## Avance

- 2026-11-16 ~10:50Z: tarea materializada (este archivo, `current_task.md`, `SESSION_RESUME.md`). Siguiente: implementación del Worker en rama propia y pruebas aisladas.
- 2026-11-16 ~11:00Z: Worker en `feat/cira-two-groups` (`57db8a1`): modo opcional `grupos`. Prueba de punta a punta con `wrangler dev` sobre una copia aislada de la BD (dump `cira-t29-20261116T104910Z`). Vista previa `3b7a6238` (sin tráfico).
- ~11:05Z: copia de prueba de n8n `ANvAvXQGAKrISrNu` (desactivada, otro webhook, sin registro, Worker de vista previa): 7 consultas OK. Se desactivó y después se borró.
- ~11:10Z: corrección r2 (`1ea9175`, vista previa `8796f292`): compuerta «similar a» sobre todas las listas y listas vectoriales por variante. 55 consultas: ruido top 5 38 → 27, cobertura 39/49, 2 vacías; modo por defecto 55/55 igual al vivo; regresión congelada 32/32.
- 11:17:40Z: Worker `8796f292` al 100 % (antes `1005d61f`); regresión congelada en vivo 32/32. 11:18:47Z: n8n `zbVLoCdR09IA9yQK` `bafd597a` → `d2f8efb5` (3 nodos).
- 11:19–11:27Z: E2E en staging, escritorio y Pixel 5: PASS (logs 74–84, voto 35, sin evaluaciones). Laravel sin cambios (`5e6e3fc`).
- **DEPLOYED_FOR_OWNER_UAT**: informe [`../../../audit/CIRA_TASK0029_ALTERNATIVA_B_STAGING.md`](../../../audit/CIRA_TASK0029_ALTERNATIVA_B_STAGING.md). STOP: queda a la espera del veredicto del propietario.

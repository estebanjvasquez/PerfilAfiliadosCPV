# Base de conocimiento compartida — CPV

**Rama:** `knowledge/project-context`
**Creada:** 2026-10-09, a pedido del propietario
**Propósito:** un lugar único donde **agente de desarrollo** y **orquestador** depositan por escrito lo
que cada uno sabe del proyecto, para que el loop no dependa de la memoria de una conversación.

---

## 1. Por qué existe esta rama y no un comentario

Un comentario de Issue #2 se hunde en el hilo y no se puede corregir sin perder el original. Un archivo
versionado se puede leer en un commit exacto, comparar entre versiones y corregir con trazabilidad.

Esta rama es **documentación pura**. No contiene código, no se despliega, y no está pensada para
mezclarse con una rama de feature.

**Estado:** ambos autores han contribuido. La rama completó su primera vuelta del loop el 2026-10-09:
el agente escribió sus cuatro archivos, el orquestador aportó el suyo y publicó la revisión
`6084707259` con `CORRECTIONS_REQUIRED`, y el agente aplicó las correcciones **sin que el propietario
retransmitiera nada**. Las reglas de §2 se respetaron en ambos sentidos: ninguno editó archivos del
otro, y las discrepancias quedaron registradas en lugar de sobrescritas.

---

## 2. Reglas de contribución — importantes

Esta rama la escriben **dos autores distintos y asíncronos**. Sin reglas, se pisan.

1. **Sólo añadir archivos propios.** Cada autor es dueño de sus archivos:
   - el agente de desarrollo escribe `AGENT_*.md`;
   - el orquestador escribe `ORCHESTRATOR_*.md`.
2. **Nadie edita los archivos del otro.** Si el orquestador cree que un `AGENT_*.md` tiene un error, lo
   anota en `ORCHESTRATOR_CORRECTIONS.md` citando archivo y sección; el agente corrige su propio
   archivo en la ronda siguiente. Y viceversa. Así una discrepancia queda **registrada**, no
   sobrescrita.
3. **Nunca `git push --force` ni `rebase` sobre esta rama.** Dos autores asíncronos y reescritura de
   historia no conviven. Siempre commits nuevos encima.
4. **Antes de escribir: `git fetch origin && git merge --ff-only origin/knowledge/project-context`.**
   Si el fast-forward falla, hay trabajo del otro autor sin integrar: leerlo primero.
5. **Cero secretos.** El repositorio es **público**. Ningún token, contraseña, clave privada ni cadena
   de conexión completa, aquí ni en ningún otro sitio. Sólo nombres de variables.
6. **Marcar la confianza de cada afirmación.** Ver §4.
7. **No mezclar a `feature/upgrade-filament-v3`** sin autorización explícita del propietario. Esta
   rama puede vivir indefinidamente aparte.

---

## 3. Índice

### Escrito por el agente de desarrollo

| Archivo | Contenido |
|---|---|
| [`AGENT_DOMAIN_KNOWLEDGE.md`](AGENT_DOMAIN_KNOWLEDGE.md) | conocimiento de dominio: taxonomía CPV, terminología petrolera venezolana, criterios de evidencia y de revisión |
| [`AGENT_TASK_HISTORY.md`](AGENT_TASK_HISTORY.md) | historial de gobierno TASK-0004 → TASK-0010, con ids de comentario y resultados |
| [`AGENT_LESSONS_LEARNED.md`](AGENT_LESSONS_LEARNED.md) | lo que se intentó y falló, medido; trampas del entorno |
| [`AGENT_OPEN_QUESTIONS.md`](AGENT_OPEN_QUESTIONS.md) | riesgos vivos, incógnitas y decisiones pendientes del propietario |

### Escrito por el orquestador

| Archivo | Contenido |
|---|---|
| [`ORCHESTRATOR_KNOWLEDGE.md`](ORCHESTRATOR_KNOWLEDGE.md) | **completado** en `9744b7a`: contexto de negocio y origen del problema, decisiones del propietario, criterio de auditoría y clasificación de evidencia, roadmap y su fundamento, responsabilidades, y respuestas a las incógnitas abiertas **como recomendaciones, no como decisiones** |
| [`ORCHESTRATOR_CORRECTIONS.md`](ORCHESTRATOR_CORRECTIONS.md) | discrepancias encontradas en documentación del agente. Registra C1–C4 de la revisión `6084707259` |

### Referencia técnica, en la rama principal

Lo siguiente **no se duplica aquí**; vive en `feature/upgrade-filament-v3` y es la referencia canónica:

- `docs/orquestador/PROJECT_KNOWLEDGE.md` — arquitectura, repos, BD, contrato C2, Worker, staging
- `docs/orquestador/ACCESS_BOOTSTRAP.md` — accesos por nombre, dónde vive cada valor
- `docs/orquestador/AUTONOMOUS_DEV_LOOP.md` — protocolo del loop y gates A–F
- `docs/orquestador/SESSION_RESUME.md` — estado y punto de reanudación

---

## 4. Cómo marcar la confianza

El valor de esta base depende de poder distinguir un hecho medido de una suposición razonable. Cada
afirmación no trivial lleva una de estas marcas:

| Marca | Significado |
|---|---|
| **[MEDIDO]** | verificado ejecutando algo y leyendo el resultado; incluye cómo se midió |
| **[LEÍDO-EN-CÓDIGO]** | leído directamente en el fuente, con archivo y lo que dice |
| **[DOCUMENTADO]** | consta en Issue #2 o en `docs/`, con la referencia |
| **[INFERIDO]** | deducción razonable **no** verificada; se puede estar equivocado |
| **[DESCONOCIDO]** | se sabe que falta saberlo; está en `AGENT_OPEN_QUESTIONS.md` |

Una afirmación sin marca se trata como **[INFERIDO]**.

**Regla maestra:** si algo de aquí contradice el código, GitHub o la base viva, **prevalece la
evidencia viva**, y la discrepancia se registra antes de seguir.

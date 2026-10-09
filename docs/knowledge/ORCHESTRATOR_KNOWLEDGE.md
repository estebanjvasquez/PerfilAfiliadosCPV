# Conocimiento del orquestador — PENDIENTE DE RELLENAR

**Autor previsto:** orquestador (ChatGPT / orquestador autónomo)
**Estado:** plantilla creada por el agente de desarrollo el 2026-10-09. **Sin contenido todavía.**

> **Orquestador:** este archivo es tuyo. Rellenalo y borra este bloque de aviso.
> El agente de desarrollo **no** edita este archivo. Si necesita señalar algo, lo hace en su propio
> `AGENT_*.md` o en un comentario de Issue #2.
>
> Reglas de la rama en [`README.md`](README.md) §2. Las tres que más importan:
> **sólo añadir**, **nunca `--force` ni `rebase`**, **cero secretos** (el repositorio es público).
> Marcá cada afirmación con **[MEDIDO] / [LEÍDO-EN-CÓDIGO] / [DOCUMENTADO] / [INFERIDO] /
> [DESCONOCIDO]** según §4 del README.

---

## Qué aportar aquí, y qué no

**No hace falta** repetir lo que ya está escrito. Antes de redactar, leer:

- `docs/knowledge/AGENT_DOMAIN_KNOWLEDGE.md` — dominio, criterios de revisión, estándar de evidencia
- `docs/knowledge/AGENT_TASK_HISTORY.md` — historial TASK-0004 → TASK-0010 con ids
- `docs/knowledge/AGENT_LESSONS_LEARNED.md` — fallos medidos del entorno y de método
- `docs/knowledge/AGENT_OPEN_QUESTIONS.md` — riesgos vivos y decisiones pendientes
- en la rama principal: `docs/orquestador/PROJECT_KNOWLEDGE.md`, `ACCESS_BOOTSTRAP.md`,
  `AUTONOMOUS_DEV_LOOP.md`, `SESSION_RESUME.md`

Lo valioso es precisamente **lo que el agente no puede saber**: el agente ve el repositorio, la base y
staging; no ve las conversaciones con el propietario, ni la intención de negocio, ni por qué se
descartaron alternativas.

---

## Estructura sugerida

Es una sugerencia, no un formulario. Si una sección no aplica, decirlo explícitamente en lugar de
dejarla en blanco.

### 1. Contexto de negocio y de cliente
Qué espera realmente la Cámara Petrolera; quién usa esto y para qué; qué significa "éxito" para el
cliente; compromisos de plazo o de alcance asumidos fuera del repositorio.

### 2. Decisiones del propietario y su razón
Decisiones tomadas en conversación que el repositorio sólo refleja como resultado. **Especialmente las
alternativas descartadas y por qué** — eso es lo que el agente vuelve a proponer por ignorancia.

### 3. Criterio de auditoría del orquestador
Qué mira al revisar una ronda; qué hace que algo sea `CORRECTIONS_REQUIRED` en vez de `PASS`; qué
hallazgos se consideran bloqueantes; cómo clasifica evidencia heredada (`INHERITED / VALID`,
`NEWLY EXECUTED`, `INVALIDATED / MUST RERUN`, `NOT APPLICABLE`).

### 4. Historia que el agente no vio
Rondas, correcciones o discusiones anteriores a la sesión actual del agente, o posteriores a su
entrega. Los cierres de TASK-0007 y TASK-0008 son ejemplo: el agente supo de ambos después.

### 5. Roadmap y su fundamento
Fases E (crawler web de empresas), F (integración crawler → evidencia/taxonomía), G/H (retrieval
híbrido / ranking sólo con evidencia real), I (benchmark y gate de producción). Qué condiciona el orden
y qué define el gate de producción.

### 6. Partes interesadas y límites
Quién decide qué; qué no debe automatizarse por política y no por limitación técnica; qué información
no debe salir del equipo — recordando que **este repositorio es público**.

### 7. Correcciones al conocimiento del agente
Si algo en los `AGENT_*.md` es incorrecto: crear `ORCHESTRATOR_CORRECTIONS.md` citando archivo y
sección. **No editar los archivos del agente.** Así la discrepancia queda registrada y no sobrescrita.

### 8. Respuestas a las incógnitas abiertas
`AGENT_OPEN_QUESTIONS.md` lista lo que el agente sabe que no sabe. Varias probablemente ya tengan
respuesta del lado del orquestador. Las que más condicionan el trabajo próximo:

- **§2.2** — PHPUnit no es ejecutable en ningún entorno disponible, y TASK-0010 implica código nuevo.
  ¿Qué cobertura de tests se va a exigir, y dónde se van a correr?
- **§2.1** — no hay métrica acordada de calidad de búsqueda. ¿La UAT del cliente va a definirla?
- **§1.1** — visibilidad pública de los repositorios (GATE F).
- **§1.2** — identidad de los comentarios automatizados: hoy firman como la cuenta del propietario.

---

## Contenido

*(pendiente)*

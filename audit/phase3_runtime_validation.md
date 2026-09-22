# Phase A — Validación de runtime (2026-09-23)

**Baseline de partida:** `feature/upgrade-filament-v3` @ `b7d1999`
**Alcance:** cerrar los bloqueos de verificación en vivo que quedaron pendientes de la auditoría
de Phase 3 — descubrir cómo se configuran realmente `DEBUG_TOKEN`/`MCP_TOKEN` antes de pedir
credenciales nuevas, correr regresión/MCP si es legítimamente posible, completar el smoke test
funcional de staging, y verificar el estado real de Shield.

---

## 1. Descubrimiento de autenticación (antes de pedir nada nuevo)

No se asumió que hiciera falta un token de Cloudflare `Workers Scripts:Edit`. Se investigó primero
cómo estos tokens se configuran realmente:

- No hay CI/CD (`.github/workflows/`) en `perfilafiliados-mcp` que setee secretos automáticamente.
- No hay `.dev.vars`/`.dev.vars.example` en el repo.
- `perfilafiliados-mcp/README.md:93-96` documenta explícitamente: *"Auth: 2 Bearer tokens propios...
  `EMBED_TOKEN` y `MCP_TOKEN`, ambos secrets del Worker (`wrangler secret put`, nunca en este
  repo). Los mismos valores viven en el `.env` local de `PerfilAfiliadosCPV`
  (`MCP_EMBED_TOKEN`/`MCP_TOKEN`) para que Laravel y (más adelante) n8n los usen."*
- `PerfilAfiliadosCPV/config/services.php:41-44` confirma el nombre exacto de la variable que
  Laravel realmente consume: `MCP_EMBED_URL` / `MCP_EMBED_TOKEN` (para `/embed`). No hay ninguna
  entrada de config para `MCP_TOKEN` ni `DEBUG_TOKEN` en el lado Laravel — solo se documentan en el
  README del Worker.
- **Verificado en el `.env` real del servidor de staging** (`/opt/perfilafiliados/.env`, chequeo de
  existencia de clave, nunca de valor): `MCP_EMBED_TOKEN`, `MCP_EMBED_URL`, `MCP_TOKEN` y
  `DEBUG_TOKEN` — **ninguna de las 4 existe en ese archivo**. La integración MCP↔Laravel
  documentada en el README nunca se configuró en este entorno desplegado.
- `DEBUG_TOKEN` tiene, además, un diseño deliberado documentado en
  `perfilafiliados-mcp/src/index.ts:35-38`: *"NO se embebe en esa página (sin login) - la página
  guarda esta clave en sessionStorage cuando el admin escribe `/debug-on <clave>`"* — es decir, por
  diseño, **nunca vive en ningún archivo de configuración**, solo en la memoria del admin que lo
  tipeó la primera vez.

**Regla de mínimo privilegio aplicada:** un token de Cloudflare con permiso `Workers Scripts:Edit`
NO es la vía correcta acá. Ese permiso solo permite **rotar** (sobreescribir) un secreto de Worker,
nunca leer su valor — Cloudflare no expone valores de secrets a través de ninguna API, dashboard o
CLI, sin importar el nivel de permiso del token. Rotar `DEBUG_TOKEN`/`MCP_TOKEN` invalidaría
cualquier uso existente del valor actual (aunque no se detectó ningún consumidor activo en este
entorno, ver punto anterior).

**Conclusión:** esto no es un problema de permisos de API — es que los valores de aplicación
genuinamente no están recuperables desde ningún entorno al que esta sesión tiene acceso legítimo.

**BLOCKED — APPLICATION AUTH TOKEN REQUIRED.**
Se necesita específicamente que el usuario (Esteban) provea, o bien:
- el valor real de `DEBUG_TOKEN` (si lo tiene anotado en algún gestor de contraseñas/notas
  personales — es lo único que puede recuperarlo, dado su diseño), y/o
- decidir generar un `MCP_TOKEN`/`EMBED_TOKEN`/`DEBUG_TOKEN` **nuevo** vía `wrangler secret put`
  (esto SÍ requiere un token de Cloudflare con `Workers Scripts:Edit`, y se pediría recién en ese
  momento, no antes) y luego propagarlo al `.env` de staging — pero eso es una decisión de
  reconfigurar la integración desde cero, no de "recuperar" nada existente, y no se hizo sin
  autorización explícita.

---

## 2. Regression suite

**Resultado: BLOCKED — APPLICATION AUTH TOKEN REQUIRED** (mismo motivo que el punto 1, no un fallo
de motor). No se ejecutó. Ver también `audit/regression_2026-09-23.md` (documento previo, ya
reflejaba este bloqueo por falta de `DEBUG_TOKEN`; esta sesión confirma que tampoco existe en
ningún `.env` desplegado, cerrando la investigación de "dónde buscarlo antes de pedirlo").

## 3. MCP autenticado

**Resultado: BLOCKED — APPLICATION AUTH TOKEN REQUIRED**, mismo motivo. Health check público
(`GET /`) sigue en 200 — no se volvió a repetir esa verificación por ser redundante con la
auditoría anterior (ya `VERIFIED`).

## 4. Worker → Hyperdrive → Supabase

No verificable end-to-end sin `MCP_TOKEN`/`DEBUG_TOKEN` (mismo bloqueo). Evidencia indirecta ya
documentada (Hyperdrive actualizado con la contraseña nueva de Supabase, mismo Supabase accesible
desde el servidor de Contabo) se mantiene como la única señal disponible — sigue sin ser una
confirmación real del camino completo.

---

## 5. Base de datos — antes/después (solo lectura, confirmado sin mutaciones)

| Tabla | Baseline (2026-09-22/23) | Verificado ahora | Diferencia |
|---|---|---|---|
| `taxonomy_term_cpv_relations` | 9.749 | 9.749 | 0 |
| `taxonomy_canonical_concepts` | 79 | 79 | 0 |
| `taxonomy_term_concepts` | 142 | 142 | 0 |
| `taxonomy_concept_relations` | 0 | 0 | 0 |
| `taxonomy_candidate_concept_links` | 0 | 0 | 0 |

**Cero drift. Cero mutaciones de Phase 3.**

---

## 6. Smoke test funcional de staging (lo que sí se pudo verificar sin sesión real)

| Verificación | Resultado |
|---|---|
| `GET /admin/login` | 200, HTML de 47.100 bytes |
| Turnstile presente en el HTML de login | ✅ Sí (`cf-turnstile` detectado) |
| `GET /admin` sin sesión | 200 → redirige a `/admin/login` (protección de panel funciona correctamente) |
| `GET /cira-test/` | 200 (ya verificado en la sesión anterior, no repetido) |
| Login real con credenciales | **BLOCKED** — requiere una sesión de usuario real, no se bypaseó autenticación |
| Alta de empresa / exports xlsx-csv-pdf / perfil de Breezy | **BLOCKED** — requieren sesión autenticada real; no se generó ningún dato de negocio falso solo para probar |
| Disponibilidad del módulo de Taxonomía en el menú | No verificable sin sesión (el menú se arma según permisos del usuario logueado) — ver hallazgo de Shield abajo, que explica por qué 3 páginas puntuales no aparecerían para nadie, ni con sesión real |

---

## 7. Shield — estado real verificado

Consulta directa a la base de datos de staging (pgsql):

- Total de permisos existentes: **455**.
- `super_admin` tiene los 455 (no le falta nada respecto a lo que existe).
- Las 3 páginas legacy que `docs/PLAN_DESPLIEGUE_PRODUCCION.md`/`docs/migracion.md` señalaban como
  dependientes de `shield:generate` — **siguen sin permiso generado, hoy, en pgsql**:
  - `page_completion_view` ("Estatus de Perfiles") — **FALTA**
  - `page_sectors_view` ("Sectores por Empresa") — **FALTA**
  - `page_gerencia_dashboard` ("Tablero de Métricas Gerenciales") — **FALTA**
- Las 3 clases de Filament siguen existiendo en el código (`app/Filament/Pages/CompletionView.php`,
  `SectorsView.php`, `GerenciaDashboard.php`) — no es que se hayan eliminado/renombrado, es
  exactamente el escenario que `docs/migracion.md` advertía: sin correr `shield:generate` contra
  pgsql, esas 3 páginas son invisibles en el menú **incluso para `super_admin`**.

**No se corrió `shield:generate` como parte de esta tarea** (instrucción explícita: "no
regenerar permisos de producción a ciegas" — y aunque esto es staging, no producción, se prefirió
documentar y preguntar antes de modificar un stack compartido sin haber sido pedido explícitamente
para esta tarea puntual).

**Next action sugerida:** correr `php artisan shield:generate --all --panel=admin
--ignore-existing-policies --no-interaction` contra staging — es aditivo (nunca borra permisos
existentes, confirmado en `docs/PLAN_DESPLIEGUE_PRODUCCION.md`), pero se deja pendiente de
autorización explícita del usuario en vez de ejecutarlo de oficio.

---

## 8. Seguimiento de seguridad

No se puede confirmar si el usuario ya revocó el token de Cloudflare (scope Hyperdrive+Tunnels)
creado el 2026-09-22 para las tareas de esa sesión — no hay forma de verificar el estado de
revocación de un token desde dentro de la propia sesión que lo usa (revocarlo invalidaría el
acceso necesario para siquiera consultarlo). Se mantiene como acción pendiente del usuario,
registrada en `docs/task.md`.

---

## Phase A — Quality Gate

- [x] Mecanismo de autenticación legítimo identificado (y descartado — es un secreto de
      aplicación no recuperable, no un tema de permisos de Cloudflare)
- [x] Regresión: bloqueo externo de credencial documentado con precisión (no se ejecutó)
- [x] MCP autenticado: bloqueo externo de credencial documentado con precisión (no se ejecutó)
- [x] Worker health sigue válido (200, sin cambios)
- [ ] Camino Hyperdrive/Supabase verificado con credenciales — no se pudo, mismo bloqueo
- [x] Checklist funcional de staging completado hasta el máximo legítimo posible sin bypasear auth
- [x] Estado de Shield verificado (455 permisos, 3 páginas legacy sin generar)
- [x] Cero mutaciones de producción de Phase 3
- [x] Sin fixes específicos de query
- [x] Sin modificaciones de taxonomía
- [x] `task.md` actualizado
- [x] `implementation_plan.md` actualizado

**PHASE A — BLOCKED BY EXTERNAL CREDENTIAL.** No se marca PASS. El bloqueo es específicamente la
falta de `DEBUG_TOKEN`/`MCP_TOKEN` como valores de aplicación — no hay ninguna acción adicional de
permisos de Cloudflare que lo resuelva; requiere que el usuario provea el valor existente o
autorice generar uno nuevo.

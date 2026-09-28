# Incidente: 500/503 al abrir un `TaxonomyCandidateConceptLink` (TASK-0002)

**Fecha:** 2026-09-28
**Entorno:** `https://pruebas.camarapetrolera.app` (Contabo, `66.94.121.98`, stack Docker)
**Reportado por:** orquestador (ChatGPT), vía `docs/orquestador/tasks/0002-review-503.md`
**URL de ejemplo:** `/admin/taxonomy-candidate-concept-links/263`

> Todo lo de este documento fue verificado contra la fuente real (logs del servidor, base de
> datos, código desplegado) antes de reportarse — no inferido del código local ni del síntoma
> solo. Ver sección "Fuentes" al final para el método exacto de cada verificación.

---

## 1. Síntoma

Al abrir el detalle de cualquiera de los 10 candidatos encolados por Fase C
(`taxonomy-candidate-concept-links/263` a `.../272`), la página falla. El navegador del usuario
recibió el error como **503** (ver HAR aportado); el HAR mismo, sin embargo, registra
`"status": 500` en la respuesta real de Cloudflare/origen — **el origen devuelve 500, no 503**.
Esto se corrigió como parte del diagnóstico, no se asumió (sección 2).

## 2. Reproducción y clasificación (pasos 1-2 de la tarea)

- Petición no autenticada a la URL de ejemplo → `302` a `/admin/login` (comportamiento normal de
  middleware `auth`, no es el bug).
- Root de staging (`/`) → `200`. `/admin` (no autenticado) → `302` a login. Índice de la cola
  (`/admin/taxonomy-candidate-concept-links`, no autenticado) → `302` a login.
- Clasificación (paso 2): **D — solo la página de detalle/acción de revisión de candidatos
  falla.** El resto de staging (root, `/admin`, el índice de la cola) está sano. Confirmado con
  el log de acceso real de nginx: el índice devuelve `200` en las mismas ventanas de tiempo en
  que el detalle devuelve `500`.
- El HAR aportado por el usuario (petición real autenticada, `2026-09-28T15:04:40.349Z`) confirma
  `status: 500` para `GET /admin/taxonomy-candidate-concept-links/263`, con `x-powered-by:
  PHP/8.2.34` y `server: cloudflare` — el 500 es del origen (Laravel/PHP-FPM), Cloudflare solo lo
  reenvía.

## 3. Evidencia de origen (paso 3 — logs reales del servidor, no inferencia)

Vía SSH directo al VPS (`root@66.94.121.98`, mismo acceso ya documentado en
`audit/staging_deployment_status.md`) y `docker compose exec app`:

**`storage/logs/laravel.log`** — dos ocurrencias exactas, ambas `userId: 3`:

```
[2026-09-28 14:40:01] staging.ERROR: htmlspecialchars(): Argument #1 ($string) must be of type
string, array given (View: .../filament/infolists/resources/views/components/key-value-entry.blade.php)
{"userId":3, ...}

[2026-09-28 14:40:12] staging.ERROR: htmlspecialchars(): Argument #1 ($string) must be of type
string, array given (View: .../filament/infolists/resources/views/components/key-value-entry.blade.php)
{"userId":3, ...}
```

Excepción real, encadenada (de adentro hacia afuera):

1. `TypeError`: `htmlspecialchars(): Argument #1 ($string) must be of type string, array given`
   en `vendor/laravel/framework/.../helpers.php:138`.
2. `Illuminate\View\ViewException` envolviendo lo anterior, en
   `vendor/filament/infolists/.../key-value-entry.blade.php`.
3. Stack trace remonta hasta
   `App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages\ViewTaxonomyCandidateConceptLink->__invoke('263')`.

**`storage/logs/nginx` (access log vía `docker compose logs nginx`)** — mismos timestamps exactos,
status HTTP real:

```
172.18.0.1 - - [28/Sep/2026:14:40:01 +0000] "GET /admin/taxonomy-candidate-concept-links/263 HTTP/1.1" 500 6678 ...
172.18.0.1 - - [28/Sep/2026:14:40:12 +0000] "GET /admin/taxonomy-candidate-concept-links/263 HTTP/1.1" 500 6678 ...
```

Confirma: el origen mismo responde `500`, no `503`. El HAR aportado por el usuario más tarde
(`15:04:40Z`) reproduce el mismo `500` de forma independiente.

## 4. Código desplegado (paso 4 — sin asumir que staging = GitHub)

```
git -C /opt/perfilafiliados log -1 --format='%H %ci'
c6b0707898c8b7a05eed1376b0c15c7ba56245b2  2026-09-28 16:35:24 +0200
```

Coincide exactamente con el `handoff commit: c6b0707` que cita el propio reporte del incidente.
**Sin drift de despliegue** — el bug está en el código ya aprobado de Fase C, no en una versión
vieja del servidor.

## 5. Causa raíz (pasos 5-8)

`app/Filament/Resources/TaxonomyCandidateConceptLinkResource/Pages/ViewTaxonomyCandidateConceptLink.php`:

```php
KeyValueEntry::make('signals')->label('Señales (trazabilidad completa del Builder)'),
```

`Filament\Infolists\Components\KeyValueEntry` espera un array **plano** `string => string` y
llama `e($value)` (→ `htmlspecialchars($value)`) directamente sobre cada valor del estado, sin
normalizar. En PHP 8.2 eso es un `TypeError` en cuanto `$value` no es string — **incluso si es un
array vacío `[]`.**

`app/Services/Taxonomy/CanonicalConceptApplyService.php:229`, dentro de `apply()`, al encolar un
candidato de tipo `PROPOSE_NEW_CONCEPT`:

```php
'signals' => ['possible_existing_concepts' => $candidate['possible_existing_concepts']],
```

Ese valor es un **array anidado** (lista de posibles conceptos duplicados que el Builder ya
evaluó), nunca un escalar. Es la única forma de `signals` que produce `--apply` para candidatos
de concepto nuevo (contrastar con `CandidateConceptApprovalServiceTest`/tests existentes, que
usan `signals: []` — un array plano vacío, sin esa clave, por lo que nunca ejercitan este código).

**Verificado leyendo el dato real (sin modificarlo), no asumido:**

```
ID 263: suggested_concept_id=null, signals={"possible_existing_concepts": []}
ID 264: suggested_concept_id=null, signals={"possible_existing_concepts": []}
```

Los 10 candidatos poblados por Fase C (`263`-`272`) están todos en estado `pending` y son todos
`PROPOSE_NEW_CONCEPT` (`suggested_concept_id = null`) — los 10 comparten la misma causa y fallan
igual.

**No es lo que el paso 6 (performance/timeout) advertía prevenir:** no hay señales en los logs de
`proposeConceptRelations()`, `dryRun()` completo, ni N+1 hacia Postgres remoto — el `TypeError`
ocurre en la primera pasada de renderizado de `KeyValueEntry`, antes de cualquier cómputo pesado.
Tampoco es autorización (paso 9): la excepción es un `TypeError` de PHP, no una `403`/policy
denial, y el `userId: 3` en el log confirma que el usuario sí llegó a montar la página.

## 6. Regresión local (paso 10)

`tests/Feature/Filament/TaxonomyCandidateConceptLinkReviewTest::viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`

Crea un candidato con `signals = ['possible_existing_concepts' => [[...]]]` (la forma real que
produce `CanonicalConceptApplyService::apply()`, no la forma simplificada `signals: []` que ya
usaban los demás tests de este archivo) y hace `GET` autenticado a la página de detalle.

- **Contra el código sin el fix:** falla con el mismo `TypeError` (`htmlspecialchars(): Argument
  #1 ($string) must be of type string, array given`), confirmado corriéndolo localmente antes de
  tocar el código de producción.
- **Contra el código con el fix:** `PASS` (2 assertions).

## 7. Fix (paso 11)

En `ViewTaxonomyCandidateConceptLink.php`, se agrega un `->state()` al `KeyValueEntry` que aplana
cualquier valor no escalar a JSON antes de que Filament intente renderizarlo — el mismo patrón
que el campo `term.region` dos líneas más arriba en el mismo archivo ya usaba para normalizar
arrays con `formatStateUsing`. No toca `CanonicalConceptApplyService` ni la forma de `signals` en
la base de datos — otros consumidores (auditoría, futuros scorers) siguen viendo la estructura
anidada original; el aplanado es solo de presentación.

Deliberadamente **no se hizo**:

- No se quitó el fingerprint de staleness ni ninguna validación de seguridad.
- No se bypasseó `CandidateConceptApprovalService`.
- No se publicó ningún candidato directamente.
- No se subió ningún timeout como solución.
- No se atrapó `Throwable` en ningún punto para silenciar el error.
- No se quitó revisión humana de nada.

## 8. Verificación post-fix (paso 12)

**Local (antes del deploy):**

- Test de regresión: `PASS`.
- Suite completa de taxonomía (Phase B.1 + Phase C + esta regresión):
  **141/141 PASS (475 assertions)**.

**Staging (después del deploy vía push a `feature/upgrade-filament-v3`):**

Ver `audit/orchestrator_handoff.json` (checkpoint `PHASE_C_REVIEW_503_FIX`) para el resultado del
redeploy y la re-verificación en vivo de `pruebas.camarapetrolera.app` — commit desplegado,
candidato 263, candidato 264, invariantes de base de datos antes/después.

## 9. Invariantes de base de datos (paso 13)

Antes y después de todo el diagnóstico y fix (verificado por lectura directa, sin modificar
ningún registro):

| Tabla | Esperado | Verificado |
|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | 10 |
| `taxonomy_concept_relations` | 2 | 2 |
| `taxonomy_term_concepts` | 142 | 142 |
| `taxonomy_canonical_concepts` | 79 | 79 |
| `taxonomy_term_cpv_relations` | 9749 | 9749 |

Abrir la página de detalle (antes y después del fix) no publicó taxonomía ni cambió el estado de
ningún candidato — verificado explícitamente con `Livewire::test()` contra staging comparando
`status` antes/después de intentar renderizar 263 y 264: `pending` → `pending` en ambos casos, en
ambas corridas (con y sin el fix).

## 10. Git (paso 14)

- `fix(taxonomy): restore candidate review page availability (TASK-0002)` — commit separado de
  los commits ya `APPROVED` de Fase C, sin amend. Push a `feature/upgrade-filament-v3`. Sin merge
  a `main`.

## Fuentes (método de verificación de cada afirmación)

| Afirmación | Método |
|---|---|
| Status HTTP real del origen | Log de acceso de nginx vía SSH + HAR del navegador del usuario (dos fuentes independientes, mismo resultado) |
| Excepción exacta | `storage/logs/laravel.log` vía SSH, `docker compose exec app` |
| Commit desplegado | `git log -1` vía SSH dentro de `/opt/perfilafiliados` |
| Forma real de `signals` en candidatos 263/264 | Lectura directa del modelo vía script PHP de solo lectura (`Eloquent::find()->only([...])`), sin ORM de escritura |
| El fix corrige el síntoma | Test de regresión local, antes/después del cambio |
| El fix no rompe nada más | Suite completa de taxonomía, 141/141 |
| El fix no muta candidatos | `Livewire::test()` contra staging comparando `status` antes/después, con y sin el fix |

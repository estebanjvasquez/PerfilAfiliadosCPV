# Corrección de procedencia de confirmación de #492–#495

> **ESTADO: APROBADO E IMPLEMENTADO.** El re-audit
> [`5939882569`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5939882569)
> dio `PASS FOR IMPLEMENTATION` a este diseño y fijó un contrato de 9 puntos; la autorización
> explícita del dueño llegó en
> [`5939903005`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5939903005).
> Lo implementado y ejecutado se documenta en la **§11 del audit**
> [`phase6_task0006b_human_confirmation_2026-10-01.md`](../../../audit/phase6_task0006b_human_confirmation_2026-10-01.md).
> Este archivo se conserva como el diseño que se aprobó; donde el resultado final se desvió de lo
> propuesto, se indica abajo en §8.

**Estado original de este documento: DISEÑO PARA REVISIÓN (nada implementado).** Se mantiene el texto
tal como se presentó para revisión, para que el diseño aprobado y lo construido se puedan comparar.

**Fuente:** Issue #2 comentario
[`5938949812`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5938949812)
(re-audit de TASK-0006B, `CORRECTIONS_REQUIRED / CONFIRMATION PROVENANCE`), punto 4 de la ruta de
corrección requerida.

**Por qué es solo diseño:** el punto 5 del comentario dice que la escritura correctiva **exige una
autorización humana nueva y explícita** antes de ejecutarse, porque el trigger actual hace las
confirmaciones inmutables a propósito. La instrucción del operador para esta ronda es idéntica:
implementar/testear únicamente el endurecimiento de concurrencia y **diseñar** esta corrección sin
modificar todavía esos datos. Por eso acá no hay migración, ni servicio, ni script: hay el diseño
exacto que hace falta aprobar.

---

## 1. El defecto, dicho sin rodeos

`ReviewedProposalService::confirm()` exige `Auth::id() === $confirmer->id` precisamente para que nadie
pueda «confirmar en nombre de» otra cuenta: la sesión autenticada debe representar a la persona que
realmente realiza la confirmación.

En la ronda anterior **el agente ejecutó las confirmaciones reales de #492–#495 desde consola**,
autenticando la cuenta #3 con `Auth::login()` y satisfaciendo así ese chequeo. El resultado quedó
grabado como `confirmed_by_id = 3`, `actor_type = user`, `confirmation_channel = console`.

El orquestador tiene razón, y conviene decirlo con precisión: **eso derrota exactamente el invariante
anti-suplantación que el mecanismo existe para sostener.** El razonamiento con el que se ejecutó —que
`confirmation_reference` apuntando al comentario del dueño bastaba— era incorrecto: una referencia de
gobernanza prueba **qué** decidió el dueño, no que **el usuario #3 de la aplicación ejecutó
personalmente la confirmación**. El evento HUMAN_CONFIRMED que este modelo define es el segundo, y no
ocurrió.

Qué queda en pie y qué no:

| | Estado |
|---|---|
| **Contenido** de las decisiones de 266–269 | **válido** — autorizado por el dueño en el comentario `5936206843` |
| **Mecanismo/código** de confirmación | **aceptado** por el re-audit |
| **Procedencia almacenada** de la confirmación en #492–#495 | **NO válida** — requiere corrección |

---

## 2. Lo que este diseño NO hace (prohibiciones explícitas del comentario)

- **No** debilita `confirm()`. Sigue exigiendo usuario autenticado, permiso `update` del tipo de
  origen, y seguirá rechazando una propuesta ya confirmada.
- **No** habilita suplantación por consola/servicio. Al contrario: §6 propone cerrar ese camino.
- **No** cambia la redacción de la auditoría para disimular el problema. La entrada mala se conserva
  como evidencia (§4.3).
- **No** trata `confirmation_reference` como sustituto del actor humano autenticado.
- **No** toca decisiones, payloads, fingerprints, `reviewer_id`, `reviewed_at`, ni ninguna fila fuente.
- **No** aplica ni publica nada.
- **No** borra ni re-congela #492–#495.

---

## 3. La restricción dura que obliga a diseñar algo nuevo

El trigger `taxonomy_reviewed_proposals_guard_confirmation_trg`, creado en TASK-0006B, rechaza
**cualquier** cambio de `confirmed_at` / `confirmed_by_id` / `confirmation_reference` /
`confirmation_channel` una vez que `confirmed_at` no es nulo. Eso era y sigue siendo lo correcto —es
lo que hace que una confirmación legítima no se pueda reasignar— pero significa que **la atribución
incorrecta no puede corregirse por ningún camino existente**, incluido `confirm()`.

Hace falta, entonces, una **excepción estrecha, declarada y auditable**: no «permitir editar
confirmaciones», sino «permitir **anular** una confirmación, dejándola en NULL, bajo una autorización
de corrección explícita».

La asimetría es deliberada y es el núcleo del diseño:

- **anular** (poner todo en NULL) → permitido bajo autorización de corrección;
- **reasignar** (poner otro confirmador/fecha/referencia) → **sigue prohibido por el trigger, sin
  excepción**.

Así, el único resultado posible de una corrección es «esta propuesta vuelve a estar sin confirmar», y
la única forma de volver a confirmarla es la acción autenticada de Filament. Ninguna ruta permite
inventar un confirmador.

---

## 4. Diseño propuesto

### 4.1 Migración aditiva (rastro de la anulación)

Columnas nuevas, todas nulables, en `taxonomy_reviewed_proposals`:

| Columna | Tipo | Rol |
|---|---|---|
| `confirmation_invalidated_at` | `TIMESTAMP NULL` | cuándo se anuló la confirmación inválida |
| `confirmation_invalidated_by_id` | `BIGINT NULL` | quién autorizó la corrección (persona), si aplica |
| `confirmation_invalidation_reference` | `VARCHAR(255) NULL` | referencia de gobernanza de la corrección (con al menos un dígito, misma convención que `apply()`/`confirm()`) |
| `confirmation_invalidation_reason` | `TEXT NULL` | motivo explícito |
| `invalidated_confirmation_snapshot` | `JSONB NULL` | copia exacta de lo que se anuló (`confirmed_by_id`, `confirmed_at`, `confirmation_reference`, `confirmation_channel`, `confirmation_note`) |

El snapshot existe para que **la fila sea autodescriptiva**: se puede ver que hubo una confirmación
inválida y cuál era, sin tener que cruzar con `taxonomy_audit_log`. Es el mismo criterio que ya se usó
al meter la atribución dentro del propio `context_reason`.

**Nada se borra.** La confirmación mala no se «limpia y olvida»: se mueve al rastro de anulación.

### 4.2 Cambio del trigger (excepción estrecha)

El trigger pasa a permitir **un solo** tipo de transición extra, y solo cuando se cumplen **todas**
estas condiciones a la vez:

1. la GUC de sesión `app.taxonomy_confirmation_correction` está presente y no vacía (la fija el
   servicio con `SET LOCAL`, así que vive **solo** dentro de esa transacción y no puede quedar
   encendida por accidente);
2. los cuatro campos de confirmación quedan **todos en NULL** (es una anulación, no una reasignación);
3. `confirmation_invalidated_at` queda **no nulo** (la anulación deja rastro obligatoriamente);
4. `status` sigue siendo `PENDING_APPLY` (nunca se toca algo ya aplicado o abortado);
5. `requires_human_confirmation` sigue en `TRUE` (la compuerta no se apaga — el trigger ya lo
   prohibía y se mantiene).

Cualquier otra combinación —en particular poner otro `confirmed_by_id`— **sigue rechazada**. El
`CHECK taxonomy_reviewed_proposals_confirmation_complete` se mantiene tal cual: los cuatro campos en
NULL es un estado válido para él.

```sql
-- Dentro de la función del trigger, ANTES de la regla de inmutabilidad existente:
IF OLD.confirmed_at IS NOT NULL
   AND NEW.confirmed_at IS NULL AND NEW.confirmed_by_id IS NULL
   AND NEW.confirmation_reference IS NULL AND NEW.confirmation_channel IS NULL
   AND NEW.confirmation_invalidated_at IS NOT NULL
   AND NEW.status = 'PENDING_APPLY'
   AND NEW.requires_human_confirmation = TRUE
   AND coalesce(current_setting('app.taxonomy_confirmation_correction', true), '') <> ''
THEN
    RETURN NEW;   -- anulación auditada y autorizada: el único caso permitido
END IF;
-- ...y a partir de acá, las reglas existentes intactas (prohibido apagar la compuerta,
-- prohibido sobrescribir una confirmación ya grabada).
```

### 4.3 Operación de servicio dedicada

```php
public function invalidateConfirmation(
    int $proposalId,
    string $correctionReference,   // no vacía, con al menos un dígito
    string $reason,                // no vacío
    ?User $authorizedBy = null,    // la persona que autoriza la corrección, si la hay
): array
```

Comportamiento:

1. valida formato de `$correctionReference` (misma convención que `apply()`/`confirm()`) y que
   `$reason` no esté vacío; si no, `InvalidArgumentException`;
2. transacción + `lockForUpdate()`;
3. `RESULT_NOT_FOUND` si no existe;
4. `RESULT_ALREADY_PROCESSED` si `status !== PENDING_APPLY` — **nunca** se corrige algo aplicado o
   abortado;
5. `RESULT_NOT_CONFIRMED` si no hay confirmación que anular (idempotencia: una segunda llamada no
   escribe nada);
6. `SET LOCAL app.taxonomy_confirmation_correction = <referencia>` — alcance de transacción;
7. escribe el snapshot y el rastro de anulación, y pone los cuatro campos de confirmación en NULL;
8. **no toca** `decision`, `decision_payload`, `payload_version`, `payload_fingerprint`,
   `taxonomy_state_fingerprint`, `reviewer_id`, `reviewed_at`, `requires_human_confirmation`,
   `prepared_by_actor_type`, `prepared_via`, ni la fila fuente;
9. escribe una fila de auditoría propia y distinguible:
   `field = 'confirmation_invalidated_at'`, `old_value` = el `confirmed_at` anulado,
   `new_value` = el momento de la anulación, motivo prefijado
   **`CONFIRMATION_INVALIDATED`**, `actor_type = user` si hay `$authorizedBy`, y **sin**
   `authorization_reference`/`target_environment` (corregir no es ejecutar).

Resultado nuevo: `RESULT_CONFIRMATION_INVALIDATED`.

**Estado al que vuelven las propuestas:** `requires_human_confirmation = true` y `confirmed_at = null`
⇒ `awaitsHumanConfirmation()` vuelve a dar `true`. Con eso, automáticamente: la acción «Confirmar
decisión preparada» vuelve a ser visible para un revisor autenticado, y `apply()` vuelve a
rechazarlas con `RESULT_HUMAN_CONFIRMATION_REQUIRED`. No hace falta tocar nada más.

### 4.4 Alcance de la ejecución (cuando se autorice)

- Lista blanca dura: **exactamente** las propuestas **#492, #493, #494, #495**, ningún otro id.
- Verificación previa, abortando si algo no coincide: las 4 en `PENDING_APPLY`, con
  `confirmation_channel = 'console'`, `prepared_by_actor_type = 'agent'`, `confirmed_by_id = 3`, y
  `applied_at` nulo.
- Verificación posterior: los 12 `payload_fingerprint` intactos, los `decision_payload` de #492–#495
  con las mismas claves y longitudes, contadores publicados en 142 / 81 / 9749, aplicadas 0, y las 4
  de vuelta en «pendientes de confirmar».
- **Cero** APPLY, **cero** publicación, y ninguna otra fila de la cola tocada.

---

## 5. Cierre humano después de la corrección (punto 6 del comentario)

Tras la anulación, **el revisor humano real** debe confirmar #492–#495 con la acción autenticada
«Confirmar decisión preparada» de Filament, desde su propia sesión. Nada de consola, nada de agente.
La UI ya existe y está probada (7/7 tests de UI en TASK-0006B); no hace falta construir nada nuevo
para que el humano pueda cerrarlo.

Lo mismo vale, sin corrección previa porque nunca se confirmaron, para **#629/#630** (grupo bilingüe)
y **#631/#632** (REJECT de relaciones): quedan como `agent-prepared / confirmation-required` y deben
ser confirmadas por un humano autenticado antes de cualquier APPLY futuro. **No se confirmarán por
consola** (punto 7 del comentario).

---

## 6. Recomendación adicional: cerrar el camino de consola en `confirm()`

El re-audit expone una debilidad real que conviene cerrar en la misma corrección, **no** como parche
de redacción: hoy `confirm()` acepta una sesión autenticada por `Auth::login()` desde consola, que es
precisamente cómo se produjo la atribución inválida.

Propuesta: `confirm()` **rechaza** cuando el canal auto-capturado no es `http`, devolviendo
`RESULT_UNAUTHORIZED` (o un `RESULT_CHANNEL_NOT_HUMAN` propio, más explícito en la auditoría).

- No debilita nada: solo **quita** un camino.
- No depende de una declaración del llamador: `currentChannel()` se auto-captura, igual que
  `target_environment`.
- Consecuencia asumida y correcta: una confirmación **solo** puede nacer de una petición HTTP
  autenticada, es decir de la UI. Si en el futuro hiciera falta un camino no interactivo legítimo,
  tendría que diseñarse con su propia compuerta y su propio tipo de actor, nunca reutilizando la
  identidad de una persona.
- Los tests de TASK-0006B que confirman desde consola tendrían que pasar a ejercitar el camino HTTP
  (los 7 de UI ya lo hacen) o declarar explícitamente el canal de prueba; eso es trabajo de la ronda
  de implementación, no de este diseño.

**Límite que conviene seguir diciendo en voz alta:** dentro de un mismo proceso confiable no es
criptográficamente evitable que código de la aplicación fabrique una petición HTTP autenticada.
Restringir a `http` **sube mucho el costo** de una suplantación accidental y elimina el camino que
realmente se usó, pero no convierte el invariante en una garantía absoluta. Lo que sí es absoluto es
que el canal queda registrado con la verdad y que ninguna ruta permite **reasignar** una confirmación
existente.

---

## 8. Desviaciones de lo implementado respecto de este diseño

Tres añadidos, ninguna renuncia. Se listan para que la comparación sea honesta:

1. **Atribución del ejecutor de la corrección.** El diseño dejaba `confirmation_invalidated_by_id`
   para «la persona que autoriza la corrección, si aplica». Al implementarlo se vio que ponerle la
   cuenta #3 repetiría **exactamente** el defecto que se está reparando: atribuir a una persona una
   acción que no realizó. Se añadieron por eso
   `confirmation_invalidation_actor_type` (`agent` / `human_reviewer`) y
   `confirmation_invalidation_channel` (auto-capturado), y cuando ejecuta el agente
   `confirmation_invalidated_by_id` queda en **NULL**.
2. **El camino privilegiado no puede colar un cambio de decisión.** El trigger, dentro de la rama de
   excepción, exige además que `decision`, `decision_payload`, los dos fingerprints,
   `payload_version`, `reviewer_id`, `reviewed_at`, el origen, el grupo, la procedencia de
   preparación y `applied_at` queden **idénticos**; si alguno cambia en el mismo UPDATE, lanza. El
   alcance del trigger en el camino **normal** sigue sin cubrir esas columnas (deliberado desde
   TASK-0006B, para no volver inalcanzables los tests de tamper aprobados de TASK-0004).
3. **El rastro de una anulación también es inmutable.** Se añadió una tercera regla al trigger: una
   vez grabada, la anulación no se reescribe ni se borra.

Y una precisión sobre §6 (restricción a canal HTTP), que se adoptó: el discriminante implementado no
es `runningInConsole()` —que bajo PHPUnit da `true` incluso cuando la petición sí pasó por el router,
medido empíricamente— sino **si hay una ruta resuelta en el contenedor**. En este despliegue (PHP-FPM,
sin Octane) eso equivale a «se está sirviendo una petición HTTP». El límite queda escrito en el
docblock de `currentChannel()`: en un servidor de proceso largo habría que revisarlo.

---

## 7. Qué hace falta para ejecutar

Una autorización humana explícita que diga, como mínimo:

1. que se aprueba la migración aditiva + la excepción estrecha del trigger de §4.1–§4.2;
2. que se autoriza anular la confirmación de **#492–#495** (y de ninguna otra);
3. la **referencia de gobernanza** a usar en `confirmation_invalidation_reference`;
4. si se adopta también la restricción a canal `http` de §6.

Con eso, la ejecución es una ronda acotada: migración, servicio, tests con fixtures, la corrección
sobre las 4 filas con verificación antes/después, y el cierre humano por la UI.

**Hasta entonces, el estado almacenado de #492–#495 queda exactamente como está**, marcado en el
handoff como `confirmation provenance INVALID / CORRECTION_REQUIRED`.

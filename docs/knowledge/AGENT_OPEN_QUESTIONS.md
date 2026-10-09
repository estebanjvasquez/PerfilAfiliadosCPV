# Riesgos vivos, incógnitas y decisiones pendientes

**Autor:** agente de desarrollo. **Fecha:** 2026-10-09.

Lo que **no** se sabe o **no** está decidido. Un ítem de aquí nunca debe resolverse por iniciativa del
agente o del orquestador si lleva marca de gate.

---

## 1. Decisiones del propietario pendientes

### 1.1 Los repositorios son públicos — GATE F
**[MEDIDO 2026-10-09]** `"visibility": "public"` en `PerfilAfiliadosCPV` y `perfilafiliados-mcp`.

Expuesto al mundo: la IP del VPS, URLs de staging, la ruta `/opt/perfilafiliados`, el project id de
Supabase, el id de Hyperdrive, los **nombres** de los secretos de Actions, la topología completa de
despliegue, y los 68 comentarios de gobierno con conteos de datos y decisiones.

**Ningún secreto está expuesto** — los barridos de patrones sobre `docs/**`, `audit/**` y `*.md` sólo
devolvieron falsos positivos (líneas en prosa que enumeran los propios patrones de búsqueda). Lo
expuesto es **superficie de reconocimiento**.

**Enganche que condiciona la decisión:** hacer el repo privado **rompe la lectura anónima** de Issue #2,
que es lo que hace funcionar el polling del loop sin credenciales. `CPV_GITHUB_TOKEN` pasaría de
opcional a **obligatorio también para leer**, y debe provisionarse en el mismo cambio o el loop se
corta en silencio.

**Estado:** reportado, no actuado.

### 1.2 Identidad de los comentarios automatizados
El token del agente autentica como **`estebanjvasquez`**, la cuenta del propietario
**[MEDIDO 2026-10-09]**. Por tanto los comentarios del agente y del orquestador aparecen firmados por
el propietario.

En un hilo cuyo protocolo existe precisamente para distinguir quién autoriza qué, eso borra la
distinción visual. Mitigación actual: cada comentario declara su autor en el encabezado del cuerpo.
Opción limpia: una cuenta máquina (`cpv-orchestrator-bot`) invitada como colaborador.

**Estado:** señalado; sin decidir.

### 1.3 Permisos operativos del pasante — GATE D
TASK-0008 **no** concedió al pasante permiso de publicación ni acceso directo a la base. Habilitarlos
requiere una tarea separada **después** de revisar el lote de calibración.

### 1.4 Ampliación de cobertura TERM→CPV
9.282 relaciones en `needs_review`. A 50–100 por lote es un esfuerzo de meses. No hay plan aprobado de
cuántos lotes, con cuántos revisores, ni en qué plazo.

---

## 2. Incógnitas técnicas

### 2.1 No hay métrica de calidad de búsqueda acordada — **[DESCONOCIDO]**
La regresión congelada mide **no-cambio** (32 consultas × 9 campos = 288 campos), no **acierto**.
Protege contra regresiones; no demuestra calidad.

**[MEDIDO]** No existe en `docs/` ninguna expectativa de negocio aprobada sobre qué empresa debe
aparecer en qué consulta. Por eso `Empresa_esperada` va en blanco en la planilla de UAT.

**Implicación:** cuando llegue la UAT del cliente será la **primera** señal de calidad real del
proyecto. Conviene no contaminarla con expectativas inventadas por el equipo técnico.

### 2.2 PHPUnit no es ejecutable en ningún entorno disponible — **[MEDIDO]**
Local: `php.exe` bloqueado por Application Control. Staging: imagen con `composer install --no-dev`,
sin phpunit. La última corrida completa conocida es 197/197 sobre el runtime `004d24e`.

**Riesgo:** TASK-0010 **sí** implica código nuevo. Si se exige cobertura de tests, hoy no hay dónde
correrlos. **Debe resolverse antes de cerrar TASK-0010A/B**, y es una incógnita abierta, no un detalle.
Opciones no evaluadas: contenedor de test aparte, CI en GitHub Actions, desbloquear PHP localmente.

### 2.3 Estado vivo de datos no reverificado desde el 2026-10-08
Valores aceptados: TERM→CPV 9.749 (212/17/9.282/238); propuestas 12 APPLIED / 3 SUPERSEDED / 0
PENDING / 0 ABORTED; último `taxonomy_audit_log.id` **5260**.

Tras una pausa larga, **confirmar con lectura de sólo consulta antes de asumir continuidad.** No se ha
hecho en esta sesión.

### 2.4 El flujo n8n de CIRA no se ha verificado activo
**[MEDIDO]** No se invocó el webhook, deliberadamente, para no disparar nada externo; y una sonda GET
sería inconclusa porque los webhooks de n8n son POST-only.

**Riesgo concreto:** si el flujo está detenido cuando se envíe el enlace al cliente, **todas** las
consultas fallan igual y una UAT entera parecerá un fallo catastrófico del buscador. Es preflight
obligatorio y hoy **no verificado**.

### 2.5 El deploy de staging está ~muchos commits por detrás
Runtime desplegado: `004d24e98159bf152c741bd9f0ee698b43139d87`. La rama acumula desde entonces sólo
commits de `docs/`/`audit/`, que `paths-ignore` mantiene sin desplegar — **por diseño**.

**[INFERIDO]** El runtime desplegado y el HEAD de la rama deberían ser funcionalmente equivalentes,
porque todo el delta es documentación. **No verificado commit por commit en esta sesión.** Conviene
confirmarlo antes del primer deploy de TASK-0010, para no atribuir a TASK-0010 un cambio arrastrado.

---

## 3. Riesgos estructurales que no son resolubles, sólo gestionables

### 3.1 Desarrollo local y staging comparten UNA base Supabase
No hay base local separada. Cualquier `artisan` que escriba, ejecutado desde una máquina de desarrollo,
**muta datos compartidos reales**.

De aquí viene casi todo el gobierno del proyecto, y por eso `BATCH_EXECUTABLE_ENVIRONMENTS` está
limitado a `staging`: para que un entorno `local` no pueda publicar taxonomía aunque alcance los datos.

**Riesgo residual:** la restricción cubre la ruta del lote de propuestas revisadas. **No** cubre
cualquier otro comando que escriba. Un comando de importación o de homologación ejecutado por descuido
desde local escribe en datos reales.

### 3.2 `DEBUG_TOKEN` no es recuperable por diseño
Sólo existe en el almacén de Cloudflare y en el `sessionStorage` del navegador del administrador que
escribió `/debug-on <key>`. Cualquier tarea futura que necesite la regresión congelada **dependerá de
una acción del propietario** o de una rotación autorizada (GATE D). No es un bloqueo a resolver: es una
dependencia a planificar.

### 3.3 El loop depende de una credencial con caducidad
Si `CPV_GITHUB_TOKEN` caduca, el agente deja de poder comentar y —si el repo pasara a privado— también
de leer. **El fallo sería silencioso**: el loop simplemente se detiene esperando una revisión que nadie
publicó.

**Mitigación sugerida, no implementada:** anotar la fecha de caducidad en `current_task.md` y
verificarla al reanudar sesión.

---

## 4. Cosas que parecen problemas y no lo son

Registrado para que nadie "arregle" lo que está bien:

- **`pipeline` / `refinery` / `refinería` devuelven `canonical_concepts: []`.** Correcto. Sus
  relaciones CPV siguen en `candidate`/`needs_review` y la rama de herencia exige `approved`. Siguen
  devolviendo candidatos por `LITERAL_MATCH` y `SEMANTIC_INFERENCE`. Aprobar en masa para "arreglarlo"
  es GATE C.
- **`taxonomy_concept_relations` no la lee nunca el Worker.** Verificado: aparece 0 veces en
  `src/*.ts`. No es una integración olvidada.
- **Los commits docs-only no despliegan.** Es el comportamiento de `paths-ignore` introducido
  deliberadamente en TASK-0005.
- **#420/#421/#422 tienen `applied_at`, `authorization_reference` y `target_environment` en `NULL`.**
  Correcto: están `SUPERSEDED` sin sucesor, a propósito, para que ninguna decisión pre-escrita quede
  esperando un clic.
- **`SESSION_RESUME.md` registra un HEAD anterior al suyo propio.** El archivo se redactó en
  `bdfefa97...` y se commiteó como parte de `21c8c07`. Benigno.

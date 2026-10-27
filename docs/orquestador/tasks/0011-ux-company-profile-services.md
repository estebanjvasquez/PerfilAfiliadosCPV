# TASK-0011 — UX de punta a punta: perfil de empresa y servicios

| Campo | Valor |
|---|---|
| Identificador | **TASK-0011** (siguiente libre: `tasks/` llega a 0007; 0008–0010C usados en ramas/worktrees; 0011 sin uso en `docs/`) |
| Fuente | Issue #2, comentario [`6438570942`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6438570942) (propietario, 2026-10-27 09:43Z) |
| Referencias directas | valoración UX `C:\Proyectos\CPV-UX\capturas\20261027T0834Z\VALORACION_UX.md` (fuera del repo); plan preliminar `6438169717` (propuesta, no asignación) |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (rama canónica del estado vigente, `SESSION_RESUME.md` §1; instrucción del propietario: seguirla) |
| BASE | `65b858cfd65245d0a5f2e4de590693aee42dc567` |
| Estado | **IN_PROGRESS** |
| Gates | sin merge a `main`, sin despliegue, sin escrituras masivas; datos reales solo en lectura |

Cambios locales del propietario en `AGENTS.md`, `CLAUDE.md`, `docs/orquestador/PROTOCOL.md` y
`docs/orquestador/SESSION_RESUME.md`: conservados, no restaurados ni descartados.

## Asignación (resumen fiel; el texto completo es el comentario fuente)

**Objetivo:** corregir los hallazgos UX reproducibles del informe y el bloqueo de servicios, manteniendo las reglas de
negocio y el acceso correcto de afiliados y administradores. Tres bloques, en orden.

1. **Servicios + validación crítica.** Reproducir «Desvincular» deshabilitado, empresas legacy sin principal y el flujo
   de quitar adicionales y colocar/cambiar el principal. Trazar la causa (UI → servidor) antes de tocar código. Regla
   coherente para ambos roles, sin estado inválido, re-autorización en servidor, afiliado limitado a su empresa,
   cambios atómicos. Regresión focalizada. Paso 3: «Sector principal» ausente no bloquea si el campo está deshabilitado;
   validación traducida; contar empresas afectadas en solo lectura.
   *Aceptación:* causa de ambos bloqueos demostrada; flujos permitidos sin estado inconsistente; sin ampliar permisos
   entre empresas; una ficha sin sector principal guarda cambios válidos.
2. **Ficha:** modelo de guardado claro (guardar por paso / navegación directa), títulos y ayuda para las nueve
   secciones, menos desplazamiento horizontal, sin cajas vacías antes de la carga diferida; «Categorías CPV»; la
   clasificación vieja como histórica; traducir «Chambers», «Contacts», «Name», «Export» y validaciones; distinguir
   estados de acciones.
3. **Listados, inicio, mapa, calidad de datos, público, registro, móvil:** ver comentario fuente; cada hallazgo se
   corrige o se cierra con evidencia de no reproducción o decisión de alcance.

---

## Bloque 1 — Servicios y sector principal

### Diagnóstico (fuente: código en BASE y en el runtime de staging `cf01e9c`; capturas UX `interaccion2`, `interaccion5`)

**D1. «Desvincular» de servicios no existe: la pestaña legacy es de solo lectura desde `8352cad` (2026-09-15).**
- `ServicesRelationManager` («Sectores y servicios») no declara acciones (`headerActions([])`, `actions([])`); el
  commit `8352cad` quitó Attach/Detach al pasar el catálogo viejo a solo lectura. Igual en `cf01e9c` (staging).
- La captura `interaccion5.log` lo confirma en vivo: en las empresas 1 y 72 la pestaña solo ofrece la paginación
  (`acciones=[…pestañas…, "Siguiente","1","2"]`), mientras «Desvincular» sí aparece en Cámaras y Contactos.
- Una acción no autorizada en Filament 3.3.55 se **oculta** (`CanBeHidden::isHiddenInGroup` → `! isAuthorized()`), no
  se deshabilita; en Cámaras/Contactos la política no tiene `detach` y Filament la permite (comprobación de existencia
  de política). No hay un botón «Desvincular» deshabilitado por política: lo que el usuario no puede hacer es
  desvincular servicios, porque la acción no existe.

**D2. El guardado exige desvincular justo eso: bloqueo circular.**
- `EditEmpresa::beforeSave()` (regla de cbc1e37, «máximo 2 sectores») detiene el guardado si algún servicio legacy
  pertenece a un sector fuera de {principal, secundario}, con el texto «En la pestaña "Sectores y servicios" desvincule
  los servicios de: …». Tras `8352cad` no hay forma de hacerlo desde la UI → ningún cambio de la ficha se guarda.

**D3. Para quien no es super_admin, el sector principal se ignora en esa regla.**
- `sector_principal_id` está `->disabled()` para quien no es super_admin (c6a08a2). En Filament 3,
  `CanBeDisabled::disabled()` también aplica `dehydrated(false)`, así que `$this->form->getState()` **no** trae
  `sector_principal_id`. `beforeSave()` arma «permitidos» solo con el secundario: los servicios del sector principal
  real cuentan como «fuera» y el afiliado queda bloqueado aunque sus datos sean coherentes.

**D4. Paso 3: «Sector principal» obligatorio y deshabilitado.**
- El campo es `->required()` y `->disabled()` a la vez. Filament valida los campos deshabilitados visibles
  (`isHiddenAndNotDehydrated()` solo salta los ocultos), así que una empresa sin sector principal (GEOHIDRA, id 72)
  falla en «Siguiente» del paso 3; «Guardar cambios» solo existe en el paso 4 → no puede guardar nada. El afiliado no
  puede corregirlo (lo define la Cámara).

**D5. Mensajes en inglés.** `lang/` solo tiene `en` y `vendor`; `config/app.php` usa `locale = es` sin
`lang/es/validation.php`, por eso «The sector principal field is required.».

**Qué protege cada bloqueo:** D2/D3 — que una empresa no opere en más de 2 sectores (principal y secundario); D4 — que
toda empresa tenga el sector que define la Cámara. El catálogo legacy sigue alimentando los reportes actuales (por eso
se dejó visible en `8352cad`); la clasificación hacia adelante es «Categorías CPV».

### Decisiones (regla coherente para admin y afiliado)

- **R1 — Desvincular servicios legacy:** vuelve «Desvincular» (individual y en lote) en «Sectores y servicios
  (histórico)», **solo quitar**; vincular servicios nuevos al catálogo viejo sigue cerrado (`8352cad`). Lo puede usar
  quien puede editar esa empresa ahora (`EmpresaAccess::mayEdit`, igual para admin y afiliado; la re-autorización por
  petición ya existente cubre la pestaña). Quitar un vínculo no borra servicios ni sectores del catálogo. Se ejecuta en
  una transacción y recalcula la completitud.
- **R2 — Sector principal:** lo define la Cámara. Obligatorio solo para quien puede editarlo (super_admin); para el
  resto el campo no se valida ni se envía, y la regla de sectores usa el valor guardado en la empresa.
- **R3 — Regla de máximo 2 sectores:** se bloquea un guardado solo si **empeora** la coherencia (deja fuera de los
  sectores elegidos servicios que antes estaban dentro). Una inconsistencia previa (empresa legacy) no impide guardar
  otros cambios: se guarda y se avisa qué servicios quitar con «Desvincular», o que la Cámara defina el sector
  principal. Así nunca se crea un estado nuevo inválido y nunca se exige conservar datos legacy incorrectos.
- **R4 — Principal de «Categorías CPV»:** si el límite de principales está lleno, el aviso indica la acción secuencial
  (marcar primero como secundaria la principal actual). Las acciones solo resuelven registros de la propia empresa
  (consulta de la relación) y la página re-autoriza cada petición.

### Cambios

_(se completa al implementar)_

### Pruebas y evidencia

_(se completa al implementar)_

---

## Bloque 2 — Ficha de empresa

_(pendiente)_

## Bloque 3 — Listados, inicio, mapa, público, registro, móvil

_(pendiente)_

## Pendientes y siguiente paso

- Siguiente paso: implementar R1–R4 con regresión focalizada.

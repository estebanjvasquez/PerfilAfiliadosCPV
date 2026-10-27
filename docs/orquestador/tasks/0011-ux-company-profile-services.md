# TASK-0011 — UX de punta a punta: perfil de empresa y servicios

| Campo | Valor |
|---|---|
| Identificador | **TASK-0011** (siguiente libre: `tasks/` llega a 0007; 0008–0010C usados en ramas/worktrees; 0011 sin uso en `docs/`) |
| Fuente | Issue #2, comentario [`6438570942`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6438570942) (propietario, 2026-10-27 09:43Z) |
| Referencias directas | valoración UX `C:\Proyectos\CPV-UX\capturas\20261027T0834Z\VALORACION_UX.md` (fuera del repo); plan preliminar `6438169717` (propuesta, no asignación) |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (rama canónica del estado vigente, `SESSION_RESUME.md` §1; instrucción del propietario: seguirla) |
| BASE | `65b858cfd65245d0a5f2e4de590693aee42dc567` |
| Estado | **READY_FOR_REVIEW** (2026-10-27); sin merge ni despliegue |
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

**Hallazgo adicional (D6):** el primer `lang/es/validation.php` no se cargaba: existe `resources/lang/` (overrides de
vendor) y Laravel 12 la prefiere sobre `lang/` (`Application::langPath`). Se movió a `resources/lang/es/`. Lo que hay
hoy en `lang/` (`en`, `vendor/filament-shield`) tampoco se carga: se registra, no se toca (fuera de alcance).

### Cambios (commits `080d9b3`, `22ace13`)

- `ServicesRelationManager`: «Desvincular» individual y en lote (R1), `canDetach/canDetachAny` = `EmpresaAccess::mayEdit`,
  `canAttach` = false, borrado del vínculo + recálculo de completitud en una transacción; título «Sectores y servicios
  (histórico)» y texto explicativo.
- `EditEmpresa`: `definesSectors()`; principal obligatorio solo para super_admin (R2); `sectorCoherence()` (pura) y
  `beforeSave()` con el principal guardado cuando no se envía; bloquea solo lo que empeora y avisa después de guardar
  (R3).
- `TaxonomyCategoriesRelationManager`: título «Categorías CPV»; avisos secuenciales de principal/secundaria (R4).
- `resources/lang/es/validation.php` (D5/D6). Manual: §Sectores y servicios (histórico), errores frecuentes.
- Pruebas: `tests/Feature/Ux/CompanyServicesAndSectorsTest.php` (13); `CompanyRelationManagersTest` ajustada a R1
  (detach existe solo para vínculos propios; el de BETA no se resuelve; lo demás sigue cerrado).

### Pruebas y evidencia

| Corrida (runner aislado Contabo) | Resultado |
|---|---|
| Antes, `0f99b9c` (solo pruebas) | 10 fallos esperados de 33 (run `20261027T095727Z-0f99b9c7ab98`) |
| Después, `080d9b3` | 31/33; 2 ajustes: prueba del 404 al montar y D6 (run `20261027T095906Z-080d9b3c02f1`) |
| Enfocadas finales | ver tabla de cierre |

**Conteo en staging (solo lectura, transacción READ ONLY, solo agregados, 2026-10-27):** 406 empresas; **194 sin sector
principal**, todas con servicios viejos; **195 con algún servicio fuera de sus sectores**; la empresa 72 sin principal.
Con el código anterior, ninguna de esas empresas podía guardar la ficha siendo afiliado (D2–D4), y por D3 tampoco las
que tienen todos sus servicios en el sector principal.

---

## Bloque 2 — Ficha de empresa (commits `2136f60`, `89cd571`)

**Diagnóstico:** «Guardar cambios» solo existía al final del paso 4 (`HasWizard`, pasos no navegables); las 10 pestañas
no tenían título ni explicación y la barra (`fi-tabs … overflow-x-auto`) escondía las últimas; los gestores eran
`lazy` (placeholder vacío hasta entrar en pantalla); los títulos «Chambers»/«Contacts»/«Users» salían de
`Str::headline` del nombre de la relación; «Name» era una columna sin etiqueta; «Export»/«Add Column» del plugin de
exportación; «Completado: N%» era un botón deshabilitado con el mismo aspecto que las acciones.

**Decisiones y cambios:**
- `EditEmpresa`: acción de cabecera **Guardar cambios** en todos los pasos (mismo `save()`, `mod+s`), pasos
  navegables (la validación completa ocurre al guardar), aviso «No se guardó: hay datos por corregir» con el nombre del
  paso con error, subtítulo que explica qué se guarda dónde.
- Vista propia `filament/resources/empresa-resource/pages/edit-empresa.blade.php` (la de Filament 3.3.55 + título
  **Secciones del perfil** con explicación; las pestañas pasan a varias filas en vez de desplazarse en horizontal).
- Los 10 gestores: `$isLazy = false` (solo se renderiza la pestaña activa; sin cajas vacías), una línea de ayuda por
  sección (`Table::description`), títulos «Cámaras», «Contactos», «Usuarios»; «Nombre», «Correo electrónico»,
  «Exportar», «Agregar columna».
- `CompletionBadge` como insignia (estado); `NoAplicaAction` gris con contorno (acción secundaria); los botones de
  sección siguen primarios.
- La clasificación vieja queda rotulada **histórica** y no editable salvo «Desvincular» (sin borrar datos).
- Manual: §Guardar los cambios, §Las pestañas de su empresa, Cámaras/Contactos/Usuarios.
- Pruebas: `tests/Feature/Ux/CompanyProfilePageTest.php` (4).

---

## Bloque 3 — Listados, inicio, mapa, público, registro, móvil (commit `9c13f46`)

| Hallazgo | Diagnóstico | Resultado |
|---|---|---|
| 6 Empresas | sin acción visible de edición; PDF como botón primario; 5 filas | **Editar perfil** primera acción; PDF gris con contorno; 10 filas (10/25/50); columna «Perfil completado» de `completion_percentage` cacheada (0 consultas extra; permiso por fila sin consulta) |
| 8 Inicio | aplica a todo rol con empresas vinculadas | tarjeta **Mi empresa** (≤3 empresas: % y pendientes en vivo + «Editar perfil»; más: total + «Ver empresas»; sin empresas: no aparece). Avisos: una sola tarjeta (antes dos con la misma frase), al final, tamaño de nota. «Salir» como enlace discreto (sigue en el menú de usuario). Se quitaron dos conteos sin uso que dividían por cero con `empresas` vacía |
| 9 Mapa: 6 tarjetas vacías | **no es error de datos ni de render.** 42 tarjetas con datos + 6 en blanco = 48 grupos, el total anunciado; cada tarjeta siempre imprime nombre y conteos. `.cpv-map__group { content-visibility: auto }` deja sin pintar lo que está fuera de pantalla y la captura de página completa de Playwright lo fotografía así. Un usuario que se desplaza las ve pintadas | cerrado sin cambio de código (decisión: la optimización se conserva) |
| 10 Público | botones `#fbc464` sobre `#faa819`; frase sin enlace; «Camara» | botones gris oscuro con texto blanco y foco visible; «haz clic aquí y regístrate» enlaza al registro; «Cámara» en título y `alt`; `style.css?v=4` |
| 11 Calidad de datos | `website` con máscara `http://***…` (fuerza http://, corta a 32) sin validar | sin máscara; normaliza (agrega `https://`), valida dominio con punto **solo si el valor cambia**; un valor viejo no bloquea ni se reescribe. Staging: 2 webs sin punto en el dominio (solo lectura). Fechas de Sectores/Servicios sin segundos y ocultas por defecto. **Nombres en mayúsculas: sin cambio** (convención de captura `mb_strtoupper`; transformarlos sería destructivo) |
| 12 Móvil | sin teléfono físico | pestañas en varias filas, «Editar perfil» primero en la fila; menú superpuesto: Filament guarda el estado abierto del menú en el navegador (estado heredado del escritorio en la captura, misma nota del runner UX: cerrar con `Alpine.store('sidebar').close()`), no se reprodujo como defecto; pasos del asistente comprimidos: componente de Filament, no se modificó |
| 13 Registro | sin indicación de lo que sigue | texto **Después de registrarse** en el formulario y **Próximos pasos** en «Verifique su correo electrónico» (verificar + avisar a la Cámara, que asocia el usuario) |

Pruebas: `tests/Feature/Ux/ListHomePublicAndDataQualityTest.php` (6); `HomeBrandingLayoutTest` actualizada al nuevo Inicio
(`3037e5a`, `932119a`); aviso con sus dos frases (`c3bce84`). Manual: Inicio, Abrir su empresa, Datos de contacto, Registro, Verificar correo.

---

## Cierre

| Campo | Valor |
|---|---|
| REVIEW_BASE | `65b858cfd65245d0a5f2e4de590693aee42dc567` |
| REVIEW_HEAD (código y pruebas) | `c3bce84f5078545aa755dc94ecc4a224bd8eaae8` |
| Enfocadas | `9c13f46`: 50/51 (falla resuelta en `89cd571`: la acción de cabecera debía llamar `save()` también montada) |
| Suite completa | `c3bce84`: **OK (658 tests, 7.591 aserciones)**, run `20261027T103636Z-c3bce84f5078`. Corridas previas: `89cd571` y `3037e5a`/`932119a` en rojo por `HomeBrandingLayoutTest` (diseño anterior de Inicio fijado en la prueba; y la segunda frase del aviso no se pintaba: Filament 3 ignora el tercer argumento de `Card::make()`, corregido en `c3bce84`) |
| Staging | `staging_before/after` 200 en cada corrida; sin contenedores `cpvtest-*` restantes |
| Datos | ninguna escritura en staging ni producción; solo el conteo agregado de solo lectura |

**Limitaciones:** sin teléfono físico; sin recorrido de navegador con cuenta real (las pruebas Livewire/HTTP cubren
admin y afiliado con usuarios sintéticos); la captura «Desvincular deshabilitado» del propietario no se vio: en el
código vivo y en `cf01e9c` la acción no existe en Servicios (D1), lo que sí se reprodujo es el bloqueo circular (D2–D4).

**Pendientes (no arrancan solos):** recorrido UX en navegador con el runner (`--ux-script`) y en un teléfono; decidir
si la Cámara define el sector principal de las 194 empresas (dato del propietario, no del sistema); limpiar `lang/`
muerto; los servicios viejos fuera de sector se quitan desde la ficha, no en masa.

**Nota de commits:** `c3bce84` incluye, además del aviso, las dos líneas del checkpoint de `SESSION_RESUME.md` (solo esas; los cambios locales del propietario en ese archivo, `AGENTS.md`, `CLAUDE.md` y `PROTOCOL.md` siguen sin commitear, conservados).

**Siguiente paso:** revisión del orquestador/propietario. Sin merge ni despliegue sin autorización.

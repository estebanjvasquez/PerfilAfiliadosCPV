# Informe acumulativo de seguridad — Perfil de Afiliados CPV

**Fecha de actualización:** 2026-10-21 (Europe/Madrid).  
**Estado:** revisión en curso; no constituye certificación de seguridad.  
**Programa:** CPV-SECURITY-AND-RELIABILITY.  
**Rama de mantenimiento inicial:** `audit/security-access-01`.  
**Plan:** [SECURITY_REVIEW_PLAN.md](../plans/SECURITY_REVIEW_PLAN.md).

Este registro resume cada ronda, sus hallazgos, correcciones, aceptación y despliegue. Los informes de ronda conservan la evidencia original; este documento no los sustituye ni debe borrar sus conclusiones anteriores.

## 1. Resumen ejecutivo

- Las descargas de perfil de empresa recibieron controles de sesión, verificación, autorización por empresa y respuestas privadas en una ronda previa al programa SEC.
- SEC-01 ronda 1 identificó una vulnerabilidad de privilegios y entregó una reparación aceptada para preparar su despliegue. **Aceptada no significa desplegada.**
- Quedan decisiones sobre permisos y asociaciones de usuarios, hipótesis por reproducir y superficies todavía no probadas.
- La siguiente ronda asignada verifica creación/edición de cuentas, concesión de privilegios y límites de acceso a archivos.
- Gerencia y nuevos reportes permanecen en espera del cierre acordado de seguridad.

## 2. Estado de versiones y evidencia

| Concepto | Referencia | Qué demuestra |
|---|---|---|
| Rama de despliegue observada al actualizar este informe | `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4` | Referencia remota; no es una comprobación independiente del contenedor vivo |
| Última entrega de despliegue conocida | Issue #2, comentario 6288589926; run 39405700502 | Despliegue de la selección de secciones; controles de descarga previos incluidos |
| Candidato de seguridad revisado | `0139e954ec22f0a6bfa2377968a6a3af86c15874` | Reparación F-SEC01-01 aceptada en 6297739082 |
| Despliegue de F-SEC01-01 | NO ACREDITADO / PENDIENTE | No existe en este registro una entrega que confirme su aplicación al entorno |
| Ronda 2 — candidato | `fix/sec-01-account-path-boundaries` (TESTED `e7ba59d`) | Entregada con correcciones de revisión; CAMBIOS SOLICITADOS atendidos; no aceptada ni desplegada |
| Despliegue de R2 (F-SEC01-02a, F-04) | NO ACREDITADO / PENDIENTE | Código aceptable pendiente de revisión; sin entrega al entorno |

Cada cambio de estos estados necesita SHA y fuente. No inferir despliegue a partir de un commit en una rama de trabajo.

## 3. Cronología de rondas

| Ronda | Alcance | Corrección / resultado | Revisión y estado |
|---|---|---|---|
| Antecedente: acceso a exportaciones | Descargas por empresa, verificación, permisos, errores y caché | Rutas protegidas, alcance por empresa, identificador validado, XLS limitado y respuestas privadas; rutas antiguas redirigen | Aceptación 6265458863, candidato `ce70b7f`; despliegue comunicado en 6267092324. El residuo de caché previo no quedó acreditado como eliminado |
| SEC-01 R1 | Inventario efectivo, matriz de actores y primeras pruebas de acciones | Una reparación de privilegios; resto de hallazgos registrado | Entrega 6294285848; revisión 6297739082: reparación aceptada, SEC-01 continúa abierto |
| SEC-01 R2 — cuentas y archivos | Inyección de privilegios, creación/edición de usuarios, traversal y expectativas de pruebas | Reparación de concesión de roles (faceta de cuentas de F-02), F-04 retirado, F-05 no reproducido, traversal sin hallazgos. BASE `0139e95`, TESTED/REVIEW ver §5. Suite completa verde | Instrucción 6310273754; **revisión 6310331211 (R2-REV): CAMBIOS SOLICITADOS**, corregidos en esta entrega; no aceptada todavía |
| SEC-02 general | Reportes, archivos y caché | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-03 general | Identidad, sesiones y entradas | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-04 | Dependencias, integraciones e infraestructura | Pendiente | No cerrado |
| SEC-05 | Regresión consolidada y riesgos residuales | Pendiente | No cerrado |

## 4. Registro de hallazgos

Las severidades son las comunicadas por la ronda, sujetas a revisión. Una configuración observada o una hipótesis no equivale a una explotación demostrada.

| ID | Ronda / severidad | Hallazgo resumido sin mecanismo sensible | Confirmación | Corrección / decisión | Estado y siguiente paso |
|---|---|---|---|---|---|
| F-SEC01-01 | R1 / Alta | Capacidad de suplantación disponible a un actor sin autoridad suficiente | Reproducido en aislamiento | Restricción de la capacidad en servidor al rol autorizado; un archivo de runtime | Reparación aceptada en `0139e95`; despliegue pendiente de acreditación |
| F-SEC01-02 | R1 / Alta, configuración | Delegación de administración de privilegios que requiere revisión | Permiso y acceso al editor observados; no confundir con demostración de toda cadena de escalación | Propuesta de mínimo privilegio; sin cambios en roles compartidos | **Parcial.** Faceta de escritura de cuentas corregida en R2 (ver F-SEC01-02a). El editor de roles (RoleResource) y la configuración de autoridad de concesión siguen como decisión del propietario; el hallazgo NO se cierra |
| F-SEC01-02a | R2 / Alta | Un actor con gestión de usuarios podía otorgarse o asignar el rol con privilegios al crear/editar una cuenta, o editar una cuenta privilegiada | Reproducido en aislamiento; prueba de frontera de persistencia (el rol prohibido nunca se escribe en el pivote) | Autorización en servidor ANTES de la sincronización de la relación de roles (`PrivilegedRoleGuard` en `saveRelationshipsUsing`); bloqueo de apertura de cuenta privilegiada en `authorizeAccess` | Corregido en candidato R2; aceptación y despliegue pendientes |
| F-SEC01-03 | R1 / Baja–media, configuración | Permisos de escritura en catálogos globales para un rol de afiliación | Hipótesis derivada de permisos; no ejecutada | Confirmar necesidad y efecto antes de cambiar | Pendiente de prueba y decisión |
| F-SEC01-04 | R1 / Baja | Superficie pública de prueba sin función de negocio vigente | Alcanzabilidad confirmada por inventario | Retirada (ruta, clase y vista) con regresión | **Corregido en candidato R2** |
| F-SEC01-05 | R1 / Media provisional | Posible exposición excesiva de datos mediante una acción de cuentas | **No reproducido en el alcance probado** (el único método invocable devolvía datos al framework, no al navegador; el caso de contraseña vacía ya lo maneja el formulario) | Se retiró el método redundante; edición con contraseña vacía conserva el hash (probado) | Negativo acotado; no refutado globalmente. El indicio de campo de verificación (F-SEC01-07) permanece para SEC-03 |
| F-SEC01-06 | R1 / Regla de negocio | Autoridad para desvincular a otros usuarios de una empresa sin definición suficientemente explícita | Comportamiento observado | Definir autoridad de gestión de usuarios por empresa | Decisión pendiente; no modificar vínculos reales |
| F-SEC01-07 | R1 / Indicio | Campo de verificación de cuenta susceptible de asignación a nivel de modelo | No demuestra acceso desde una petición manipulada | Probar escritores reales de cuenta y campos protegidos | R2; no declarar bypass sin reproducción |
| F-SEC01-08 | R1 / Informativa provisional | Archivos históricos de diagnóstico en el repositorio | Existencia observada; entrega web no comprobada | Evaluar exposición, contenido y saneamiento | SEC-04; no afirmar ausencia de secretos por coincidencia de palabras |
| R-SEC01-01 | R1 / Fiabilidad | Formularios de empresa fallan si no hay ciudades | Confirmado en aislamiento; antecedente conocido | Reparación independiente pendiente | No cerrado |
| Q-SEC01-01 | Revisión R1 / Calidad de evidencia | Parte de la matriz usa el propio gate como expectativa | Confirmado por revisión del test | Añadir expectativas de negocio independientes | R2 |
| Q-SEC01-02 | Revisión R1 / Calidad de evidencia | Errores inesperados y ciertos accesos legítimos no siempre hacen fallar la matriz | Confirmado por revisión del test | Fallar ante 5xx/excepciones inesperadas, comprobar caminos permitidos y actor privilegiado representativo | **Atendido en R2** |

En R2 SÍ se estableció y corrigió un fallo de inyección de roles en cuentas (F-SEC01-02a). NO se estableció traversal de directorios en las superficies probadas (segmento de id numérico y `secciones` con lista blanca): resultado negativo acotado, no certificación general.

### Revisión R2 (6310331211) — correcciones solicitadas y su estado en esta entrega

| ID | Petición | Estado |
|---|---|---|
| R2-REV-01 | Autorizar la concesión de roles ANTES de persistir; no depender de limpieza posterior; conectar o retirar helper muerto; frontera/atomicidad | Hecho: autorización en `saveRelationshipsUsing` antes de `sync()`, dentro de la transacción de Filament; prueba de frontera de persistencia; helper muerto retirado |
| R2-REV-02 | Aserciones de traversal sobre texto de PDF decodificado; respuesta exacta de denegación; retirar objetivos de SO; capa de denegación e integridad del canario | Hecho: `pdftotext` sobre PDFs; 404 exacto «Archivo no disponible» con 0 consultas/render; sin objetivos de SO; capa registrada; canario intacto |
| R2-REV-03 | Expectativas independientes en la matriz; fallar 5xx inesperados (JoinViews contado); actor privilegiado representativo; declarar filas solo-gate | Hecho: expectativas de negocio allow/deny en entradas de alto valor; 5xx inesperado falla salvo JoinViews; barrido completo declarado como caracterización |
| R2-REV-04 | Registro acumulativo con estado; separar aceptado de desplegado; ayuda de administrador | Este documento; comportamiento de staff descrito en §5 R2 |

## 5. Correcciones y validación por ronda

### SEC-01 R1

- **Base:** `4a56fafa5117cd6efb739603aa468efbf841cd15`.
- **Reparación:** F-SEC01-01, commit `613c359`, incluida en candidato revisado `0139e954ec22f0a6bfa2377968a6a3af86c15874`.
- **Regresión previa:** falla en el control de suplantación en el commit de pruebas anterior a la reparación.
- **Suite completa:** ejecución del desarrollador en `7d8531e24fcb3c109b6bb93ea0621c691c7e6074`: 593 tests / 6.824 aserciones.
- **Enfocadas:** ejecución del desarrollador en `481987719ce6d8f2e5a9fa0094a450fd1d39c4b9`: 25 tests / 2.487 aserciones.
- **Revisión manual:** diff de aplicación y mecanismo de ejecución de la dependencia fijada en lockfile revisados. No es una ejecución independiente de PHPUnit.
- **Limitaciones:** camino permitido probado mediante el gestor; falta flujo completo de acción/salida en contexto limpio. La matriz no sustituye un oráculo independiente de permisos. Sin comprobación exhaustiva de todas las acciones.
- **Informe:** [security_sec01_2026-10-20.md](../../audit/security_sec01_2026-10-20.md).
- **Despliegue y cierre:** pendientes; no cerrar el hallazgo operativo por aceptación del código.

### SEC-01 R2 — cuentas y archivos (6310273754) + correcciones de revisión (6310331211)

- **Base:** `0139e954ec22f0a6bfa2377968a6a3af86c15874` (candidato F-01 congelado; no modificado).
- **Rama:** `fix/sec-01-account-path-boundaries`.
- **Reparación F-SEC01-02a:** autorización de concesión de roles en el límite de escritura, ANTES de sincronizar la relación (`app/Support/Security/PrivilegedRoleGuard.php` + `saveRelationshipsUsing` en `UserResource`), dentro de la transacción de create/edit de Filament. Apertura de cuenta privilegiada bloqueada en `EditUser::authorizeAccess` (consulta fresca de pivote). Retirado el enfoque previo de limpieza posterior y su helper de snapshot.
- **F-SEC01-04:** retirada la ruta `/form`, su componente y su vista.
- **F-SEC01-05:** no reproducido; método redundante retirado.
- **Regresión antes/después:**
  - antes (enfoque de limpieza posterior): la prueba de frontera de persistencia falla porque el rol prohibido SÍ se escribía en el pivote antes de retirarse;
  - después: el rol prohibido nunca se escribe; enfocadas verdes.
- **Enfocadas:** `e7ba59d9deb35198bc9fb3fac857255ebaa9e0e2` (`AccountPrivilege|PathBoundary|ImpersonationAction|AccessMatrix|SurfaceInventory`): 22 tests. **Suite completa:** ver §entrega.
- **Cambio visible para staff:** al intentar asignar un rol con privilegios sin ser Super Admin, la asignación se descarta y aparece un aviso en la interfaz; no cambia nada para afiliados. No se modificó el manual del afiliado por eso; no existe página de ayuda de administración de usuarios donde documentarlo (se registra aquí).
- **Traversal:** inventario dirigido de sinks; canario fuera de la raíz; el segmento `{empresa}` devuelve 404 y `secciones` cae al perfil completo en todas las variantes; sin lectura fuera de raíz, sin 5xx, sin fuga; canario intacto.
- **Informe:** [security_sec01_r2_2026-10-21.md](../../audit/security_sec01_r2_2026-10-21.md).
- **Estado:** CAMBIOS SOLICITADOS atendidos; no aceptada todavía; sin desplegar.

## 6. Cobertura pendiente

R1 informó 131 rutas inventariadas y 792 solicitudes sobre 88 entradas con nueve actores. Son conteos de inventario y caracterización, no porcentaje global de seguridad.

| Superficie | Evidencia R1 | Pendiente |
|---|---|---|
| Relation managers | 3 de 23 (sin cambio en R2) | 20 — lote acotado siguiente |
| Búsqueda global | 1 de 13 recursos (sin cambio en R2) | 12 — lote acotado siguiente |
| Actor administrador | Rol sintético sin conjunto explícito representativo | Expectativas y capacidades efectivas de fixture |
| Acciones Livewire / exportaciones generales | Inventario y pruebas parciales | Ejecución negativa y positiva por operación |
| Registro, perfil, roles, verificación y recuperación | Comprobaciones parciales | Pruebas específicas de escritores y flujos |
| Archivos / rutas / cargas / temporales | Inventario parcial | Sinks, límites de directorios y permisos |
| Caché previa y configuración efectiva | Sin cierre acreditado | Evidencia y decisión |
| Dependencias e integraciones | Sin cierre | SEC-04 |

## 7. Regla de actualización obligatoria

En CADA ronda de seguridad, el desarrollador actualiza este documento en su rama de entrega:

1. Añadir una fila de cronología con fecha, alcance, comentario, BASE_SHA, TESTED_HEAD, REVIEW_HEAD y estado de revisión.
2. Añadir hallazgos con ID estable; no renumerar ni borrar. Referenciar duplicados a su causa original.
3. Separar **sospechado / confirmado / corregido en candidato / aceptado / desplegado y verificado / cerrado / reabierto / riesgo aceptado**. Registrar por separado la validación, revisión y aplicación al entorno.
4. Para cada corrección: cambio resumido, commit, regresión antes/después, efectos legítimos y límites de evidencia.
5. Tras despliegue autorizado, añadir SHA real, workflow, fecha, comprobación posterior y riesgos que permanecen. Si no hubo despliegue, mantenerlo explícito.
6. Actualizar denominadores y pendientes. Los errores conocidos deben contarse y tener referencia; nunca desaparecer de la matriz.
7. Preservar la cronología anterior. Retirar conclusiones incorrectas mediante una nota fechada, no sobrescribiéndolas sin rastro.
8. Mantener un único registro canónico: llevar este documento a la rama sucesora mediante integración o copia revisada de sus cambios documentales, sin promover runtime ajeno ni hacer force-push. No modificar un candidato ya aceptado; nueva documentación produce otro SHA claramente identificado.
9. En el comentario de entrega añadir `SECURITY_REGISTER: docs/security/SECURITY_STATUS.md` y los IDs modificados.
10. Mantener la entrada de reanudación corta: enlace al registro; no copiar todo el registro en SESSION_RESUME.

## 8. Confidencialidad y cierre

Este repositorio es público. No incluir datos reales, secretos, mapas de permisos reales, payloads ni pasos de explotación de problemas no corregidos. Conservar la evidencia sensible en almacenamiento restringido fuera de git y webroot; indicar un canal de entrega seguro sin asumir acceso entre ejecutores.
Una búsqueda por patrones sin coincidencias no acredita que un archivo sea inocuo.
El cierre del programa requiere alcance acordado, pruebas y decisiones explícitas sobre lo pendiente. Gerencia se activa mediante instrucción posterior; no se inicia por actualizar este informe.

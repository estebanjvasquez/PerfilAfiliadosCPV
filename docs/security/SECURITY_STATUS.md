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
- **2026-10-21:** las reparaciones de R1 (F-SEC01-01) y R2 (F-SEC01-02a, F-SEC01-04, atomicidad R2-REV-05) están **desplegadas en staging (`cf01e9c`) y verificadas con comprobaciones de solo lectura**. Esto no cierra SEC-01 ni el programa: siguen abiertos F-02 (editor de roles/configuración), F-03, F-06, la cobertura de relation managers/búsqueda y SEC-02..05.
- **2026-10-21, UAT del propietario: PASS** para esa entrega. **R3** (relaciones de empresa) corrige en candidato la re-autorización después de montar (F-SEC01-R3-01), sin desplegar. **Alerta: F-SEC01-R3-04**: un camino de privilegios que queda abierto se salta las guardas desplegadas; la mitigación inmediata es una acción del propietario sobre los datos de roles.
- **2026-10-21, R3 aceptado (NO desplegado). R4:** el propietario fijó que administrar roles es **exclusivo de super_admin**. La contención en datos vivos quedó **bloqueada** por el clasificador del ejecutor (sin cambios; **el riesgo vivo sigue**). La reparación de código está lista para revisión, sin desplegar.
- **2026-10-21, R4 código aceptado en 6319407886 (candidato `97737ba`, NO desplegado). Contención manual: confirmada por el propietario (6319443716) y VERIFICADA en solo lectura:** 0 permisos de administración de roles fuera de super_admin, continuidad de super_admin PASS y caché coherente. **Efecto colateral (F-SEC01-R4-01):** al guardar, el editor de roles quitó además permisos que no muestra; requiere decisión del propietario.
- **2026-10-21, F-SEC01-R4-01 (6320241495):** corrección del editor lista para revisión (TESTED `24413d5`, sin desplegar) y propuesta de recuperación de permisos **solo propuesta**: no se cambió ningún dato vivo.
- Quedan decisiones sobre permisos y asociaciones de usuarios, hipótesis por reproducir y superficies todavía no probadas.
- La siguiente ronda asignada verifica creación/edición de cuentas, concesión de privilegios y límites de acceso a archivos.
- Gerencia y nuevos reportes permanecen en espera del cierre acordado de seguridad.

## 2. Estado de versiones y evidencia

| Concepto | Referencia | Qué demuestra |
|---|---|---|
| Rama de despliegue observada al actualizar este informe | `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4` | Referencia remota; no es una comprobación independiente del contenedor vivo |
| Última entrega de despliegue conocida | Issue #2, comentario 6288589926; run 39405700502 | Despliegue de la selección de secciones; controles de descarga previos incluidos |
| Candidato de seguridad revisado | `0139e954ec22f0a6bfa2377968a6a3af86c15874` | Reparación F-SEC01-01 aceptada en 6297739082 |
| Despliegue de F-SEC01-01 | ~~NO ACREDITADO / PENDIENTE~~ → **DESPLEGADO Y VERIFICADO** (2026-10-21), en `cf01e9c` | Autorización 6316265832; run 39602544527 success; comprobaciones de solo lectura en §5 «Despliegue SEC-01 R1+R2» |
| Ronda 2 — candidato | `fix/sec-01-account-path-boundaries` (TESTED `f4dd8fb`, enfocadas y suite completa; antes REVIEW_HEAD `51a923e`, suite `6bf8582`, enfocadas `e7ba59d`) | Segunda revisión: CAMBIOS SOLICITADOS (atomicidad, R2-REV-05), corregidos; **aceptada** en 6310331211 (FINAL) y **desplegada** en `cf01e9c` (2026-10-21) |
| Despliegue de R2 (F-SEC01-02a, F-04, R2-REV-05) | ~~NO ACREDITADO / PENDIENTE~~ → **DESPLEGADO Y VERIFICADO** (2026-10-21), en `cf01e9c` | R2 y R2-REV-05 aceptadas en 6310331211 («FINAL R2 REVIEW — ACCEPTED FOR DEPLOYMENT PREPARATION»); verificación limitada a lo listado en §5 |
| Runtime de staging tras el despliegue | `cf01e9c3e30415a34ccda60b312a993c1388167d`, imagen `081c35c5715d`; recuperación `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`, la imagen de `87dd997`) | SSH de solo lectura, 2026-10-21 ~12:52Z |

Cada cambio de estos estados necesita SHA y fuente. No inferir despliegue a partir de un commit en una rama de trabajo.

## 3. Cronología de rondas

| Ronda | Alcance | Corrección / resultado | Revisión y estado |
|---|---|---|---|
| Antecedente: acceso a exportaciones | Descargas por empresa, verificación, permisos, errores y caché | Rutas protegidas, alcance por empresa, identificador validado, XLS limitado y respuestas privadas; rutas antiguas redirigen | Aceptación 6265458863, candidato `ce70b7f`; despliegue comunicado en 6267092324. El residuo de caché previo no quedó acreditado como eliminado |
| SEC-01 R1 | Inventario efectivo, matriz de actores y primeras pruebas de acciones | Una reparación de privilegios; resto de hallazgos registrado | Entrega 6294285848; revisión 6297739082: reparación aceptada, SEC-01 continúa abierto |
| SEC-01 R2 — cuentas y archivos | Inyección de privilegios, creación/edición de usuarios, traversal y expectativas de pruebas | Reparación de concesión de roles (faceta de cuentas de F-02), F-04 retirado, F-05 no reproducido, traversal sin hallazgos. BASE `0139e95`, TESTED/REVIEW ver §5. Suite completa verde | Instrucción 6310273754; **revisión 6310331211 (R2-REV): CAMBIOS SOLICITADOS**, corregidos en la entrega 6311569828; **segunda revisión (misma 6310331211, sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION»): CAMBIOS SOLICITADOS**; pausa por cuota (6313459342); corrección R2-REV-05 entregada después en la misma rama; no aceptada |
| Despliegue SEC-01 R1+R2 (2026-10-21) | F-SEC01-01 + F-SEC01-02a + F-SEC01-04 + R2-REV-05 a staging | `87dd997` → `cf01e9c` (fast-forward, push normal); run 39602544527 success (12:48–12:50Z) | Autorización 6316265832; aceptación 6310331211 (FINAL R2 REVIEW). Desplegado y verificado con comprobaciones de solo lectura; UAT del propietario pendiente. SEC-01 sigue abierto |
| UAT de la entrega desplegada (2026-10-21) | `cf01e9c` | OWNER_UAT: **PASS** (confirmación del propietario, registrada en 6316775771) | Cierra la espera de UAT de esa entrega; SEC-01 sigue abierto |
| SEC-01 R3 — relaciones de empresa, búsquedas, permisos | 7 gestores de relación de Empresa, 3 búsquedas, propuestas F-02/F-03/F-06 | F-SEC01-R3-01 corregido; R3-02/R3-03/R3-04 confirmados en aislamiento y registrados. BASE `eb03b4c`, TESTED `e95169c`, suite 624 OK | Instrucción 6316775771; ~~listo para revisión~~ → **aceptado en 6318588005 (REVIEW_HEAD `66f5ce8`), NO desplegado**. **Alerta al propietario: R3-04** |
| SEC-01 R4 — administración de roles (2026-10-21) | Regla del propietario: administrar roles es exclusivo de super_admin. A) contención en datos vivos; B) reparación de código | A) ~~**BLOQUEADA**: el clasificador de permisos del ejecutor rechazó la escritura; sin cambios en vivo (alerta 6318733204)~~ → **aplicada a mano por el propietario** (confirmación 6319443716) y **verificada en solo lectura** (§5). B) `RolePolicy` + `PrivilegedRoleDefinitionGuard`; BASE `66f5ce8`, TESTED `b51fa3e` | Instrucción 6318625636; ~~código listo para revisión~~ → **código aceptado en 6319407886 (REVIEW_HEAD `97737ba`)**; **sin desplegar** |
| SEC-02 general | Reportes, archivos y caché | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-03 general | Identidad, sesiones y entradas | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-04 | Dependencias, integraciones e infraestructura | Pendiente | No cerrado |
| SEC-05 | Regresión consolidada y riesgos residuales | Pendiente | No cerrado |

## 4. Registro de hallazgos

Las severidades son las comunicadas por la ronda, sujetas a revisión. Una configuración observada o una hipótesis no equivale a una explotación demostrada.

| ID | Ronda / severidad | Hallazgo resumido sin mecanismo sensible | Confirmación | Corrección / decisión | Estado y siguiente paso |
|---|---|---|---|---|---|
| F-SEC01-01 | R1 / Alta | Capacidad de suplantación disponible a un actor sin autoridad suficiente | Reproducido en aislamiento | Restricción de la capacidad en servidor al rol autorizado; un archivo de runtime | Reparación aceptada en `0139e95`; **desplegada y verificada (solo lectura) en `cf01e9c`, 2026-10-21** |
| F-SEC01-02 | R1 / Alta, configuración | Delegación de administración de privilegios que requiere revisión | Permiso y acceso al editor observados; no confundir con demostración de toda cadena de escalación | Propuesta de mínimo privilegio; sin cambios en roles compartidos | **Parcial.** Faceta de escritura de cuentas corregida en R2 (ver F-SEC01-02a). El editor de roles (RoleResource) y la configuración de autoridad de concesión siguen como decisión del propietario. **R3 (2026-10-21): demostrado en aislamiento como F-SEC01-R3-04 (Alta, alerta), un camino que se salta las guardas desplegadas**; propuesta en el informe R3. El hallazgo NO se cierra |
| F-SEC01-02a | R2 / Alta | Un actor con gestión de usuarios podía otorgarse o asignar el rol con privilegios al crear/editar una cuenta, o editar una cuenta privilegiada | Reproducido en aislamiento; prueba de frontera de persistencia (el rol prohibido nunca se escribe en el pivote) | Autorización en servidor ANTES de la sincronización de la relación de roles (`PrivilegedRoleGuard` en `saveRelationshipsUsing`); bloqueo de apertura de cuenta privilegiada en `authorizeAccess` | Corregido en candidato R2; aceptado (6310331211 FINAL); **desplegado y verificado (solo lectura) en `cf01e9c`, 2026-10-21**; atomicidad solo para la conexión única actual |
| F-SEC01-03 | R1 / Baja–media, configuración | Permisos de escritura en catálogos globales para un rol de afiliación | Hipótesis derivada de permisos; no ejecutada | Confirmar necesidad y efecto antes de cambiar | ~~Pendiente de prueba y decisión~~ → **R3: efecto confirmado en aislamiento** (sonda restringida: el rol de afiliado puede abrir el alta de los dos catálogos). Propuesta: solo cambio de datos de roles (quitar esos dos permisos de alta); decisión del propietario |
| F-SEC01-04 | R1 / Baja | Superficie pública de prueba sin función de negocio vigente | Alcanzabilidad confirmada por inventario | Retirada (ruta, clase y vista) con regresión | **Corregido en candidato R2**; desplegado y verificado en `cf01e9c` (2026-10-21: `/form` → 404, ruta y clase ausentes) |
| F-SEC01-05 | R1 / Media provisional | Posible exposición excesiva de datos mediante una acción de cuentas | **No reproducido en el alcance probado** (el único método invocable devolvía datos al framework, no al navegador; el caso de contraseña vacía ya lo maneja el formulario) | Se retiró el método redundante; edición con contraseña vacía conserva el hash (probado) | Negativo acotado; no refutado globalmente. El indicio de campo de verificación (F-SEC01-07) permanece para SEC-03 |
| F-SEC01-06 | R1 / Regla de negocio | Autoridad para desvincular a otros usuarios de una empresa sin definición suficientemente explícita | Comportamiento observado | Definir autoridad de gestión de usuarios por empresa | Decisión pendiente; no modificar vínculos reales |
| F-SEC01-07 | R1 / Indicio | Campo de verificación de cuenta susceptible de asignación a nivel de modelo | No demuestra acceso desde una petición manipulada | Probar escritores reales de cuenta y campos protegidos | R2; no declarar bypass sin reproducción |
| F-SEC01-08 | R1 / Informativa provisional | Archivos históricos de diagnóstico en el repositorio | Existencia observada; entrega web no comprobada | Evaluar exposición, contenido y saneamiento | SEC-04; no afirmar ausencia de secretos por coincidencia de palabras |
| R-SEC01-01 | R1 / Fiabilidad | Formularios de empresa fallan si no hay ciudades | Confirmado en aislamiento; antecedente conocido | Reparación independiente pendiente | No cerrado |
| Q-SEC01-01 | Revisión R1 / Calidad de evidencia | Parte de la matriz usa el propio gate como expectativa | Confirmado por revisión del test | Añadir expectativas de negocio independientes | R2 |
| **F-SEC01-R3-01** | R3 / Alta | La página de edición de empresa y sus gestores de relación no volvían a comprobar el acceso después de abrirse: una página abierta (o una copia guardada) seguía escribiendo después de perder el vínculo o el permiso de edición | Reproducido en aislamiento (falla antes en `9fb0469`) | Re-autorización en cada petición (`hydrate`) y al montar; 404 | **Corregido en candidato R3** (`e95169c`); sin desplegar |
| F-SEC01-R3-02 | R3 / Media | Algunas acciones de módulos de empresa no aplican el permiso de escritura propio del módulo | Confirmado en aislamiento (sonda restringida, 2 módulos) | Autorizar esas acciones con el permiso del módulo; corrección pequeña con regresión | Registrado; siguiente ronda (el presupuesto de R3 se usó en R3-01) |
| F-SEC01-R3-03 | R3 / Baja | El alta de categorías de una empresa acepta, por id forjado, una categoría inactiva | Confirmado en aislamiento (sonda restringida) | Filtrar por categoría activa en el servidor; corrección pequeña con regresión | Registrado; siguiente ronda |
| **F-SEC01-R3-04** | R3 / **Alta, alerta al propietario** | Un titular no super admin del permiso de edición de roles puede volverse privilegiado y saltarse las dos guardas desplegadas (suplantación y concesión de roles), porque el editor de roles no protege la definición del rol privilegiado | Confirmado en aislamiento (sonda restringida). Roles reales tienen ese permiso (instantánea restringida): **riesgo vivo** | Mitigación inmediata (dato de roles, acción del propietario): quitar los permisos de gestión de roles a todo rol que no sea super admin. Regla de código: solo un super admin administra roles; definición del rol privilegiado protegida (detalle en notas restringidas) | **Abierto**; forma parte de F-02. **2026-10-21 (R4):** regla del propietario: solo super_admin administra roles. Corregido en candidato R4 (`b51fa3e`), sin desplegar. Contención en datos vivos **bloqueada** (sin cambios); **el riesgo vivo sigue**. **2026-10-21 (posterior):** contención aplicada a mano por el propietario y verificada en solo lectura (0 concesiones fuera de super_admin; caché coherente). Código aceptado (`97737ba`), **sin desplegar**: sigue **abierto** hasta el despliegue |
| Q-SEC01-R3-05 | R3 / Gobierno | Significado ambiguo del origen «Confirmada» (`validated`): el manual dice que la empresa confirma sugerencias, pero el campo de aprobador describe una aprobación de la Cámara | Lectura de código y manual | Definir la regla | Decisión del propietario; sin cambios |
| Q-SEC01-R3-06 | R3 / Higiene | Archivo `error_log` de PHP versionado en directorios de la aplicación desde el primer commit | Inventario | Revisarlo y retirarlo en una ronda de higiene | Registrado |
| **F-SEC01-R4-01** | R4 / Media, integridad de permisos (disponibilidad) | Al guardar un rol, el editor de roles de Shield sincroniza solo los permisos que muestra. Como la pestaña de permisos personalizados está desactivada, **quita sin avisar** los permisos que no aparecen en el formulario (116 en staging; 73 se usan en el código) | Confirmado en solo lectura tras la contención manual, contra la instantánea restringida del 2026-10-20. ~~el rol sin usuarios perdió 115 permisos ocultos además de los de Role, y el rol con 1 usuario perdió 1 que la app comprueba (gestión de usuarios)~~ → **atribución corregida (6320241495):** el defecto quitó **109 permisos ocultos, todos en el rol sin usuarios**; el rol con 1 usuario no tenía ocultos y no perdió ninguno por el defecto. Además se desmarcaron casillas **visibles** fuera de Role: 6 de gestión de usuarios en el rol sin usuarios y 1 (lista de usuarios) en el rol con 1 usuario. Nada se añadió; super_admin, el resto de roles y las membresías no cambiaron | Decisión del propietario: restaurar o no lo perdido (lista exacta restringida; restaurar requiere aprobación). Antes de volver a guardar un rol en el editor: no guardar super_admin (tiene 116 ocultos) hasta decidir la corrección (mostrar los permisos personalizados o conservar los no mostrados) | **Abierto**; no es una exposición: solo reduce acceso. **2026-10-21:** corrección en candidato (`fix/sec-01-r4-01-role-editor-preserve`, TESTED `24413d5`), **sin desplegar**; propuesta de recuperación preparada (§5), **sin restaurar** |
| Q-SEC01-02 | Revisión R1 / Calidad de evidencia | Errores inesperados y ciertos accesos legítimos no siempre hacen fallar la matriz | Confirmado por revisión del test | Fallar ante 5xx/excepciones inesperadas, comprobar caminos permitidos y actor privilegiado representativo | **Atendido en R2** |
| RT-PHP-2026-10 | Runtime / Crítica (CVE-2026-109618, CVE-2026-109619, publicados 2026-10-21) | El runtime PHP de la app de staging (8.2.34, php-fpm) está por debajo del parche 8.2.35 | Medido en el contenedor en ejecución (versión CLI y FPM), 2026-10-22 | Solo runtime: rama `security/php-8.2.35-base` @ `1d3a16e` (padre `cf01e9c`), **diff únicamente en `Dockerfile`** (base fijada por digest a 8.2.35). Imagen candidata idéntica a la desplegada salvo PHP; suite aislada 611/7042 en `cf01e9c` con 8.2.35 | **Desplegado** el 2026-10-22: el servidor quedó en `1d3a16e`, con PHP 8.2.35 en CLI y FPM. **Aceptado por el propietario el 2026-10-26** (pruebas manuales PASS). Seguimiento en el repositorio privado de administración (Issue #1, etapa 3). Sin migraciones, código de aplicación ni R3/R4. El runner, con la base fijada a 8.2.35, está integrado en el servidor de pruebas y su rama está pendiente de push del propietario |
| RT-BUILD-2026-10 | Higiene de build / Alta (configuración embebida) | Sin `.dockerignore`, la imagen se construía desde el checkout del servidor e incluía archivos no versionados (`.env`, `.git`). Las imágenes anteriores conservan esa configuración en sus capas | Medido en las capas de la imagen en servicio, 2026-10-26 (solo conteos) | Rama `build/source-only-image` (base `1d3a16e`): `.dockerignore` estricto, más una guarda en el `Dockerfile` que detiene la build si entra un archivo prohibido. En la candidata, 0 nombres prohibidos y 0 valores secretos en capas; código, vendor y lock idénticos a lo desplegado. Ver `docs/deploy/IMAGEN_Y_SECRETOS.md` | **Candidato en revisión, sin desplegar** (2026-10-26). Las imágenes antiguas quedan restringidas al servidor hasta la decisión del propietario sobre su ciclo de vida |
| RT-MAIL-TLS-2026-10 | App / Media, seguridad del transporte de correo | La configuración SMTP de la app (`config/mail.php`, `mailers.smtp.stream.ssl`) declaraba la verificación del certificado y del nombre del servidor desactivada (`verify_peer=false`, `verify_peer_name=false`, `allow_self_signed=true`) | Confirmado en el código. Con Laravel 12.69 y `symfony/mailer` 7.4.17, Laravel no pasa `stream` al transporte, así que el bloque **no tenía efecto**. Lo prueba un control negativo en local, y en vivo el transporte no tiene opciones SSL. La opción que sí desactiva la verificación (`verify_peer` en el primer nivel) nunca estuvo puesta. El relay presenta un certificado válido (TLS 1.3) | Commit `e49cbac` (rama `fix/mail-tls-verify`): quita el bloque y agrega `tests/Feature/Security/MailTransportTlsTest.php`. La prueba vigila la configuración y el transporte, e incluye un control de que detecta `verify_peer=false`. 3/3 en local; sin envío de correo | **Corregido y desplegado** el 2026-10-26 16:41Z en staging, con el workflow de despliegue (ejecución exitosa). Verificación de solo lectura posterior: app con PHP 8.2.35, `.env` ausente de la imagen, configuración en caché sin `stream` ni `verify_peer`, HTTP 200 y 0 errores nuevos. No se hizo prueba de envío. Registrado por la revisión 6416153795 (repositorio privado de administración) |
| RT-MAIL-2026-10 | Funcionalidad / Correo del sistema | El correo estaba en modo simulado (`log`): la verificación de cuentas y los avisos de solicitudes no salían | — | Activación autorizada por el propietario: transporte `smtp` global con remitente del dominio; el único cambio fue regenerar la caché de configuración (solo cambiaron claves `mail.*`) | **Activado** el 2026-10-26. Una prueba controlada fue aceptada por el proveedor y el propietario confirmó que llegó al buzón. Detalle en `docs/deploy/CORREO_DEL_SISTEMA.md` (rama `build/source-only-image`) |

En R2 SÍ se estableció y corrigió un fallo de inyección de roles en cuentas (F-SEC01-02a). NO se estableció traversal de directorios en las superficies probadas (segmento de id numérico y `secciones` con lista blanca): resultado negativo acotado, no certificación general.

### Revisión R2 (6310331211) — correcciones solicitadas y su estado en esta entrega

| ID | Petición | Estado |
|---|---|---|
| R2-REV-01 | Autorizar la concesión de roles ANTES de persistir; no depender de limpieza posterior; conectar o retirar helper muerto; frontera/atomicidad | Hecho: autorización en `saveRelationshipsUsing` antes de `sync()`, dentro de la transacción de Filament; prueba de frontera de persistencia; helper muerto retirado |
| R2-REV-02 | Aserciones de traversal sobre texto de PDF decodificado; respuesta exacta de denegación; retirar objetivos de SO; capa de denegación e integridad del canario | Hecho: `pdftotext` sobre PDFs; 404 exacto «Archivo no disponible» con 0 consultas/render; sin objetivos de SO; capa registrada; canario intacto |
| R2-REV-03 | Expectativas independientes en la matriz; fallar 5xx inesperados (JoinViews contado); actor privilegiado representativo; declarar filas solo-gate | Hecho: expectativas de negocio allow/deny en entradas de alto valor; 5xx inesperado falla salvo JoinViews; barrido completo declarado como caracterización |
| R2-REV-04 | Registro acumulativo con estado; separar aceptado de desplegado; ayuda de administrador | Este documento; comportamiento de staff descrito en §5 R2 |
| R2-REV-05 (segunda revisión) | Habilitar explícitamente transacciones en `CreateUser` y `EditUser` sobre la conexión real de cuentas/pivote; pruebas de inyección de fallo con rollback antes del teardown; conservar la autorización previa a la sincronización | ~~PENDIENTE — no ejecutada~~ (estado al 2026-10-21 ~10:15Z). **Corregida en candidato** (`f4dd8fb`, entrega posterior): transacción explícita por página en ambas, sobre las conexiones de cuenta y pivote; inyección de fallo verificada antes del teardown. ~~No aceptada; sin desplegar~~ → **aceptada** (6310331211 FINAL) y **desplegada** en `cf01e9c` (2026-10-21), verificada en código y configuración; atomicidad solo con una conexión |

**Nota fechada 2026-10-21 (cierre de sesión 6313400617).** La segunda revisión de 6310331211 pide una corrección acotada de atomicidad. Motivo: Filament v3.3.55 (versión fijada) deja las transacciones del panel **desactivadas** por defecto y este candidato no las activa en sus páginas ni en el proveedor del panel. Por eso **se retira** la afirmación anterior de que la reparación «ya era atómica / dentro de la transacción de Filament» (aquí y en §5 R2): era incorrecta. La autorización previa a la sincronización sigue siendo válida y se conserva. La pausa se debe a la cuota de sesión del propietario; la corrección no se retiró y es la próxima tarea de código. Progreso revisado que se mantiene: guarda de privilegios previa a la sincronización; pruebas de rutas con canario y texto de PDF; matriz independiente de alto valor y fallo ante errores inesperados; este registro.

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
- **Actualización 2026-10-21 (segunda revisión):** CAMBIOS SOLICITADOS. La frase «dentro de la transacción de create/edit de Filament» de la reparación F-SEC01-02a queda retirada (ver nota fechada en §4): con Filament v3.3.55 las transacciones del panel están desactivadas por defecto y este candidato no las activa. Corrección de atomicidad (R2-REV-05) pendiente, no ejecutada; pausa por cuota. REVIEW_HEAD `51a923e`; sin desplegar.
- **Actualización 2026-10-21 (R2-REV-05 ejecutada, sesión nueva):**
  - **Configuración:** `UsesAccountWriteTransaction` (en `CreateUser` y `EditUser`) habilita las transacciones solo en esas dos páginas (`hasDatabaseTransactions()`; el valor por defecto del panel no cambia). Abre/confirma/revierte en las conexiones reales del modelo `User` (cuenta) y del modelo de rol (pivote `model_has_roles`); hoy son la misma.
  - **Pruebas de inyección de fallo** (`AccountWriteAtomicityTest`), con el estado verificado tras la petición fallida y antes del teardown, más instrumentación (eventos de inicio por conexión, nivel de anidamiento):
    - crear con fallo tras el pivote → no queda cuenta ni vínculo de rol;
    - editar con fallo tras actualizar la cuenta → se restauran nombre, correo y roles previos;
    - operaciones completas → persisten;
    - rol privilegiado forjado → nunca se escribe.
  - **Antes** (`309146b`, solo pruebas): 3 de 5 fallan. **Después** (`f4dd8fb`): ver informe.
  - **Garantía probada:** la reversión ante una excepción dentro de la petición en esas dos páginas. No cubre caídas del proceso o de la base de datos fuera de la transacción.
  - **Estado:** corregida en candidato; no aceptada; sin desplegar.

### Despliegue SEC-01 R1+R2 (6316265832) — 2026-10-21

- **Aceptación:** 6310331211, sección «FINAL R2 REVIEW — ACCEPTED FOR DEPLOYMENT PREPARATION» (REVIEW_HEAD `cf01e9c` aceptado; TESTED `f4dd8fb`). R2 y R2-REV-05 quedan **aceptadas**; la cronología de revisiones anterior se conserva.
- **Autorización:** 6316265832 (propietario), solo para este SHA. No autoriza cambios de roles/permisos ni cierra el programa.
- **Preflight (solo lectura):**
  - rama remota y checkout de staging en `87dd997`, sin cambios en archivos versionados (6 carpetas no versionadas de assets publicados por el propio despliegue, sin código);
  - `cf01e9c` es fast-forward de `87dd997`. El diff de runtime son solo los 9 archivos aceptados (F-01, guarda, páginas, trait, retiro de `/form`), sin `database/`, composer, config, docker ni workflow;
  - sin migraciones pendientes; sin despliegue en curso; app y nginx arriba; debug y mantenimiento apagados;
  - **misma conexión:** cuenta y rol/pivote resuelven ambas a `pgsql`.
- **Recuperación:** imagen en ejecución `f91e97322189` etiquetada `perfilafiliados-app:pre-sec01-87dd997` antes de promover. No hizo falta.
  - Procedimiento: retag de esa imagen a `latest`, `git reset --hard 87dd997` en el checkout y `docker compose up -d app`.
- **Despliegue:** push normal de `cf01e9c` a `feature/upgrade-filament-v3` (sin force). Workflow «Deploy a Contabo» run 39602544527, **success**, 12:48–12:50Z.
- **Post-despliegue, runtime:** checkout `cf01e9c` limpio; imagen `081c35c5715d`; app reiniciada y nginx arriba; sin migraciones pendientes.
- **Post-despliegue, HTTP público (peticiones reales, anónimas):** `/admin/login` 200; `/form` 404; URL desconocida 404; descarga antigua 302 → login.
- **Post-despliegue, smoke de seguridad** `docs/deploy/smoke_sec01_readonly.php`: **17/17**, frente a 3/17 en el mismo runtime antes de promover. Corre en una transacción READ ONLY revertida, con usuarios solo en memoria; no envía peticiones ni escribe nada. Distingue fuente/configuración de ejecución en memoria:
  - *configuración:* debug y mantenimiento apagados; cuenta y pivote en la misma conexión;
  - *fuente:* `User::canImpersonate()` instalado; el campo de roles sincroniza mediante `PrivilegedRoleGuard::authorizedRoleIds` antes del `sync()`; `CreateUser` y `EditUser` usan `UsesAccountWriteTransaction`; la clase `Form` antigua no existe;
  - *ejecución en memoria:* sin roles o con un rol ordinario no se puede suplantar; con super admin sí. Sin actor super admin, el rol privilegiado pedido se quita antes de sincronizar. `hasDatabaseTransactions()` es true en ambas páginas y la transacción cubre exactamente la conexión de la cuenta. No hay ruta `/form`.
- **Smokes previos (solo lectura):** exportación 25/25, 0010C 18/18, perfil completo 13/13.
- **Escrituras y rollback:** NO se probaron en staging (prohibido). La evidencia de atomicidad son las pruebas aisladas de inyección de fallo (`f4dd8fb`: enfocadas 45 OK, suite 611 OK) más la verificación de código y configuración desplegados.
- **Limitación de conexión única:** la atomicidad vale para la configuración actual, con cuenta y pivote en una sola conexión. Abrir y confirmar transacciones en varias conexiones **no** forma una transacción distribuida. Si la configuración separa esas escrituras, hace falta un invariante que falle cerrado o una arquitectura revisada aparte; no se afirma atomicidad entre bases.
- **Resuelto en runtime (dentro de lo comprobado):** F-SEC01-01, F-SEC01-02a, F-SEC01-04, R2-REV-05.
- **Pendiente:** UAT del propietario con sus cuentas, el clic real de suplantación por un super admin y los flujos de escritura en staging. Siguen abiertos F-02 (editor de roles/configuración), F-03, F-06, 20/23 relation managers, la búsqueda global en 12/13 recursos y SEC-02..05. **No se afirma que el sistema sea seguro.**

### Validación del propietario de la entrega desplegada — 2026-10-21

- **OWNER_UAT: PASS.** Fuente: confirmación del propietario en el chat, del 2026-10-21, registrada en el Issue #2 `6316775771`. No se infieren resultados por acción ni de navegador más allá de esa confirmación, y no hubo una prueba de navegador del revisor.
- **Qué cierra:** la espera de UAT de la entrega desplegada (`cf01e9c`: F-SEC01-01, F-SEC01-02a, F-SEC01-04, R2-REV-05). **No cierra SEC-01 ni el programa.**

### SEC-01 R3 — relaciones de empresa, búsquedas prioritarias y decisiones de permisos (6316775771)

- **Rama / BASE:** `audit/sec-01-r3-company-boundaries` desde `eb03b4c` (el runtime es igual al desplegado `cf01e9c`).
- **TESTED_HEAD:** `e95169c`; REVIEW_HEAD en el comentario de entrega.
- **Fails-before:** rama de evidencia `audit/sec-01-r3-fails-before` @ `9fb0469`.
- **Reparación F-SEC01-R3-01 (Alta), corregida en candidato:**
  - `EmpresaAccess::mayEdit()` aplica las condiciones de la propia página de edición, leídas en el momento: autenticado, verificado, vinculado ahora a la empresa y con permiso para editarla;
  - el trait `ReauthorizesEmpresaAccess` repite esa comprobación en cada petición Livewire (`hydrate`) de `EditEmpresa` y de los 10 gestores de relación de Empresa; los gestores también la hacen al montarse;
  - el rechazo es el mismo 404 que da una empresa ajena;
  - antes, una página abierta mientras el usuario estaba vinculado seguía escribiendo después de revocarle el vínculo o el permiso de edición.
- **Pruebas:**
  - antes (`9fb0469`): fallan exactamente las 3 pruebas de re-autorización;
  - después (`e95169c`): suite completa **624 OK** (7.201 aserciones), en el primer intento.
  - Cinco fixtures existentes recibieron el permiso de edición de empresa que exige la página que contiene esas pestañas. Una prueba existente acepta ahora el 404 además del 403 (oráculo R2-REV-03).
- **Matrices:** 7 gestores (permitido / denegado, claves y ids de otra empresa, ids forjados, catálogos compartidos sin cambios, gobierno de la taxonomía, revocación después de montar) y 3 búsquedas (Customer, TaxonomyCandidateTerm, Role). Detalle en el informe.
- **Comportamiento visible:** ninguno para un usuario con acceso. Una página que quedó abierta después de perder el acceso responde «no encontrado» en su siguiente acción. El manual no cambia.
- **Informe:** [security_sec01_r3_2026-10-21.md](../../audit/security_sec01_r3_2026-10-21.md).
- **Estado:** listo para revisión; no aceptado; sin desplegar.
- **Actualización 2026-10-21 (revisión 6318588005):** R3 **aceptado** (REVIEW_HEAD `66f5ce8`), **NO desplegado**.
- **Corrección de cobertura (pedida en 6318588005):** la revocación después de montar se ejecutó en estos casos concretos:
  - con el vínculo revocado: guardado de cabecera en Assets y en Management, borrado en Experiences, Presence y Sustainabilities, quitar en TaxonomyCategories, y actualizar un campo y guardar en la página de la empresa;
  - con el permiso de edición retirado: **solo** el guardado de Management y el borrado de Presence (no Assets, como decía la tabla de R3);
  - con el gestor ligado a otra empresa: los siete, al montarse.

### SEC-01 R4 — administración de roles exclusiva de super_admin (6318625636)

- **Regla del propietario (2026-10-21, vinculante):** solo puede administrar roles un usuario que tenga **hoy** el rol `super_admin`. Ni un permiso genérico ni el acceso a la gestión de usuarios lo exceptúan. Un rol menor no puede alterar la definición del rol privilegiado.
- **A. Contención (datos vivos): BLOQUEADA, sin ningún cambio.**
  - **Qué pasó:** el clasificador de permisos de la plataforma del ejecutor rechazó la única escritura autorizada. No se rodeó. Se alertó en `6318733204`.
  - **Verificación previa** (solo lectura, sobre `cf01e9c`, `pgsql`, guard `web`):
    - conjunto de permisos derivado de Shield y de `RolePolicy`: 12 nombres, 6 existentes;
    - fuera de super_admin: **2 roles, 12 asignaciones** (con 1 y 0 usuarios), **0 permisos directos** y ningún rol con nombre parecido en otro guard;
    - continuidad: el rol super_admin conserva los 6 permisos, y sus **4 cuentas verificadas** administran roles a través de ese rol.
  - **Acción del propietario:** en la app, como super_admin: **Roles** → cada rol que no sea super_admin → desmarcar sus permisos de **Role** → guardar. Alternativas: permitir esta escritura al ejecutor, o ejecutar el script revisado que se entrega por canal privado.
  - **Restringido:** la instantánea previa, el script y el plan inverso están fuera de git. Cualquier restauración requiere aprobación.
- **B. Reparación de código (rama `fix/sec-01-r4-role-administration`, BASE `66f5ce8`, TESTED `b51fa3e`), sin desplegar:**
  - **`RolePolicy`:** todas las habilidades exigen tener super_admin, leído en el momento con una consulta fresca, **y** el permiso explícito. Así, una página abierta antes de perder el rol queda rechazada en su siguiente petición, aunque el usuario conserve permisos directos.
  - **`PrivilegedRoleDefinitionGuard`:** en el límite del modelo, el rol privilegiado no se puede renombrar, cambiar de guard ni eliminar, y ningún otro rol puede tomar su nombre. Sus permisos siguen siendo editables por un super_admin.
  - **Límite preciso (precisión pedida en 6319407886):** protege las escrituras de Eloquent que disparan eventos de modelo. No es una restricción de base de datos y no intercepta SQL directo, actualizaciones o borrados masivos del query builder, `saveQuietly` ni `withoutEvents`.
- **A'. Contención manual: CONFIRMADA por el propietario (6319443716) y VERIFICADA en solo lectura** (2026-10-21 ~15:20Z, runtime `cf01e9c`, transacción `READ ONLY`, sin escrituras ni cambios de caché):
  - **Recalculado, sin reutilizar la instantánea de 12 filas:** 12 nombres derivados de Shield, 6 existentes, 0 comodines.
  - **Fuera de super_admin: 0 asignaciones a roles** (antes 12, en 2 roles) y **0 permisos directos a usuarios**.
  - **Continuidad de super_admin: PASS.** Un rol super_admin con los 6 permisos; sus **4 cuentas verificadas** pasan todas las comprobaciones de administración de roles.
  - **Caché y acceso:** almacén `file`, entrada presente y **coherente con la base** (0 pares fuera de super_admin, 6 en super_admin). Por tanto, los guardados de la interfaz la invalidaron y se reconstruyó. En un proceso nuevo, que es lo que ve la siguiente petición, el gate desplegado da **0 de 325** usuarios que no son super_admin con alguna habilidad de administración de roles. Staging responde 200.
  - **Sin cambios:** 5 roles, 329 membresías, 366 usuarios; super_admin y los otros dos roles intactos.
  - **Efecto colateral:** F-SEC01-R4-01 (§3). La diferencia exacta está en las notas restringidas.
- **Pruebas:**
  - antes (`4953be6`): fallan 4 de 5 pruebas, las 4 protecciones; la vía permitida de super_admin pasa;
  - el oráculo de la matriz (lista de roles) se actualizó a la regla del propietario;
  - suite completa en `b51fa3e`: **629 OK** (7.344 aserciones), en el primer intento.
- **Ayuda de administración:** `docs/manual_usuario/manual_administrador.md` §9.1. El manual del afiliado no cambia.
- **Informe:** [security_sec01_r4_2026-10-21.md](../../audit/security_sec01_r4_2026-10-21.md).
- **Riesgo residual:** ~~**alto y vivo** en staging hasta que se aplique la contención~~ → **contenido en los datos** (verificado). El código de R4 (aceptado, `97737ba`) **no está desplegado**: hasta entonces, si alguien vuelve a otorgar a otro rol los permisos de Role, el camino reaparece. Contención y código son protecciones distintas, y hacen falta las dos. SEC-01 sigue abierto.

### SEC-01 F-SEC01-R4-01 — el editor de roles conserva lo que no muestra (6320241495)

- **Causa exacta (Shield 3.9.10 instalado, sin editar vendor):**
  - `EditRole::afterSave` y `CreateRole::afterCreate` hacen `firstOrCreate` con cada valor enviado y luego `syncPermissions` con **exactamente** esa lista. Lo que el formulario no muestra se desvincula.
  - Las casillas son las opciones de los campos visibles: los prefijos de cada recurso registrado, las páginas y widgets no excluidos, y los personalizados **solo** si esa pestaña está activa. Aquí está desactivada.
  - En staging hay 116 permisos sin casilla. Son nombres de recursos que ya no están registrados en el panel o con otro identificador, `export_*`, `taxonomy_*` y páginas o widgets antiguos. Las políticas de la app usan 73 de ellos.
  - `CheckboxList` no valida los valores contra sus opciones, así que un nombre forjado se creaba y se concedía (tras R4, solo un super_admin llega a esa vía).
  - Activar la pestaña de personalizados **no** basta: mostraría todo permiso que no sea de entidad, y seguiría creando lo forjado.
- **Corrección** (rama `fix/sec-01-r4-01-role-editor-preserve`, desde `f41bcb6`):
  - **Recurso de la app:** `App\Filament\Resources\Shield\RoleResource` es el recurso publicado, la vía que Shield admite. Conserva el formulario, la tabla, la dirección (`shield/roles`) y las etiquetas, y cambia solo las páginas.
  - **Regla de escritura:** final = (enviado ∩ opciones que el formulario muestra ahora, calculadas en el servidor) ∪ (permisos que el rol tiene **ahora** fuera de ese conjunto).
    - Se lee en el momento, con la fila del rol bloqueada.
    - Solo se crea un nombre que el formulario muestra, y con el guard guardado del rol.
  - **Transacción:** el rol y sus permisos son una sola transacción sobre las conexiones de rol y de permiso. La caché de permisos se vacía otra vez después del commit.
  - **Sin cambios:** R4 (solo un super_admin vigente administra roles), la protección de identidad del rol privilegiado y las asignaciones de cuentas.
- **Pruebas** (`RoleEditorPermissionPreservationTest`, 7):
  - el panel sirve solo el editor de la app;
  - guardar sin cambios en un rol común y en el privilegiado;
  - quitar y agregar una casilla visible conservando las ocultas;
  - parte oculta leída en el momento: no se repone lo quitado ni se pierde lo agregado desde que se abrió la página;
  - nombres forjados, ids, nombres ocultos en un campo visible y un campo inventado: nada se crea ni se concede;
  - página abierta como super_admin después de perder el rol;
  - fallo inyectado después de desvincular y vincular: se revierten el rol y cada vínculo;
  - en todas, conjuntos exactos, número de filas `Permission` y membresías.
- **Resultados:**
  - **antes de la corrección** (`44da9a3`, páginas que solo heredan): **fallan 5 de 6**; la revocación ya la cubría R4;
  - **enfocadas** en `24413d5` (seguridad, suplantación, usuarios, exportación, verificación, alta): **74 OK**;
  - **suite completa** en `24413d5`: **636 OK** (7.388 aserciones), en el primer intento.
- **Recuperación: solo PROPUESTA, nada restaurado.** El delta exacto está en notas restringidas.
  - **Rol con 1 usuario** (7 perdidos): 6 de administración de roles, que **nunca** se restauran, y 1 de lista de usuarios (visible). Propuesta: restaurar ese 1 **solo si** el propietario confirma que quitarlo no fue intencional.
  - **Rol sin usuarios** (121 perdidos):
    - 7 de administración de roles: **nunca**;
    - 6 visibles de gestión de usuarios (1 de lectura y 5 de escritura). La escritura permite asignar roles no privilegiados, así que solo se restauran con una intención explícita;
    - 66 ocultos **activos**: CRUD de módulos de empresa y de catálogos geográficos e infraestructura. Escribir en catálogos globales es la clase de riesgo de F-03;
    - 42 ocultos **sin referencia** en el código (`export_*` y páginas o widgets antiguos): no se restauran.
    - Propuesta: **0 ahora**, porque no tiene usuarios. Si el rol vuelve a usarse, se decide por bloques.
  - **Requisitos de cualquier restauración:** aprobación del propietario, **después** de desplegar esta corrección (si no, el siguiente guardado lo vuelve a borrar), con un script revisado y transaccional y no con el editor, y vaciando la caché.
- **Informe:** [security_sec01_r4_01_2026-10-21.md](../../audit/security_sec01_r4_01_2026-10-21.md).

## 6. Cobertura pendiente

R1 informó 131 rutas inventariadas y 792 solicitudes sobre 88 entradas con nueve actores. Son conteos de inventario y caracterización, no porcentaje global de seguridad.

| Superficie | Evidencia R1 | Pendiente |
|---|---|---|
| Relation managers | ~~3 de 23 (sin cambio en R2)~~ → **R3 (2026-10-21): 10 probados** (R1: Contacts, Users, Chambers; R3: Assets, Management, Experiences, Presence, Sustainabilities, Services, TaxonomyCategories). **Discrepancia de denominador:** el inventario de R1 dice 23; el recuento en `e95169c` da **24** registros activos (recurso × gestor; Empresa 10 + 14 en otros recursos) | ~~14 por el recuento (13 por el denominador anterior)~~ → **resuelto en R4: el denominador es 24** gestores (pares recurso × gestor). El inventario de R1 mostraba 23 entradas porque `CountryResource` agrupa States y Cities en un `RelationGroup` contado como una sola. **Restan 14**, todos fuera de Empresa |
| Búsqueda global | ~~1 de 13 recursos (sin cambio en R2)~~ → **R3:** 13 en inventario, de los que **12 son buscables de verdad** (RoleResource está desactivado por la configuración de Shield, y ahora se prueba). Probados: Empresa (R1), Customer y TaxonomyCandidateTerm (R3), más Role como no buscable | **9**. Desglose (R4): **12** recursos con búsqueda habilitada; **3** con búsqueda habilitada probada (Empresa, Customer, TaxonomyCandidateTerm); **1** deshabilitado probado como no buscable (Role, que no cuenta como ejecución habilitada); **9** habilitados restantes |
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

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
| Ronda 2 | Instrucción 6310273754 | Asignada; no hay todavía resultados aceptados en este registro |

Cada cambio de estos estados necesita SHA y fuente. No inferir despliegue a partir de un commit en una rama de trabajo.

## 3. Cronología de rondas

| Ronda | Alcance | Corrección / resultado | Revisión y estado |
|---|---|---|---|
| Antecedente: acceso a exportaciones | Descargas por empresa, verificación, permisos, errores y caché | Rutas protegidas, alcance por empresa, identificador validado, XLS limitado y respuestas privadas; rutas antiguas redirigen | Aceptación 6265458863, candidato `ce70b7f`; despliegue comunicado en 6267092324. El residuo de caché previo no quedó acreditado como eliminado |
| SEC-01 R1 | Inventario efectivo, matriz de actores y primeras pruebas de acciones | Una reparación de privilegios; resto de hallazgos registrado | Entrega 6294285848; revisión 6297739082: reparación aceptada, SEC-01 continúa abierto |
| SEC-01 R2 — cuentas y archivos | Inyección de privilegios, creación/edición de usuarios, traversal y expectativas de pruebas | Pendiente de ejecución/entrega verificable | Instrucción 6310273754; sin resultado acreditado |
| SEC-02 general | Reportes, archivos y caché | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-03 general | Identidad, sesiones y entradas | Pendiente, salvo comprobaciones acotadas activadas en R2 | No cerrado |
| SEC-04 | Dependencias, integraciones e infraestructura | Pendiente | No cerrado |
| SEC-05 | Regresión consolidada y riesgos residuales | Pendiente | No cerrado |

## 4. Registro de hallazgos

Las severidades son las comunicadas por la ronda, sujetas a revisión. Una configuración observada o una hipótesis no equivale a una explotación demostrada.

| ID | Ronda / severidad | Hallazgo resumido sin mecanismo sensible | Confirmación | Corrección / decisión | Estado y siguiente paso |
|---|---|---|---|---|---|
| F-SEC01-01 | R1 / Alta | Capacidad de suplantación disponible a un actor sin autoridad suficiente | Reproducido en aislamiento | Restricción de la capacidad en servidor al rol autorizado; un archivo de runtime | Reparación aceptada en `0139e95`; despliegue pendiente de acreditación |
| F-SEC01-02 | R1 / Alta, configuración | Delegación de administración de privilegios que requiere revisión | Permiso y acceso al editor observados; no confundir con demostración de toda cadena de escalación | Propuesta de mínimo privilegio; sin cambios en roles compartidos | Decisión del propietario; R2 comprueba límites de concesión |
| F-SEC01-03 | R1 / Baja–media, configuración | Permisos de escritura en catálogos globales para un rol de afiliación | Hipótesis derivada de permisos; no ejecutada | Confirmar necesidad y efecto antes de cambiar | Pendiente de prueba y decisión |
| F-SEC01-04 | R1 / Baja | Superficie pública de prueba sin función de negocio vigente | Alcanzabilidad confirmada por inventario | Retirada con regresión propuesta y autorizada en R2 | No corregido todavía según evidencia disponible |
| F-SEC01-05 | R1 / Media provisional | Posible exposición excesiva de datos mediante una acción de cuentas | No reproducido | Reproducir antes de aplicar reparación mínima | R2; detalles restringidos |
| F-SEC01-06 | R1 / Regla de negocio | Autoridad para desvincular a otros usuarios de una empresa sin definición suficientemente explícita | Comportamiento observado | Definir autoridad de gestión de usuarios por empresa | Decisión pendiente; no modificar vínculos reales |
| F-SEC01-07 | R1 / Indicio | Campo de verificación de cuenta susceptible de asignación a nivel de modelo | No demuestra acceso desde una petición manipulada | Probar escritores reales de cuenta y campos protegidos | R2; no declarar bypass sin reproducción |
| F-SEC01-08 | R1 / Informativa provisional | Archivos históricos de diagnóstico en el repositorio | Existencia observada; entrega web no comprobada | Evaluar exposición, contenido y saneamiento | SEC-04; no afirmar ausencia de secretos por coincidencia de palabras |
| R-SEC01-01 | R1 / Fiabilidad | Formularios de empresa fallan si no hay ciudades | Confirmado en aislamiento; antecedente conocido | Reparación independiente pendiente | No cerrado |
| Q-SEC01-01 | Revisión R1 / Calidad de evidencia | Parte de la matriz usa el propio gate como expectativa | Confirmado por revisión del test | Añadir expectativas de negocio independientes | R2 |
| Q-SEC01-02 | Revisión R1 / Calidad de evidencia | Errores inesperados y ciertos accesos legítimos no siempre hacen fallar la matriz | Confirmado por revisión del test | Fallar ante 5xx/excepciones inesperadas, comprobar caminos permitidos y actor privilegiado representativo | R2 |

No se ha establecido todavía un fallo de inyección de roles en el registro público ni un traversal general. Son objetivos de R2, no hallazgos confirmados adicionales.

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

### SEC-01 R2

Asignada mediante 6310273754. Al entregar se incorporarán SHA probado/revisado, resultados permitidos/denegados de cuentas, matriz de sinks de archivos con canarios, errores inesperados, hallazgos confirmados y correcciones. No anticipar PASS.

## 6. Cobertura pendiente

R1 informó 131 rutas inventariadas y 792 solicitudes sobre 88 entradas con nueve actores. Son conteos de inventario y caracterización, no porcentaje global de seguridad.

| Superficie | Evidencia R1 | Pendiente |
|---|---|---|
| Relation managers | 3 de 23 | 20 |
| Búsqueda global | 1 de 13 recursos | 12 |
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

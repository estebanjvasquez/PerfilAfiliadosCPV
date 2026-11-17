# E6 — Informe ejecutivo de preparación pre-UAT y plan de acciones

**TASK-0033** · 2026-11-17 · Para: el propietario (decide) y el orquestador (revisa) · Índice: [`00_INDICE.md`](00_INDICE.md)

## 1. Respuesta corta

- **¿Se puede convocar ya al personal de la Cámara?** Todavía no: el veredicto de hoy es **NO-GO**. No es por un defecto
  técnico, sino por **6 preparativos y decisiones de bajo esfuerzo** que solo puede cerrar el propietario (§5). Si se
  cierran, la UAT institucional puede empezar en **1 a 2 días hábiles**.
- **¿Hay algo técnico que la impida?** No. Staging está estable:
  - 92 h sin respuestas 5xx;
  - versiones verificadas;
  - controles de seguridad de SEC-01 y SEC-03 en su sitio.

  CIRA funciona según la UAT del propietario del 17/11. Las condiciones de uso están en E2 §5.
- **¿Y producción?** Está lejos y es **otra decisión**:
  - `main` sigue en septiembre (Filament v2 + MySQL);
  - ninguna corrección de staging está allí;
  - faltan rondas de seguridad, la rotación de credenciales, la conciliación del código del Worker y el plan de migración
    (§4, grupo D).

## 2. Estado por área (verificado hoy salvo indicación)

| Área | Estado | Para la UAT institucional | Para producción |
|---|---|---|---|
| CIRA (buscador) | Funciona; aceptación funcional del propietario con reservas; búsqueda ~10 s, ficha ~5,5 s | Lista, con aviso de tiempos | Faltan: hallazgos privados de n8n, código del Worker fuera de `master`, latencia |
| Evaluación CPV-01..08 | En staging; 1 campaña, 2 invitaciones, 0 evaluaciones | Lista; falta invitar a los evaluadores definitivos | — |
| Perfiles, PDF, descargas | UAT declarada; sin sesión no hay caché | Lista, solo lectura | Falta la prueba con sesión y SEC-02 |
| Entrada y recuperación | Login con Turnstile y correo `smtp`; recuperación desplegada | Solo login para el personal | Falta evidencia formal de correo y RIF; F-SEC03-01 en producción |
| Taxonomía y datos | 191 sin sector principal; 249 vínculos «X»; 3 solicitudes | Las decisiones de la Cámara pueden ir en paralelo | Aplicar las decisiones con gate |
| Seguridad | Ver E2 | Sin bloqueos técnicos; 5 condiciones | Muchos bloqueos (orden P1–P6) |
| Infraestructura | Staging `5e6e3fc` = `feature/upgrade-filament-v3`; BD compartida con datos reales | Regla: no escribir datos reales | Plan de producción sin asignar |

## 3. Hechos nuevos de esta preparación (con evidencia)

1. **Latencia (E4):** los 5 tiempos de navegador del propietario son correctos, a 0,5–0,85 s del tiempo del servidor.
   - El tiempo se reparte entre el **modelo** (~4,2 s, con variación del proveedor) y el **Worker sin caché** (~3,8 s,
     consultas a la BD).
   - El 6,1 s de la copia aislada no se repite en uso real: el benchmark repetía consultas que la caché de Hyperdrive
     (~60 s) acelera.
   - No se observa regresión de V1b ni de la fase B.
2. **Doble presencia I.O.E.D./Geohidra (E5 D3):** la búsqueda por nombre entró como búsqueda de servicio.
   - Geohidra aparece por parecido de significado («offshore» ≈ «costa afuera»).
   - Ambas tienen una experiencia relacionada, por eso salen en las dos vistas.
   - No es un defecto confirmado: es una pregunta de criterio para la Cámara.
3. **Código del Worker:** el código desplegado no está en `master` del Worker, y `master` tiene un commit de septiembre que
   nunca se desplegó. Es un riesgo para producción, no para la UAT.
4. **La Cámara figura como empresa activa** (id 451). Además es la autora de la solicitud de categoría n.º 1, cuyo aviso por
   correo falló.
5. **Monitorización de 24 h de TASK-0026/0027:**
   - las lecturas intermedias y finales no quedaron registradas;
   - la instantánea de hoy (92 h) da PASS en todos los controles;
   - los 3 errores de Laravel desde el 13/11 tienen origen identificado: dos sondas propias y el defecto conocido de
     `JoinViews`;
   - hoy otra sonda propia añadió 1 línea de error, documentada.
6. **Cabeceras de endurecimiento ausentes** (`X-Frame-Options`, CSP, `nosniff`). Es una observación para SEC-02/04, no un
   hallazgo confirmado.

## 4. Clasificación del trabajo

### A — Se prepara o corrige documentalmente antes de la UAT (sin asignación técnica)

| # | Acción | Quién | Esfuerzo estimado |
|---|---|---|---|
| A1 | Revisar y ajustar el paquete de UAT (E3): casos, tiempos esperados, criterios | Propietario | 1–2 h |
| A2 | Decidir sobre la latencia: avisar y medir (L0 + L8) o pedir antes L1 (§6) | Propietario | Decisión |
| A3 | Decidir sobre la monitorización: aceptar la instantánea de hoy o pedir una ventana nueva de 24 h | Propietario | Decisión |
| A4 | Invitar a los evaluadores definitivos (cuentas verificadas, sin roles privilegiados) y fijar las fechas de la campaña | Propietario | 30–60 min |
| A5 | Comunicar a los evaluadores las reglas C1–C5 (no escribir datos reales, etc.) | Propietario | Incluido en E3 §4–§5 |
| A6 | Definir el canal de incidencias y el responsable de la firma | Propietario y Cámara | Decisión |
| A7 | Enviar a la Cámara las preguntas de E5 (versión con nombres, desde la carpeta privada). Va **en paralelo** a la UAT | Propietario | 30 min |
| A8 | Corregir el manual de verificación de correo (CU-02) | Asignación documental | 30 min |

### B — Requiere una asignación nueva y su gate técnico

| # | Acción | Componente | Cuándo | Esfuerzo estimado |
|---|---|---|---|---|
| B1 | L1: saltar el agente en el pedido de ficha `[ID n]` | n8n (copia aislada + staging) | Opcional antes de la UAT | 0,5–1 día, siguiendo el método de TASK-0032 |
| B2 | Evidencia formal de recuperación por correo y RIF (CU-01) | Staging con cuentas y buzones de prueba | Antes de producción | 0,5–1 día |
| B3 | Prueba de descarga autenticada sin caché (EX-01) | Staging, cuenta de prueba | Antes de producción | 2–3 h |
| B4 | Aplicar las respuestas de la Cámara (sector principal, «X», alias) | BD compartida (gate de escritura con conteos) | Después de E5 | 1–2 días por lote |
| B5 | `EXPLAIN` de solo lectura de las consultas del Worker y propuesta L3 | Lectura, luego Worker/BD | Después de la UAT | 1 día de análisis |
| B6 | Configurar y probar la búsqueda semántica de categorías en staging (TX-04) | Laravel y Worker | Antes de producción | 0,5–1 día |
| B7 | Revisiones técnicas pendientes (TASK-0014/15/16, R4, F-02) | Orquestador | Cualquier momento | — |

### C — Requiere decisión de la Cámara

- D1: sector principal (191 empresas).
- D2: servicio «X» (249 vínculos, 119 de prioridad alta).
- D3: criterio de capacidades frente a experiencias.
- D4: glosario y alias.
- D5: si la Cámara figura como empresa.
- D6: 3 solicitudes de categoría.
- La aceptación de CPV-01..08 y el acta (E3 §9).

### D — Bloquea producción

En el orden de E2 §6:
1. **P1:**
   - credenciales y los dos hallazgos privados de n8n;
   - inventario de la clave anónima de la Data API;
   - RT-BUILD;
   - cierre formal de la monitorización y de T24-02.
2. **P2:**
   - R3-02 y T24-01;
   - R4 y F-02;
   - F-SEC03-01 en producción;
   - correo y RIF;
   - descarga autenticada.
3. **P3:** cobertura, SEC-02 y SEC-04, y el equivalente en producción de todo lo de staging.
4. **P4 a P6:** el resto y el cierre con SEC-05.

Además:
- **conciliar las ramas del Worker** (CI-11);
- **plan comparativo de producción**, checklist y ensayo aislado (PR-01/02);
- **fusión a `main`** (PR-03);
- y el gate declarado: seguridad crítica y alta cerrada, UAT completa, aceptación de la Cámara y autorización explícita.

## 5. Criterio GO/NO-GO para **iniciar** la UAT institucional

No es un GO a producción.

| # | Criterio objetivo | Cómo se comprueba | Hoy |
|---|---|---|---|
| G1 | Staging estable: sin 5xx en 24 h, sin mantenimiento, versiones conocidas | `monitor_check.sh` + versiones (E4 §1) | ✅ (VIVO, 92 h) |
| G2 | Funciones centrales aceptadas por el propietario en staging | `6945403230` | ✅ con reservas (rendimiento, relevancia) |
| G3 | Ningún hallazgo abierto explotable con los roles de los evaluadores | E2: T24-01 contenido; F-03 con condición C3; roles de los invitados conocidos | ✅ con condiciones |
| G4 | Evaluadores definitivos invitados, con cuentas verificadas sin privilegios, y campaña con fechas | Panel de campañas (B-01) | ⏳ hay 2 invitaciones; faltan las definitivas |
| G5 | Paquete de UAT revisado y aprobado por el propietario | Comentario en el Issue #2 | ⏳ |
| G6 | Reglas C1–C5 comunicadas a los evaluadores | Incluidas en el material enviado | ⏳ |
| G7 | Decisión sobre la latencia (L0 + L8 o L1) | Comentario del propietario | ⏳ |
| G8 | Decisión sobre la monitorización de 24 h (aceptar la instantánea o pedir una ventana nueva) | Comentario del propietario | ⏳ |
| G9 | Canal de incidencias, responsable de la firma y fechas | E3 §2 y §9 completos | ⏳ |

**Regla:**
- **GO** cuando G1–G9 están en ✅.
- Si G1, G2 o G3 pasan a ❌ antes de empezar (5xx, regresión o hallazgo nuevo que afecte a los roles de evaluador), es
  **NO-GO** y se reevalúa.
- Durante la UAT rigen los criterios de suspensión de E3 §9.

**Veredicto al 2026-11-17: NO-GO**, por G4–G9: decisiones y preparativos del propietario, sin trabajo técnico.

## 6. Secuencia propuesta

```
Día 0-2   A1-A7 (propietario)   ──► GO/NO-GO (G1-G9)
             │ (opcional) B1 si se decide L1 ─┘
Semana 1-2  UAT institucional (E3 parte A + B)   ║   Cámara responde E5 (C)
             └ L8: tiempos reales al cerrar
Semana 2-3  Acta (E3 §9) · triage S1/S2 · B4 (datos de la Cámara, con gate) · B5
Después     Grupo D (seguridad P1-P6, Worker, plan de producción, main) ──► GO producción (decisión aparte)
```

**Cómo se estimó:** son estimaciones razonadas, no compromisos.
- Los esfuerzos de A son lecturas o decisiones sobre documentos ya preparados.
- B1 reutiliza el método de copia aislada de TASK-0032 y cambia un solo nodo.
- B4 depende del volumen de respuestas: 119 vínculos de prioridad alta y 191 sectores.
- El grupo D no se estima aquí: su tamaño depende del plan de producción (PR-01), que todavía no está asignado.

**Dependencias clave:**
- B4 depende de las respuestas a E5.
- La retirada de «X» depende de B4 (prioridad alta primero).
- L3 depende de B5.
- El grupo D depende de PR-01.
- La aceptación de la Cámara (gate de producción) depende del acta.

## 7. Riesgos

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Un evaluador modifica datos reales (BD compartida) | Media | Alto | C1; cuentas sin permisos de edición de catálogo; aviso en E3 §4 |
| Percepción de lentitud (10–14 s) que sesga la evaluación | Alta | Medio | Aviso de tiempos (E3 §5); L1 opcional; registrar el tiempo aparte de la relevancia |
| La Cámara tarda en responder E5 | Media | Medio | E5 va en paralelo; la UAT no depende de E5 |
| Turnstile falla en móvil | Media | Bajo | Aviso de refrescar; registrar el dispositivo |
| Aumenta el tráfico del webhook público de CIRA | Baja | Medio | C5; revisar el volumen al cerrar |
| Confundir el acta de UAT con una autorización de producción | Media | Alto | Texto explícito en E3 §9 y aquí |

## 8. Lo que este paquete no hizo

- **Sin cambios:** no hubo despliegues, ediciones de n8n o del Worker, cambios de prompt o modelo, W1, DDL, escrituras en la
  BD, cambios de taxonomía, roles o empresas, rotación de credenciales, caché, kiosco ni tráfico de producción.
- **Sin ejecuciones nuevas de CIRA:** no se crearon ejecuciones de n8n ni registros de búsqueda; `cira_search_log` sigue en
  142.
- **Lecturas de hoy:** todas fueron `SELECT` en transacciones `READ ONLY`, `GET` y llamadas de solo lectura al Worker (51 en
  total). Una consulta fallida dejó 1 línea en el log de Laravel de staging (documentado).

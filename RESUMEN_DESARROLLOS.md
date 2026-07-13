# Resumen de Desarrollos — CPV Perfil de Afiliados

**Fecha:** 13 de Julio de 2026
**Tarifa aplicada:** **USD $5 / hora**
**Estado general:** 2 desarrollos independientes, ambos en espera de aprobación del cliente.

---

## Índice

| # | Desarrollo | Estado | Horas | Costo ($5/h) |
|---|-----------|--------|-------|--------------|
| **A** | Límite de 2 Sectores + "No Aplica" + % Completitud (wizards) | 90 % implementado | 56.5 – 79 h | **$283 – $395** |
| **B** | CAPTCHA anti-bots (Cloudflare Turnstile) en Registro y Login | No iniciado | 11.5 – 13.5 h | **$58 – $68** |
| | **TOTAL** | | **68 – 92.5 h** | **$340 – $463** |

> Son desarrollos **independientes**: se pueden aprobar, implementar y desplegar por separado.

---

# DESARROLLO A — Límite de Sectores, "No Aplica" y % de Completitud

**Rama:** `feature/limite-sectores-no-aplica` (commit `cbc1e37`)
**Estado:** ✅ 90 % implementado — pendiente 1 bug crítico + QA + despliegue
**Documentos de referencia:** `ESTIMADO_IMPLEMENTACION.md`, `ESTADO_ANALISIS_JUNIO_2026.md`, `PROXIMO_PASOS.md`, `SNAPSHOT_SISTEMA_JUNIO_2026.md`

## Características

### 1. Límite de 2 sectores por empresa
- Se reemplazan los selectores simples por **Sector Principal (obligatorio)** + **Sector Secundario (opcional, distinto)**.
- Cambios en **los 2 wizards** de empresa (Crear y Editar), paso "3 – Operaciones".
- **Validación al guardar:** bloquea si la empresa tiene servicios fuera de sus 2 sectores, con notificación clara indicando qué desvincular.
- **Validación en `ServicesRelationManager`:** impide adjuntar servicios de sectores no permitidos, en tiempo real.
- Impacto: las **~30 empresas legadas** con 3-4 sectores quedarán bloqueadas al editar hasta que ajusten (requiere comunicación al afiliado).

### 2. Botón "No Aplica" por módulo
- La empresa declara explícitamente que un módulo **no aplica** a su actividad; cuenta como "completo" sin obligar a llenar datos falsos.
- Implementado en **5 módulos**: Recursos, Sistemas de Gestión, Experiencia Relevante, Presencia Internacional, Sostenibilidad.
- Clase reutilizable `NoAplicaAction` (evita duplicar código en cada módulo).
- **Sincronización automática:** hooks en 5 modelos que borran el flag "No Aplica" cuando el afiliado carga datos reales.

### 3. % de Completitud del perfil
- Columna **"% de Perfil"** en la tabla de Empresas (visible para la Cámara).
- **Desglose de módulos pendientes** en el formulario de edición ("Pendiente: Recursos, Presencia…").
- Lógica sobre 8 módulos: completo = tiene datos **o** está marcado "No Aplica".

### 4. Reportes y exportaciones coherentes
- **PDF por empresa:** muestra *"NO APLICA — Declarado por la empresa"* en vez de "No existe información" (6+ secciones).
- **9 exports a Excel** convertidos de `FromQuery` a `FromCollection` + trait `AppendsNoAplicaRows`, para incluir las empresas que las vistas SQL omitían (por `INNER JOIN`) pero que sí declararon "No Aplica".

### 5. Infraestructura de base de datos
- 2 migraciones **reversibles**: columnas `sector_principal_id` / `sector_secundario_id` en `empresas` + tabla nueva `empresa_module_status`.

## Entregables

✅ 2 migraciones · ✅ 1 modelo nuevo (`EmpresaModuleStatus`) · ✅ 1 clase reutilizable (`NoAplicaAction`) · ✅ 1 trait reutilizable (`AppendsNoAplicaRows`) · ✅ 2 wizards actualizados · ✅ 5 recursos + 2 relation managers · ✅ 1 blade PDF · ✅ 9 exports · **30 archivos, 681 líneas**

## Estimación de tiempo y costo — Desarrollo A

### A.1 — Trabajo ya ejecutado (implementado en commit `cbc1e37`)

| Concepto | Horas |
|---|---|
| Base de datos (2 migraciones, 1 tabla, 2 columnas) | 2 – 3 |
| Modelos, helpers y hooks de sincronización (5 modelos) | 8 – 10 |
| UI: selectores de sector en 2 wizards + validación `beforeSave()` | 10 – 14 |
| UI: botón "No Aplica" en 5 módulos + clase reutilizable | 10 – 12 |
| Completitud visual (columna en tabla + desglose en formulario) | 6 – 8 |
| PDF por empresa ("NO APLICA" en 6 secciones) | 6 – 8 |
| Exports: conversión de 9 exports + trait `AppendsNoAplicaRows` | 10 – 12 |
| Validación de servicios (attach limitado a 2 sectores) | 6 – 8 |
| QA & verificación (sintaxis, diff, commit) | 3 – 4 |
| **Subtotal ejecutado** | **51 – 69 h** |

**Costo a $5/h: $255 – $345**

### A.2 — Trabajo pendiente (para cerrar y desplegar)

| Fase | Descripción | Horas |
|---|---|---|
| **1 — Bug crítico** | `CreateEmpresa` no tiene `beforeSave()`: una empresa nueva puede guardarse con servicios en >2 sectores. Código de corrección ya redactado. | 2 – 3 |
| **2 — Refactor** | `SustainabilitiesRelationManager` tiene el botón "No Aplica" duplicado a mano; migrar a `NoAplicaAction`. | 0.5 – 1 |
| **3 — Testing** | 22 casos de prueba en staging (sectores, "No Aplica", completitud, PDF, exports). | 2 – 4 |
| **4 — Despliegue** | Migración + deploy a producción + verificación post-despliegue. | 1 – 2 |
| **Subtotal pendiente** | | **5.5 – 10 h** |

**Costo a $5/h: $27.50 – $50**

### Total Desarrollo A

| | Horas | Costo ($5/h) |
|---|---|---|
| Ejecutado | 51 – 69 h | $255 – $345 |
| Pendiente | 5.5 – 10 h | $28 – $50 |
| **TOTAL A** | **56.5 – 79 h** | **$283 – $395** |

*Punto medio: ~68 h → **~$340***

---

# DESARROLLO B — CAPTCHA Anti-bots (Cloudflare Turnstile)

**Estado:** ⏸️ No iniciado — plan aprobado internamente, en espera del cliente
**Documentos de referencia:** `logincaptcha.md` (plan completo), `CONTEXTO-logincaptcha.md` (retomada rápida)

## Motivo

El sitio en producción sufrió un **ataque de registro masivo por bots** en `/admin/register`. Las cuentas creadas **no obtienen acceso** (el sistema exige aprobación humana), pero el daño colateral persiste:

- **Bloat** de la tabla `users` con cuentas basura.
- **Abuso del SMTP corporativo** (`info@camarapetrolera.app`): cada registro dispara un correo de verificación → **riesgo real de blacklist** del correo de la Cámara.
- Consumo innecesario de recursos del servidor.

**Causa raíz:** el formulario de registro (Livewire) **no tiene CAPTCHA ni límite de intentos**.

## Características

### 1. Cloudflare Turnstile en Registro y Login
- CAPTCHA **sin puzzles** (fricción casi nula para el usuario real), **gratuito e ilimitado**, respetuoso de la privacidad.
- **No requiere** que el sitio esté alojado en Cloudflare.
- Verificación **del lado del servidor** (`siteverify`) dentro de los componentes Livewire — un bot no puede saltársela manipulando el navegador.

### 2. Honeypot en el registro
- Campo invisible que sólo los bots rellenan → registro rechazado silenciosamente, sin molestar al usuario real.

### 3. Rate-limiting en el registro
- Máximo 5 intentos por minuto y por IP (el login ya lo tiene; el registro **no tenía ninguno**).

### 4. Correcciones de seguridad adicionales (incluidas)
- 🔴 **Fuga de datos:** la ruta pública `xls/{empresa}` **ignora el parámetro** y exporta **toda** la base de afiliados **sin autenticación**. Cualquiera con el enlace descarga todo. Se corrige exigiendo autenticación y respetando el filtro por empresa.
- ⚠️ `APP_ENV=local` en producción → cambiar a `production`.
- ⚠️ `APP_URL=https://localhost` → dominio real (afecta los enlaces de verificación de correo).
- ⚠️ Política de contraseña débil (sólo 8 caracteres, sin complejidad).

## Estimación de tiempo y costo — Desarrollo B

### B.1 — Desarrollo

| Tarea | Horas |
|---|---|
| Alta del widget Turnstile + claves en `.env`/`config` | 0.5 |
| Helper de verificación server-side (`Turnstile.php`) | 1.0 |
| Componente Blade reutilizable del widget (compatible con Livewire) | 1.5 |
| Registro: CAPTCHA + honeypot + rate-limiting | 2.0 |
| Login: CAPTCHA | 1.5 |
| Correcciones de seguridad adicionales (ruta `xls`, `.env`, contraseñas) | 1.5 |
| Pruebas end-to-end (registro, login, bot simulado) | 2.0 |
| **Subtotal + margen 20 %** | **10 – 12 h** |

**Costo a $5/h: $50 – $60**

### B.2 — Despliegue

| Tarea | Horas |
|---|---|
| Claves de producción + deploy + limpieza de caché | 0.75 |
| Verificación en producción (smoke test registro/login) | 0.75 |
| **Subtotal** | **1.5 h** |

**Costo a $5/h: $7.50**

### B.3 — Mantenimiento (recurrente)

| Concepto | Costo |
|---|---|
| Licencia Cloudflare Turnstile | **$0** (gratis e ilimitado) |
| Infraestructura nueva | **$0** (se usa el hosting actual) |
| Monitoreo mensual (~0.5 h/mes) | **~$2.50 / mes** |
| Compatibilidad en futuras actualizaciones (1–2 h por upgrade) | $5 – $10 por upgrade |

### Total Desarrollo B

| | Horas | Costo ($5/h) |
|---|---|---|
| Desarrollo | 10 – 12 h | $50 – $60 |
| Despliegue | 1.5 h | $7.50 |
| **TOTAL B (puesta en marcha)** | **11.5 – 13.5 h** | **$58 – $68** |
| Mantenimiento | ~0.5 h/mes | ~$2.50/mes |

---

# Resumen Comparativo

| Criterio | **A — Sectores / Wizard / "No Aplica"** | **B — CAPTCHA Anti-bots** |
|---|---|---|
| **Naturaleza** | Mejora funcional del producto | Corrección de seguridad (incidente activo) |
| **Estado** | 90 % implementado | No iniciado |
| **Urgencia** | Media — mejora calidad del dato | 🔴 **Alta** — el ataque está ocurriendo y arriesga el correo corporativo |
| **Horas totales** | 56.5 – 79 h | 11.5 – 13.5 h |
| **Costo a $5/h** | **$283 – $395** | **$58 – $68** |
| **Horas pendientes** | 5.5 – 10 h ($28 – $50) | 11.5 – 13.5 h ($58 – $68) |
| **Riesgo técnico** | Bajo (migraciones reversibles) | Bajo (cambios aislados en auth) |
| **Costo recurrente** | $0 | ~$2.50/mes (opcional) |

## Recomendación de priorización

1. **Desarrollo B (CAPTCHA) primero** — es el más barato ($58 – $68), el más rápido (~2 días de trabajo) y ataca un problema **activo** que amenaza con inhabilitar el correo institucional de la Cámara. Incluye además el cierre de una **fuga de datos crítica** (ruta `xls`) que hoy permite descargar toda la base de afiliados sin autenticación.
2. **Desarrollo A (cierre)** — quedan sólo 5.5 – 10 h ($28 – $50) para cerrar el bug crítico, hacer QA y desplegar el 90 % ya construido. Es la forma más eficiente de capitalizar el trabajo ya invertido.

**Inversión total para dejar ambos desarrollos en producción (sólo lo pendiente): 17 – 23.5 h → $85 – $118.**
**Inversión total incluyendo el trabajo ya ejecutado del Desarrollo A: 68 – 92.5 h → $340 – $463.**

---

*Documento preparado el 13 de Julio de 2026 — Esteban Vásquez*

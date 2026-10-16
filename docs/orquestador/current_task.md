# Tarea actual (checkpoint 2026-10-16 ~13:30Z, rama `fix/verification-completion`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-VERIFICATION-FLOW-AND-COMPLETION — READY_FOR_REVIEW — MANUAL

| Campo | Valor |
|---|---|
| Autorización | `6215451160` (propietario, 2026-10-16 12:25Z): implementación y pruebas aisladas; **no** despliegue; correo/SMTP en otra ronda |
| Rama / BASE | `fix/verification-completion` / `10616afcf0b6f75248bd54a0903d93b3a9b51d85` (candidato `fix/first-save-postgres`, aceptado en `6215360219`) |
| Runtime verificado antes de la rama | remoto `10616af`; despliegue `0f8bdad`; staging `0f8bdad`, 0 modificaciones rastreadas (12:27Z) |
| TESTED_HEAD | `3e684c569c035bc2944319f63996b45cbceab902` (navegador en `3f639b7`; después solo cambian el manual y los docs) |
| A. Verificación | `->emailVerification()` de Filament: sin 500; páginas protegidas → «Verifique su correo electrónico»; enlace firmado propio (403 para otro usuario, firma alterada, hash ajeno o vencido); reenvío limitado a 2 por minuto, que no promete entrega si no hay envío |
| B. Completitud | `setStatus(false)` borra por modelo y se recalcula el cache; `NoAplicaAction` en transacción; datos y `updated_at` intactos |
| Pruebas | antes en rojo (`d0e6947`); enfocadas 139 OK; suite completa 568 OK en `3e684c5`; navegador 30/30 |
| Informe | `audit/verification_completion_2026-10-16.md`; evidencia `audit/runs/verification_completion/` |
| Manual | `#verificar-correo` (nuevo), «No Aplica», errores frecuentes, contactar a la Cámara; `docs/deploy/CORREO_DEL_SISTEMA.md` |
| Siguiente acción | revisión manual. Desplegar y activar el correo requieren autorizaciones aparte del propietario |

## Hallazgos para una ronda aparte (no autorizados aquí)

- **Seguridad:** `pdf/{empresa}` y `xls/{empresa}` no exigen sesión (informe §9). Se recomienda priorizarlo.
- **Correo:** mientras no se active, un afiliado nuevo no puede verificarse solo.
- **Datos históricos de `assets`:** solo propuesta (`audit/first_save_postgres_2026-10-16.md` §8).

## Fuera de alcance hasta una instrucción nueva

- activar el correo;
- reintentar la solicitud #1;
- reactivar el ejecutor;
- hacer merge a `main`;
- mutar la taxonomía;
- el backlog listado en `SESSION_RESUME.md` §5.

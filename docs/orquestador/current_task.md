# Tarea actual (checkpoint 2026-10-16 ~09:45Z, rama `fix/model-compatibility-labels`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-MODEL-COMPATIBILITY-AND-LABELS — READY_FOR_REVIEW — MANUAL

| Campo | Valor |
|---|---|
| Autorización | `6211997650` (propietario, 2026-10-16 09:03Z): implementación y validación aislada; **no** despliegue |
| Referencia directa | `6190871814` (investigación de solo lectura) |
| Rama / BASE | `fix/model-compatibility-labels` / `28014c2cb56ef5f75bb9339545a045635ee81854` (continuidad aceptada, `6211970495`) |
| Runtime verificado antes de la rama | staging `0f8bdad`, 0 modificaciones, 2026-10-16 09:05Z |
| TESTED_HEAD | `2f1e6fdd9a363bfa57c1a8e523375e6e963bcbd1` |
| Resultados | enfocados 61 OK; suite completa 515 OK; navegador 51/51 (aislado) |
| Informe | `audit/model_compat_labels_2026-10-16.md`; evidencia `audit/runs/model_compat_labels/` |
| Siguiente acción | revisión manual. Desplegar requiere una autorización del propietario |
| Arrastre de continuidad | esta rama lleva los archivos de arranque aceptados hacia la próxima entrega de runtime |

## Hallazgo para una ronda aparte (no autorizado aquí)

Los hooks `created` de `Management`, `Asset`, `Experience`, `Presence` y `Sustainability` usan
`set <tabla>.user_id`, que PostgreSQL rechaza. Detalle en el informe §6.

## Fuera de alcance hasta una instrucción nueva

- activar el correo;
- reintentar la solicitud #1;
- reactivar el ejecutor;
- hacer merge a `main`;
- mutar la taxonomía;
- el backlog listado en `SESSION_RESUME.md` §5.

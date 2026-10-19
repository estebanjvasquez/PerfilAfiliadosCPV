# Tarea actual (checkpoint 2026-10-19 ~10:30Z, rama `review/export-access`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-EXPORT-ACCESS-REVIEW — READY_FOR_REVIEW — MANUAL (solo revisión)

| Campo | Valor |
|---|---|
| Instrucción | `6262799754` (propietario, 2026-10-19): revisión, prueba aislada y propuesta; **sin** cambio de runtime ni despliegue |
| Rama / BASE | `review/export-access` / `edf20ed4a1fbd8b2d6b8cee3d62af2a58bfa6d7a` (`fix/verification-completion`, aceptada en `6262657488`) |
| Runtime observado | staging `0f8bdad`: middleware efectivo de las dos rutas y existencia de las vistas, leídos de solo lectura |
| Prueba | `ExportAccessCharacterizationTest` (3 OK): 6 actores × PDF/XLS × empresas A/B/C, más ids inexistentes y manipulados |
| Hallazgos | F1/F2: el PDF y el XLS se sirven a cualquiera, incluso a un invitado (IDOR). F3: el XLS toma el id de `REQUEST_URI`, filtra a una sola empresa y responde 500 con `?x`/`abc`. F4: XLS `Cache-Control: public`. F7: el alias `auth` lleva a una ruta `login` que no existe |
| Propuesta | informe §5 (matriz) y §6 (reparación mínima); decisiones D1, D2 y D3 en §7 |
| Siguiente acción | revisión y autorización de la reparación. El correo y el resto siguen diferidos |

## Fuera de alcance hasta una instrucción nueva

- implementar la reparación de las descargas;
- activar el correo;
- reintentar la solicitud #1;
- reactivar el ejecutor;
- hacer merge a `main`;
- mutar la taxonomía;
- el backlog listado en `SESSION_RESUME.md` §5.

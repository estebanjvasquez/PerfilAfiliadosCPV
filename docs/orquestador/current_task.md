# Tarea actual (checkpoint 2026-10-16 ~11:00Z, rama `fix/first-save-postgres`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY — candidato integrado, READY_FOR_REVIEW — MANUAL

| Campo | Valor |
|---|---|
| Autorización | `6212967641` (propietario, 2026-10-16 09:57Z): implementación y validación aislada; **no** despliegue |
| Prerrequisito incluido | `fix/model-compatibility-labels` @ `0895258` (EmpresaPgsql y etiquetas OVID/ISO), aceptado en `6212784682` |
| Rama / BASE | `fix/first-save-postgres` / `0895258670f0bbf18476a33d20c361b674b471d5` |
| Runtime verificado antes de la rama | staging `0f8bdad`, 0 modificaciones (2026-10-16 10:02Z) |
| TESTED_HEAD | `fea53554f35170470802a8637989190bbe17818e` (runtime igual a `e67c3ca`) |
| Resultados (aislado) | reproducción antes del arreglo; enfocadas 93 OK y 18 OK; suite completa 533 OK; navegador 40/40 |
| Informe | `audit/first_save_postgres_2026-10-16.md` (matriz §3); evidencia `audit/runs/first_save/` |
| Manual | sin cambios: los pasos son los mismos (reparación de confiabilidad) |
| Siguiente acción | revisión manual. Desplegar requiere una autorización explícita del propietario |

## Hallazgos para una ronda aparte (no autorizados aquí)

- **Usuario sin verificar:** al abrir el panel recibe 500 (`verification.notice` no definida). Informe §5.
- **Datos históricos de `assets`** (1 fila con actor NULL; 1 empresa con 2 filas): solo diagnóstico, informe §8.

## Fuera de alcance hasta una instrucción nueva

- activar el correo;
- reintentar la solicitud #1;
- reactivar el ejecutor;
- hacer merge a `main`;
- mutar la taxonomía;
- el backlog listado en `SESSION_RESUME.md` §5.

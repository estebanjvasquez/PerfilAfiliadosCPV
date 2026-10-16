# Tarea actual (checkpoint 2026-10-16 ~12:00Z, rama `fix/first-save-postgres`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY — candidato integrado, READY_FOR_REVIEW — MANUAL (corrección `6214463281`)

| Campo | Valor |
|---|---|
| Autorización | `6212967641` (propietario, 2026-10-16 09:57Z): implementación y validación aislada; **no** despliegue |
| Corrección | `6214463281` (revisión de `6213980289`): atomicidad en el camino de falla |
| Prerrequisito incluido | `fix/model-compatibility-labels` @ `0895258` (EmpresaPgsql y etiquetas OVID/ISO), aceptado en `6212784682` |
| Rama / BASE | `fix/first-save-postgres` / `0895258670f0bbf18476a33d20c361b674b471d5` |
| Runtime verificado antes de la rama | staging `0f8bdad`, 0 modificaciones (2026-10-16 10:02Z) |
| TESTED_HEAD | `b9b41a0ee6dcef0323a52537cfe067dd2a187f8a` |
| Resultados (aislado, en `b9b41a0`) | 10 fallas inyectadas de primer guardado y 4 de edición/borrado: rollback y reintento OK (8 en rojo antes, en `19a67bb`); enfocadas 107 OK; suite completa 547 OK; navegador corto 7/7 |
| Informe | `audit/first_save_postgres_2026-10-16.md` (matriz §3, atomicidad §12); evidencia `audit/runs/first_save/` |
| Manual | sin cambios: los pasos son los mismos (reparación de confiabilidad) |
| Siguiente acción | revisión manual. Desplegar requiere una autorización explícita del propietario |

## Bloqueante de release y hallazgos (no autorizados aquí)

- **Alta de afiliados nuevos, BLOQUEANTE aparte:** un usuario sin verificar recibe 500 al abrir el panel (`verification.notice` no definida, informe §5).
  - El candidato **no** está listo para el alta completa.
  - Próxima tarea: el flujo real registro → verificación → panel, con SMTP pendiente. La decide el propietario.
- **Datos históricos de `assets`** (1 fila con actor NULL; 1 empresa con 2 filas): solo propuesta de revisión, informe §8.
- **`NoAplicaAction`:** al desmarcar no refresca el porcentaje cacheado (informe §12).

## Fuera de alcance hasta una instrucción nueva

- activar el correo;
- reintentar la solicitud #1;
- reactivar el ejecutor;
- hacer merge a `main`;
- mutar la taxonomía;
- el backlog listado en `SESSION_RESUME.md` §5.

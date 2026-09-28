# Canal de comunicación con el agente orquestador

> **Desde el 2026-09-28 rige el Collaboration Protocol: el canal autoritativo es GitHub**
> (commits, ramas, Pull Requests, comentarios de PR, `audit/*.md`, `docs/task.md`). Al terminar una
> fase, el handoff al orquestador son 5 líneas —repo, rama, PR, HEAD, `READY_FOR_REVIEW`— y el
> orquestador inspecciona GitHub por su cuenta. **No hay que pegarle reportes largos.**
>
> Esta carpeta queda como complemento, no como canal principal:
> - `ESTADO.md` — foto del presente, útil para arrancar una sesión sin releer la historia.
> - Archivos numerados — contexto narrativo que no entra naturalmente en un PR.

## Cómo usarlo

Si el orquestador tiene acceso al filesystem del repo, alcanza con:

```
Lee docs/orquestador/ESTADO.md y docs/orquestador/003-para-orquestador-<tema>.md
```

Si no lo tiene, abrís el archivo y pegás su contenido — pero cada archivo está escrito para ser
autocontenido y corto por separado, así no pegás la historia entera.

## Convención de nombres

```
NNN-para-orquestador-<tema>.md    lo que este agente (Claude Code) le manda al orquestador
NNN-del-orquestador-<tema>.md     lo que el orquestador responde o pide
```

`NNN` es un correlativo de 3 dígitos que ordena el ida y vuelta cronológicamente. El tema va en
kebab-case y corto. La fecha va **dentro** del archivo, no en el nombre.

## ESTADO.md

`ESTADO.md` es el único archivo que se **sobrescribe** en cada actualización: es la foto del estado
actual del proyecto. Se lee primero y evita tener que releer toda la historia numerada.

Regla: si algo cambia el estado del proyecto (una entrega, una corrida contra producción, una
decisión), se actualiza `ESTADO.md` en el mismo movimiento en que se escribe el archivo numerado.
Los archivos numerados son el historial inmutable; `ESTADO.md` es el presente.

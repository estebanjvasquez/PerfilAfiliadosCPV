---
description: Cierra la jornada de trabajo en PerfilAfiliadosCPV — actualiza task.md y Notion con lo hecho hoy
---

Cierra el día de trabajo en este proyecto (PerfilAfiliadosCPV). Hacé lo siguiente, en orden:

## 1. Reconstruir qué se hizo hoy

- Corré `git log --oneline` y `git status` para ver los commits del día y si queda algo sin
  commitear/pushear en el working tree.
- Repasá la conversación de esta sesión para identificar: qué pidió el cliente/usuario, qué se
  implementó, qué bugs aparecieron y cómo se corrigieron, y qué quedó pendiente o sin confirmar.

## 2. Actualizar `task.md` (raíz del proyecto, nunca se commitea — está en `.gitignore`)

- Actualizar la fecha en "Última actualización" (arriba del todo).
- Si ya existe una sección para la tarea/fase activa de hoy, **agregar una subsección nueva**
  dentro de ella (no reescribir lo que ya está) resumiendo lo hecho: qué se pidió, qué se
  construyó (con paths de archivos clave), qué bugs se corrigieron y por qué (causa raíz, no solo
  el síntoma), y el estado final.
- Actualizar el bloque de "Commits relevantes" con los commits nuevos (más reciente primero).
- Actualizar la sección "Pendiente" / checklist de QA: marcar qué quedó probado y confirmado por
  el cliente, qué quedó pendiente de probar, y cualquier cosa que haya quedado **sin commitear o
  sin pushear** (avisar explícitamente si el working tree no queda limpio).
- Si aparecieron gotchas técnicos de Filament/Laravel que valga la pena no tener que redescubrir
  después, agregarlos a "Notas técnicas importantes para retomar sin releer todo el historial".
- Si se usó un plan de `EnterPlanMode` guardado en `C:\Users\EstebanVasquez\.claude\plans\`,
  referenciarlo en "Comando para retomar".

## 3. Actualizar Notion

Páginas ya conocidas de este proyecto (evitar `notion-search` si estos IDs siguen resolviendo):
- Página principal **"Perfil de Afiliados CPV"**: `3a76989d-a22e-8157-aa9c-d1c60bc59415`
- **"Registros de actividades"** (contenedor de las entradas cronológicas):
  `3a76989d-a22e-8133-ba45-c92fb6abd4ac`

Si algún ID ya no resuelve, usar `notion-search` con "PerfilAfiliadosCPV" o "Cámara Petrolera"
para volver a encontrarlas.

Pasos:
1. Fetch de "Registros de actividades" para ver si ya existe una entrada
   "Registro de Proyecto — [fecha de hoy]" (por si `/cerrar-proyecto` se corre más de una vez el
   mismo día, como pasó el 2026-07-24). Si existe, **agregarle una sección nueva** al final
   (`insert_content`, position end) en vez de crear una página duplicada. Si no existe, crearla
   con `notion-create-pages` como hija de "Registros de actividades", con el mismo estilo que las
   entradas anteriores (oración en negrita de intro, listas por área, sección "Bugs corregidos
   durante el desarrollo" si aplica, párrafo de cierre "Estado al cierre del día" / "Pendiente").
2. Actualizar la sección "Estado actual" de la página principal ("Perfil de Afiliados CPV"): la
   fecha del callout y la descripción de qué contiene la rama `staging`.

## 4. Confirmar al usuario

Cerrar con un resumen corto: qué se actualizó en `task.md`, el link a la entrada de Notion
actualizada/creada, y si quedó algo sin commitear/pushear que el usuario debería revisar antes de
la próxima sesión.

**No** hagas `git commit`/`git push` como parte de este comando — es solo documentación. Si hay
cambios de código sin commitear, avisar y preguntar si se quiere commitear antes de cerrar.

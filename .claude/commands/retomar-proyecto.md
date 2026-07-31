---
description: Retoma el trabajo en PerfilAfiliadosCPV — lee task.md, sincroniza staging y resume el estado antes de seguir
---

Retomá el contexto de este proyecto (PerfilAfiliadosCPV) después de una pausa. Hacé lo siguiente,
en orden, y no empieces a programar nada todavía — este comando es solo de orientación:

## 1. Sincronizar y verificar estado de git

```bash
git status
git checkout staging
git pull origin staging
git log --oneline -15
```

- Si `git status` (antes del `checkout`/`pull`) muestra cambios sin commitear, **no los
  descartes**: repórtalos tal cual, son casi seguro el "pendiente sin commitear" que haya quedado
  anotado en `task.md` de la última sesión.
- Si `git pull` trae commits nuevos que no están descritos en `task.md`, avisar — puede que otra
  persona (o el cliente directo en el servidor) haya tocado algo.

## 2. Leer `task.md` completo

Es la fuente de verdad de en qué quedó todo (nunca se sube al repo, vive solo local). Prestar
atención especial a:
- La sección marcada `[ACTIVA]` (o las que estén activas) vs. `[PAUSADA]`.
- El bloque "Pendiente — retomar aquí cuando el cliente responda" de la tarea activa.
- Cualquier nota de "sin commitear" o "sin pushear" y contrastarla contra el resultado del paso 1.
- La sección "Comando para retomar" al final de cada tarea — casi siempre referencia uno o más
  archivos de plan en `C:\Users\EstebanVasquez\.claude\plans\`.

## 3. Leer el/los plan(es) referenciados

Si `task.md` apunta a un archivo de plan (ej. `noble-wondering-metcalfe.md`,
`golden-splashing-nova.md`), leerlo también — puede tener contexto de diseño/decisiones que no
está repetido en `task.md`. Si el archivo ya no existe o el contenido no coincide con lo que dice
`task.md` (se reutiliza el mismo archivo entre fases distintas en este proyecto), decirlo
explícitamente en vez de asumir.

## 4. Resumir al usuario y preguntar cómo seguir

Cerrar con un resumen corto (no un volcado de todo `task.md`) que cubra:
- En qué quedó el proyecto la última vez (1-2 frases).
- Qué está pendiente de QA/confirmación del cliente ahora mismo.
- Si quedó algo sin commitear/pushear, y qué es.
- Si hay algo roto o inconsistente entre lo que dice `task.md` y lo que muestra `git log`/`git status` real.

Terminar preguntando explícitamente qué se quiere hacer ahora (seguir con lo pendiente, atender
un pedido nuevo del cliente, revisar el sin-commitear, etc.) — **no** asumas la siguiente acción
ni empieces a editar código todavía.

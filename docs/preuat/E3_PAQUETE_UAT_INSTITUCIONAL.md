# Pruebas de aceptación del Perfil de Afiliados y del buscador CIRA

**Para:** el personal de la Cámara Petrolera de Venezuela que participa en las pruebas y la persona que las organiza.
**Versión:** borrador para revisión del propietario (TASK-0033, 2026-11-17). Índice técnico: [`00_INDICE.md`](00_INDICE.md).

---

## 1. Para qué son estas pruebas

Queremos saber si el sistema sirve para el trabajo diario de la Cámara **antes** de usarlo de verdad. Usted no prueba el
código: dice, con su conocimiento del sector, si lo que ve es correcto.

Al final, la Cámara firma un acta que dice qué funciona, qué hay que corregir y si se puede seguir adelante. **Firmar esta
prueba no pone el sistema en producción**: esa es otra decisión.

## 2. Quién hace qué

| Rol | Quién | Qué hace |
|---|---|---|
| Organizador | El propietario del proyecto o la persona que designe | Prepara las cuentas y las invitaciones, explica las pruebas, recoge los resultados y redacta el acta |
| Evaluador | Personal de la Cámara invitado | Hace las pruebas de la **parte A** y anota los resultados |
| Administrador | El propietario | Hace las pruebas de la **parte B**, que necesitan permisos especiales |
| Responsable de la firma | La persona que la Cámara designe | Lee el resumen y firma el acta (§9) |

## 3. Antes de empezar

**El organizador se asegura de que:**
1. cada evaluador tiene una cuenta propia y verificada, **sin permisos de administración**;
2. cada evaluador está invitado a la campaña de evaluación de CIRA, porque sin invitación «Mis pruebas» muestra «Prohibido»;
3. la campaña está abierta y tiene fechas;
4. cada evaluador recibió este documento y la guía de evaluación de CIRA. La guía está dentro del sistema, en «Evaluación de
   CIRA» > «Guía» (`/admin/evaluacion-cira/guia`), y en `docs/manual_usuario/guia_evaluacion_cira.md` de la versión
   desplegada (`5e6e3fc`).

**El evaluador necesita:**
- una computadora con Chrome, Edge o Firefox actualizados (opcional: un teléfono);
- su usuario y contraseña;
- unos **60 a 90 minutos**, en una o varias sesiones;
- la hoja de resultados ([`formatos/E3_hoja_de_resultados.csv`](formatos/E3_hoja_de_resultados.csv)) o un papel con las
  mismas columnas.

## 4. Dónde se prueba

Todo se hace en el **entorno de pruebas**: `https://pruebas.camarapetrolera.app`.

| Página | Dirección |
|---|---|
| Buscador CIRA | `https://pruebas.camarapetrolera.app/cira-test/` |
| Entrada al sistema | `https://pruebas.camarapetrolera.app/admin/login` |
| Mis pruebas de CIRA | menú «Evaluación de CIRA» dentro del sistema |

**Importante: el entorno de pruebas tiene los datos reales de las empresas afiliadas.** Por eso:
- **no modifique ni borre** datos de ninguna empresa, contacto o usuario, aunque el sistema se lo permita;
- **no cree** sectores, servicios, áreas, países ni categorías;
- **no comparta** fuera de la Cámara capturas con datos de empresas;
- si una prueba le pide guardar algo distinto de su evaluación, **no lo haga** y avise al organizador.

## 5. Qué esperar (para no reportarlo como error)

| Situación | Es lo esperado hoy |
|---|---|
| Una búsqueda en CIRA tarda **entre 8 y 14 segundos** | Sí. Si tarda **más de 20 segundos** o no responde, anótelo con la hora |
| Abrir la ficha de una empresa desde su tarjeta tarda **5 a 7 segundos** | Sí |
| Al abrir una ficha, su mensaje aparece con un texto como `[ID 177]` al final | Sí, es conocido. Puede comentarlo como sugerencia |
| El sistema pide una casilla «No soy un robot» al entrar y, en algunos teléfonos, falla la primera vez | Conocido. Refresque la página. Si se repite, anote el modelo de teléfono y el navegador |
| CIRA separa **«Solo capacidades»** de **«Empresas con experiencias relacionadas»** | Sí: capacidades = lo que la empresa declara que ofrece; experiencias = proyectos parecidos a lo buscado |
| Una empresa aparece en las dos vistas | Puede pasar. Anote si le parece correcto o no: la Cámara lo va a decidir |

## 6. Cómo anotar cada resultado

En cada prueba anote en la hoja:
- **Resultado:** ✅ Correcto · 🟡 Parcial · ❌ Incorrecto · ⛔ No se pudo hacer
- **Tiempo** (solo en CIRA): aproximado, en segundos. No hace falta cronometrar con precisión
- **Evidencia:** una captura de pantalla si el resultado no es ✅. Guárdela con el número de la prueba, por ejemplo `A-07.png`
- **Severidad**, si encontró un problema:

| Severidad | Significa | Ejemplo |
|---|---|---|
| **S1 · Bloqueante** | No se puede usar para el trabajo, o muestra datos a quien no debe | No deja entrar; muestra la ficha de otra empresa a un afiliado; error de página que impide seguir |
| **S2 · Mayor** | Funciona, pero da un resultado equivocado que confundiría a un usuario | Falta la empresa más conocida de una actividad; dice «Empresa no afiliada» de una afiliada desde su tarjeta |
| **S3 · Menor** | Molesta, pero no cambia la decisión del usuario | Orden mejorable; texto confuso; lentitud dentro de lo esperado |
| **S4 · Sugerencia** | Idea de mejora | «Me gustaría filtrar por estado» |

## 7. Parte A — Pruebas del personal de la Cámara

### A.1 Buscador CIRA

**Cómo hacerlo:**
- Abra `https://pruebas.camarapetrolera.app/cira-test/`.
- Escriba la búsqueda tal como se indica y espere la respuesta.
- No hace falta iniciar sesión para buscar. Sí hace falta para **«Evaluar esta búsqueda»**.

| N.º | Qué hacer | Resultado esperado | Qué mirar |
|---|---|---|---|
| A-01 | Buscar `Empresas de perforación` | Una lista de empresas, separada en «Solo capacidades» y «Empresas con experiencias relacionadas» | ¿Son empresas que realmente perforan? ¿Falta alguna que usted conozca? |
| A-02 | Buscar `balancín` | Arriba, en capacidades, la empresa registrada para balancines; las relacionadas, en experiencias | ¿Es correcta la separación? |
| A-03 | Buscar `balacin` (mal escrito) y después `balancines` | Los mismos resultados que A-02 | ¿Entiende el error de escritura? |
| A-04 | Buscar `macolla` | Sin empresas en capacidades, con la opción de consultar experiencias. No cambia de vista sola | ¿El mensaje se entiende? |
| A-05 | Buscar `gandola` | Empresas de transporte en gandolas | ¿Están las que conoce? |
| A-06 | Buscar `camiones de transporte de maquinaria pesada` y pulsar la **primera tarjeta** | Se abre la ficha («Información de …») de esa empresa | Que **no** diga «Empresa no afiliada» |
| A-07 | Buscar por nombre una empresa afiliada que usted conozca (completo o parte, incluso con siglas) | Aparece esa empresa; al pulsarla, se abre su ficha | ¿Aparecen también otras? ¿Le parece correcto? (pregunta 4 de la Cámara) |
| A-08 | Buscar `International Offshore` | Aparecen I.O.E.D. y, por parecido de servicio («costa afuera»), Geohidra | Diga si debe aparecer solo I.O.E.D. o ambas |
| A-09 | Buscar algo fuera del sector, por ejemplo `venta de zapatos` | Pocos resultados o ninguno, sin inventar coincidencias | ¿Muestra empresas sin relación? Eso sería S2 |
| A-10 | Buscar con un sinónimo o una palabra coloquial de su trabajo diario (elija usted) | Resultados equivalentes a los del término formal | Anote el término y si funcionó (sirve para el glosario de la Cámara) |
| A-11 | En una búsqueda que conozca bien, mire **solo los 5 primeros** | Los 5 son pertinentes y en un orden razonable | ¿Cuántos de los 5 son correctos? |
| A-12 | Pulse «¿Por qué aparece?» (o la explicación) en dos o tres resultados | La explicación coincide con lo que la empresa hace | ¿Alguna explicación es falsa? |
| A-13 | Vote 👍 o 👎 en uno o dos resultados | El voto queda marcado | Que no dé error |
| A-14 | Repita una búsqueda en un **teléfono** | Se ve bien, sin tener que desplazarse de lado; las tarjetas se pueden pulsar | ¿Se lee bien? |

### A.2 Evaluación formal de CIRA (pruebas CPV-01 a CPV-08)

Es el registro oficial que usará la Cámara para decidir.

**Pasos** (según la guía de evaluación):
1. Haga la búsqueda en CIRA.
2. Pulse **«Evaluar esta búsqueda»** debajo de los resultados; si no ha entrado, el sistema le pide su usuario.
3. Elija a qué prueba corresponde y marque **Correcta**, **Parcial** o **Incorrecta**.
4. Marque los resultados que sobran y escriba las empresas que faltan.
5. Pulse **«Guardar borrador»** o **«Enviar evaluación»**.

Haga la evaluación **en el mismo navegador** donde buscó.

| N.º | Prueba | Ejemplo orientativo (use búsquedas que usted conozca) | Resultado esperado del sistema |
|---|---|---|---|
| A-15 | CPV-01 · Actividad conocida | la de A-01 o una suya | La evaluación se guarda ligada a la búsqueda |
| A-16 | CPV-02 · Sinónimos y vocabulario | la de A-10 | Idem |
| A-17 | CPV-03 · Empresa por nombre | la de A-07 | Idem |
| A-18 | CPV-04 · Capacidad específica frente a sector general | p. ej. `inspección de tuberías` | Idem |
| A-19 | CPV-05 · Búsqueda negativa | la de A-09 | Idem |
| A-20 | CPV-06 · Relevancia de los 5 primeros | la de A-11 | Idem |
| A-21 | CPV-07 · Actividad transversal | p. ej. `seguridad industrial` o `laboratorio` | Idem |
| A-22 | CPV-08 · Explicación de la coincidencia | la de A-12 | Idem |
| A-23 | Abra «Mis pruebas», reabra una evaluación, corríjala y envíela | Se ve su avance y la evaluación corregida | Que no se dupliquen |

**Mínimo esperado:** las 8 pruebas enviadas por cada evaluador. Las que salgan «Parcial» o «Incorrecta» deben llevar un
comentario.

### A.3 Perfil de empresas y descargas (solo lectura)

| N.º | Qué hacer | Resultado esperado | No hacer |
|---|---|---|---|
| A-24 | Entrar al sistema con su usuario | Entra al panel; ve solo los menús de su rol | — |
| A-25 | Abrir la ficha de una empresa: la suya, si su cuenta es de afiliado, o una que conozca, si su cuenta es de consulta | Se ven sus datos, sectores y servicios; un afiliado ve solo su empresa | **No** pulse «Guardar» ni edite |
| A-26 | Comparar la ficha con lo que sabe de la empresa | Datos correctos | Anote lo incorrecto en la hoja (es información para la Cámara, no un error del sistema) |
| A-27 | Descargar el **perfil en PDF** de esa empresa | Se descarga un PDF con su perfil | — |
| A-28 | Elegir **secciones** del PDF, si su rol lo permite, y descargar | El PDF trae solo las secciones elegidas | — |
| A-29 | Descargar la **hoja de cálculo** de la empresa, si su rol lo permite | Se descarga el archivo | — |
| A-30 | Intentar abrir la descarga de una empresa a la que **no** debería tener acceso (si es afiliado: otra empresa) | El sistema lo impide | Si la ve, es **S1**: avise de inmediato al organizador |
| A-31 | Salir del sistema y abrir otra vez la dirección de la descarga | Pide entrar de nuevo | Si descarga sin entrar, es **S1** |

### A.4 Entrada al sistema

| N.º | Qué hacer | Resultado esperado |
|---|---|---|
| A-32 | Entrar con su usuario en la computadora y en el teléfono | Entra; la casilla «No soy un robot» funciona, quizá tras refrescar |
| A-33 | Escribir mal la contraseña una vez | Mensaje de error claro, sin bloquear a otros |
| A-34 | Salir del sistema | Vuelve a la pantalla de entrada |

La recuperación de contraseña y la recuperación por RIF las prueba el administrador con cuentas de prueba (B-05 y B-06). El
personal **no** las usa, para no generar correos ni cambios en cuentas reales.

## 8. Parte B — Pruebas del propietario o administrador

| N.º | Área | Qué hacer | Resultado esperado | Condición |
|---|---|---|---|---|
| B-01 | Campaña | Crear o confirmar la campaña, sus fechas y las invitaciones; comprobar que cada invitado ve «Mis pruebas» | Cada invitado ve sus pruebas; un no invitado ve «Prohibido» | Hoy hay 1 campaña y 2 invitaciones |
| B-02 | Panel de evaluaciones | Revisar las evaluaciones enviadas: seudónimo, búsqueda, votos, instantánea «verificada» | Se ven sin correos ni datos personales | Solo `super_admin` |
| B-03 | Informe | Generar el informe de la campaña | No declara «aceptado» por sí solo; la decisión se registra prueba por prueba | Solo `super_admin` |
| B-04 | Decisión de la Cámara | Registrar, para cada CPV-01..08, la decisión que firme la Cámara | Queda con autor y fecha | Después del acta |
| B-05 | Recuperar contraseña | Con una **cuenta y un buzón de prueba**: pedir el correo, abrir el enlace y cambiar la contraseña | Llega el correo; el enlace usa el dominio de pruebas; el cambio funciona | Pruebas controladas autorizadas en alcance por `6802438608`; requieren su tarea |
| B-06 | Recuperar acceso por RIF | Con una **empresa y una cuenta de prueba** | El flujo se completa sin revelar datos | Idem |
| B-07 | Taxonomía | En una categoría, abrir «Términos del diccionario» y buscar categorías desde la ficha de empresa (sin guardar) | Los términos y la búsqueda son coherentes | UAT parcial pendiente de TASK-0014 |
| B-08 | Análisis con IA de solicitudes | Ver el análisis guardado de una solicitud | Se muestra sin error. Un análisis nuevo es una **llamada de pago** | Solo si se decide |
| B-09 | Códigos de categoría | Ver la jerarquía y el historial de cambios de código (sin cambiar nada) | Visible y coherente | — |
| B-10 | Lectura de tiempos | Al cerrar la campaña, pedir al equipo técnico los tiempos de servidor de las búsquedas registradas (sin cronómetro) | p50 y p95 por tipo de consulta | Solo lectura (E4, opción L8) |
| B-11 | Volumen | Al cerrar la campaña, revisar cuántas ejecuciones de CIRA hubo | Coherente con el número de evaluadores | E2, condición C5 |

**Fuera de esta UAT, por decisión o por gate:**
- editar roles;
- crear o retirar sectores, servicios o la «X»;
- cambiar datos de empresas;
- la búsqueda semántica de categorías (sin configurar en pruebas);
- la página «Vistas de la Cámara» (`JoinViews`, con error conocido);
- producción.

## 9. Criterios de aceptación y acta

**La UAT institucional se da por aceptada si:**
1. no queda ninguna incidencia **S1** abierta;
2. cada incidencia **S2** tiene responsable y decisión: corregir antes de producción o aceptar el riesgo;
3. cada prueba CPV-01..08 tiene **al menos 2 evaluaciones enviadas** por personas distintas, y la Cámara registró su
   decisión para cada una;
4. en **A-06 y A-07** ninguna afiliada aparece como «Empresa no afiliada» al abrir su ficha desde la tarjeta;
5. en **A-30 y A-31** no hay ninguna descarga indebida;
6. la Cámara respondió, o fijó una fecha para responder, las preguntas de [E5](E5_DECISIONES_DE_LA_CAMARA.md).

**Se suspende la UAT de inmediato si:**
- aparece una S1 de acceso a datos (A-30, A-31);
- el sistema no está disponible durante más de 30 minutos;
- o se modifican por error datos reales.

En ese caso, el organizador avisa al propietario y no se sigue hasta su decisión.

**Acta de aceptación** (una por campaña):

| Campo | Valor |
|---|---|
| Campaña | |
| Fechas | |
| Evaluadores (cantidad y seudónimos) | |
| Pruebas ejecutadas / con ✅ / 🟡 / ❌ / ⛔ | |
| Incidencias S1 / S2 / S3 / S4 | |
| Decisión por prueba CPV-01..08 | |
| Decisión global | ☐ Aceptada · ☐ Aceptada con reservas (listar) · ☐ No aceptada |
| Reservas y condiciones | |
| Firma por la Cámara (nombre, cargo, fecha) | |
| Firma del propietario del proyecto (fecha) | |

Esta acta registra la aceptación de la Cámara en el **entorno de pruebas**. **No autoriza producción.**

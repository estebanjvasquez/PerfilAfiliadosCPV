Esta ayuda es para quienes administran el sistema en la Cámara. Explica dónde ver las solicitudes de revisión de categoría que envían las empresas, cómo configurar quién recibe el aviso por correo y qué hacer cuando un aviso no salió.

## Dónde están las solicitudes {#donde-estan}

En el menú lateral, abra **Taxonomía CPV → Solicitudes de categoría**.

- La lista muestra primero las más recientes: número, fecha, empresa, necesidad, motivo, estado de revisión y estado del aviso por correo.
- Puede buscar por número, empresa o necesidad, y filtrar por estado del aviso, estado de revisión o motivo.
- El número junto a **Solicitudes de categoría** en el menú indica cuántas tienen el aviso **Falló** o **Pendiente**.
- Abra una solicitud para ver todo lo que envió la empresa: la necesidad, el motivo, el detalle, las palabras que probó, los datos de la empresa y del solicitante tal como estaban al enviar, las categorías que la empresa ya tenía y el historial de avisos.

> **IMPORTANTE:** Una solicitud **no crea ni publica ninguna categoría**. Revisarla y decidir qué hacer sigue siendo un trabajo de la Cámara. Esta pantalla solo la muestra.

## Configurar quién recibe el aviso {#configurar-destinatario}

En el menú lateral, abra **Taxonomía CPV → Configuración de solicitudes**. También se llega desde el botón **Configuración de solicitudes** de la lista.

1. Escriba el correo en **Correo responsable de solicitudes de categorías**.
2. Pulse **Guardar**.

En la misma página, **Modo de envío del servidor** indica si el servidor envía correos de verdad o si está en modo simulado.

> **NOTA:** Guardar el correo **no reenvía** las solicitudes que ya estaban registradas. Para esas, use **Reintentar aviso** en cada una.

Los límites de categorías principales y secundarias siguen en **Taxonomía CPV → Límites de selección**. Esa página ya no tiene el campo del correo: solo enlaza aquí.

## Qué significa cada estado del aviso {#estados}

| Estado | Qué significa |
|---|---|
| **Pendiente** | El aviso todavía no se intentó, o el intento no llegó a terminar. |
| **Aceptado por el servidor de correo** | El servidor de correo recibió el mensaje para enviarlo. **No confirma** que haya llegado al buzón del destinatario. |
| **Simulado: registrado en el servidor, no se envió** | El servidor está en modo simulado. El aviso quedó registrado, pero **no se envió ningún correo**. |
| **Falló** | El aviso no salió. El motivo aparece en la solicitud. |

Motivos de fallo:

- **No hay correo responsable configurado.** Configure el correo y luego reintente.
- **El servidor de correo rechazó el envío o no respondió.** Vuelva a intentar más tarde. Si se repite, avise a quien administra el servidor.

En cualquiera de estos estados la solicitud **queda registrada** y se puede revisar desde esta pantalla.

## Reintentar un aviso {#reintentar}

**Reintentar aviso** aparece en las solicitudes con el aviso **Falló** o **Pendiente**.

1. Abra la solicitud o use el botón de su fila en la lista.
2. Pulse **Reintentar aviso**. La ventana de confirmación muestra a qué correo se enviará y en qué modo está el servidor.
3. Pulse **Reintentar ahora**.

Qué ocurre:

- Se envía el mismo aviso con el contenido original de la solicitud. **No se crea otra solicitud** y no se cambia lo que la empresa envió.
- El intento queda en el **Historial de avisos** de la solicitud, con la fecha, quién lo pidió, el destinatario, el modo de envío y el resultado.
- Si alguien ya está reintentando la misma solicitud, el segundo intento se rechaza para no enviar dos avisos.
- Si no hay correo responsable configurado, el botón aparece desactivado. Configure primero el correo.

> **NOTA:** El sistema nunca reintenta solo. Cada reintento lo pide una persona.

## Qué ve la empresa {#que-ve-la-empresa}

Al enviar la solicitud, la empresa recibe su **número de solicitud** como referencia. Si el aviso no se pudo enviar, ve que la solicitud quedó registrada y que el aviso por correo está pendiente. No ve el modo del servidor ni ningún detalle técnico.

## El correo del sistema {#correo-del-sistema}

El sistema tiene **una sola configuración de correo**, común a todo lo que envía: los avisos de solicitudes y el correo de verificación que recibe una persona al crear su cuenta. Esa configuración no se cambia desde el panel: la aplica quien administra el servidor, y nunca se escriben claves en el panel, en el chat ni en los informes.

- **Configuración de solicitudes** solo define **a quién** avisar. No decide si el correo sale.
- **Modo de envío del servidor** (en la misma página) muestra el modo actual. Si dice **Simulado**, no sale ningún correo: los avisos quedan registrados en el historial como simulados.
- Mientras el modo sea simulado, una persona que crea su cuenta **no recibe** el correo de verificación, y sin él no puede entrar al panel. Tome nota de quién se registra en ese periodo para resolverlo cuando se active el envío real.

> **NOTA:** Activar el envío real es una decisión aparte de la Cámara: elegir el proveedor de correo y el remitente, configurar el dominio y hacer una prueba controlada. Hasta entonces, el sistema funciona y registra todo, pero no envía correos.

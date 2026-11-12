# Puente de atención automática en Codex

Base: `/api/claude/support-codex`. Todas las rutas exigen `X-Claude-Task-Key` y `Accept: application/json`. No usan una sesión de administrador ni devuelven claves de los clientes. El contrato es aditivo y permanece apagado hasta activar settings.

## Configuración y pendientes

- `GET settings`: `{enabled:boolean,activated_at:string|null}`.
- `PUT settings` con `{enabled:boolean}`: devuelve la misma forma. El primer encendido fija `activated_at` al reloj del servidor; no procesa automáticamente mensajes anteriores. El corte no se resetea al reiniciar el worker ni al apagar/encender. Un ticket viejo vuelve a ser elegible si el cliente escribe después del corte.
- `GET pending`: `{enabled,activated_at,server_time,tickets:[{id,client_id,client_name,contact_name,expected_message_id,thread_id,reason,state}]}`. Máximo 50 candidatos. `reason` es `inbound`, `follow_up` o `mission_completed` (tras retomar explícitamente una corrección verificada). No lista reservas vigentes ni casos en revisión, cerrados o con un envío ambiguo.

Cuando está encendido, el número principal recibe soporte aunque antes estuviera desconectado. Los clientes con asistente personal dejan de ser derivados a su otro número cuando escriben al principal. Se preserva el webhook del número del asistente. El prefijo `sistema:` va a soporte sin llamar a la IA de API; el worker puede pedir sus datos con diagnostic.

### Clientes en implementación (desde el 26/10/2026)

Con el circuito encendido, un cliente con una implementación en curso también llega a soporte, salvo en los dos flujos guionados que esperan respuestas concretas:

| Implementación en curso (`in_progress`) | Circuito encendido | Circuito apagado |
|---|---|---|
| Sistema en modo `manual` | soporte (ticket, Martín); log `route: cliente-en-implementacion` | hilo de la implementación |
| Sistema en modo `auto` | hilo de la implementación | hilo de la implementación |
| Tienda en etapa ≥ 2 | soporte (ticket, Martín); log `route: cliente-en-implementacion` | hilo de la tienda |
| Tienda en etapa 1 | hilo de la tienda | hilo de la tienda |

Si las dos están en curso decide la del sistema y la de la tienda no se mira (la prioridad de siempre). Con el circuito apagado no cambia ningún camino. Un mensaje que va a soporte queda en `support_messages`, no en el hilo de la implementación: quien lleve la implementación lo lee en el ticket.

## Reserva y conversación

`POST tickets/{id}/claim`:

```json
{"worker_id":"pc-lucas","expected_message_id":123,"thread_id":"opcional","lease_token":"solo para renovar"}
```

Respuesta: `{lease_token,lease_expires_at,ticket:<contexto>}`. La reserva dura 15 minutos. Para renovarla se envían mismo worker, token y expected_message_id; otro proceso recibe 423. Un thread_id ya vinculado no se puede cambiar por accidente.

`GET tickets/{id}` devuelve `{ticket:<contexto>}`. Contexto:

```json
{
  "id":1,"status":"open","name":null,"source":"whatsapp",
  "client":{"id":2,"name":"Comercio","api_url":"https://api-cliente.example","slug":"comercio"},
  "contact_name":"Ana","client_employee_id":null,
  "state":"ready","thread_id":null,"mission":null,"review_reason":null,
  "reason":"inbound","expected_message_id":123,
  "window":{"open":true,"last_inbound_at":"...","expires_at":"...","origin":"support"},
  "client_context":"Ficha operativa y datos actuales del admin, sin notas internas",
  "implementation":null,
  "previous_tickets":[{"id":98,"status":"closed","opened_at":"...","closed_at":"...","mensajes":12}],
  "messages":[{"id":123,"sender_type":"user","body":"No puedo vender","kind":"text","created_at":"...","attachments":[]}]
}
```

`client.slug` (string o null) es el slug del cliente en el admin: el programa local lo usa para ubicar la bitácora de la implementación cuando no tiene su `estado.json`.

`capabilities` declara modos opcionales admitidos por este servidor. Actualmente incluye `{continue_pending:true,mission_started:true}`. Si la clave o la capacidad no existe, el consumidor NO debe enviar `continue_pending` a `resume`: un admin anterior ignoraría el campo y ejecutaría el modo normal que consume los mensajes anteriores y limpia la misión. Un consumidor anterior puede ignorar `capabilities`.

### Excel de catálogo en soporte

`capabilities.soporte_import` y `capabilities.soporte_import_resolver` habilitan el circuito de un cliente activo en el ticket abierto de WhatsApp del dueño.

- `POST tickets/{id}/import-excel` recibe `attachment_id`, `automatizar_soporte: true`, `lease_token` y `expected_message_id`. El archivo y el mensaje del turno deben provenir del número registrado del dueño. Si falta esa identidad en un adjunto anterior, el resultado es HTTP 422 con `estado: archivo_sin_remitente_verificado`. Si la instancia no declara compatibilidad, devuelve `estado: version_vieja` antes de reenviar el archivo. Una respuesta `estado: aceptado` con `automatizar_soporte: true` identifica la corrida y su estado en Empresa.
- El contexto del ticket y la respuesta de `claim` incluyen `messages[].remitente_dueno_verificado`. Es `true` para un entrante de WhatsApp de un negocio activo cuando el ID del mensaje y el remitente original permiten acreditar al dueño con las mismas comprobaciones de Excel. No expone el teléfono. Si el campo falta o es `false`, el monitor no puede usar ese mensaje para autorizar correcciones.
- `POST tickets/{id}/import-excel-stock-existente` recibe los mismos datos del modo soporte más `alcance: stock_existente`. Martín lo usa con `capabilities.soporte_import_stock_existente: true`. Admin comprueba que Empresa anuncia el alcance y reenvía únicamente a su ruta de solo stock. La aceptación devuelve `estado: aceptado` y `alcance: stock_existente`. Frente a un Admin anterior (HTTP 404) o una Empresa incompatible (`estado: version_vieja`), no se reintenta por la ruta general.
- `importaciones_soporte_pendientes` lista las preguntas por corrida y revisión. `importaciones_soporte_en_proceso` puede ser `true`, `false` o `null` si falta confirmación de estado de Empresa. El ticket sigue abierto mientras se procesa o espera decisiones.
- `POST tickets/{id}/import-prueba-resolver` conserva `analysis_uuid`, `revision`, `respuestas`, `lease_token` y `expected_message_id`. Para confirmar o cancelar una carga activa, el último mensaje del dueño tiene que decir `CONFIRMAR <código>` o `CANCELAR <código>` según el aviso enviado para esa revisión.
- Cuando una corrida de `stock_existente` incluye `acepta_codigo_articulo: true` en una pregunta, el mismo resolver transmite `codigo:<código literal>` a Empresa. Empresa valida que la pregunta vigente admita esa respuesta y que el código identifique un artículo del negocio.
- Los avisos se envían por el ticket de soporte dentro de la ventana de WhatsApp. El barrido `asistente:enviar-avisos` incluye tickets con una importación activa iniciada aunque el negocio carezca del canal del asistente. Empresa recibe el filtro `solo_importaciones_de_soporte=1` al consultar y al marcar esos avisos. En la consulta también recibe `support_ticket_ids` con los IDs de los tickets abiertos y con actividad reciente.

Los tickets de prueba conservan `prueba_import`, `prueba_import_resolver` y `importaciones_prueba_*`.

`mission_lifecycle` es `null` si no hay una misión guardada; si existe es `{status:"pending"|"started",thread_id:string|null,started_at:ISO8601|null}`. El inicio solo se informa tras registrar la tarea creada. Una misión iniciada puede seguir pendiente de implementación o publicación cuando se cierra la conversación de soporte. El cierre conserva su diagnóstico y vínculo.

La bandeja y el detalle autenticados del admin agregan `support_attention:{needs_human:boolean,reason:string|null,mission:<mission_lifecycle>|null}`. Los tickets abiertos con una misión sola no requieren atención humana. Un estado `manual_review` por un bloqueo real conserva su motivo y puede convivir con una misión pendiente o iniciada; los escalados legacy sin misión conservan la intervención humana. La pausa específica «Lucas retomó la atención desde el admin.» corresponde a un turno ya contestado y no muestra una intervención pendiente, incluso si persiste una marca de escalación anterior; una misión sigue mostrándose por su cuenta. Los tickets cerrados no muestran ninguna alerta. La relación completa `support_codex_case` no se serializa; los serializadores genéricos que no precargaron esa relación devuelven `support_attention:null`, sin consultas extra por fila ni motivos internos en otros canales.

### Registro supervisado de misión iniciada

`POST tickets/{id}/mission-started` recibe `{mission_thread_id,mission_hash}` y es una acción expresa de Lucas, posterior a crear la tarea nativa. `mission_thread_id` es el identificador retornado por la creación (hasta 150 caracteres alfanuméricos, guiones o guiones bajos); `mission_hash` es SHA256 hexadecimal del texto exacto de `ticket.mission` leído en ese momento. El helper verifica primero `capabilities.mission_started=true`; un servidor anterior carece del endpoint.

La ruta vincula la tarea al ticket y conserva UUID del hilo de soporte, reserva y mensajes atendidos. Es idempotente para la misma tarea y conserva la fecha original. Devuelve 409 `mission_missing` sin misión, `mission_changed` si cambió el diagnóstico o `mission_thread_conflict` si se intenta reemplazar otra tarea vinculada o se confunde con el hilo de soporte. Registrar el hecho de creación no requiere que el agente del ticket o el proveedor global estén encendidos. Puede convivir con `manual_review`: registrar el trabajo conserva el bloqueo humano. Al éxito devuelve el contexto actualizado y emite `SupportTicketUpdated` (solo id). Al escalar otro diagnóstico se limpia el inicio anterior; reescalar el mismo texto lo conserva. Un `resume` normal limpia también su tarea y fecha; `continue_pending` los conserva.

Con `capabilities.feature_handoff_closure=true`, `reply` admite `close_on_mission_start:true` en `escalar` si la única atención pendiente es registrar un pedido de funcionalidad. El mensaje de escalación le informa ese registro al cliente. Cuando se vincula la tarea por primera vez, se cierra la conversación si ese mensaje fue enviado y sigue siendo el último, y si no hay reserva activa, revisión, entrega incierta, importación o actualización en curso. El cierre conserva el vínculo y usa el mensaje ya enviado. Un nuevo mensaje impide ese cierre al registrar. En un mismo diagnóstico, omitir el campo conserva su valor y `false` desarma el cierre. Editar el acuse al aprobarlo también lo desarma. La repetición del mismo inicio en un ticket cerrado por este camino conserva su resultado. Los demás tickets cerrados rechazan el registro.

`implementation` (objeto o null, desde el 26/10/2026) es la implementación del cliente, la del sistema y la de la tienda. Es `null` si no tiene ninguna abierta ni cerrada hace 30 días o menos. **Abierta es solo `status: in_progress`**; una `paused` o `pending` no viaja (ni como abierta ni como cerrada). Cerrada reciente es `completed` con `completed_at` en los últimos 30 días; una `completed` sin `completed_at` no viaja. Un consumidor que no la conozca la puede ignorar, y la clave ausente equivale a `null`:

```json
{
  "sistema": {
    "id":10,"status":"in_progress","abierta":true,"automation_mode":"manual",
    "started_at":"...","completed_at":null,
    "etapa_actual":{"numero":3,"nombre":"Recolección de archivos"},
    "etapas":[{"numero":1,"nombre":"Información de la empresa","estado":"completed","started_at":"...","completed_at":"...",
               "acciones":[{"accion":"form_link","at":"...","canal":"whatsapp_web"}]}],
    "form_submitted_at":"...","user_setup_executed_at":null,
    "form_link":"https://.../configuracion/<token>",
    "tienda_contratada":true,"entrega_hecha":true,"sistema_url":"https://cliente.comerciocity.com",
    "instalacion":{"estado":"completada","at":"..."},
    "mails":[{"hito":"bienvenida","estado":"enviado","enviado_at":"..."}],
    "conversacion":[{"direction":"outbound","etapa":1,"canal":"whatsapp_web","body":"...","at":"..."}]
  },
  "tienda": {
    "id":3,"status":"in_progress","abierta":true,"started_at":"...","completed_at":null,
    "etapa_actual":{"numero":3,"nombre":"Instalación del API"},
    "etapas":[{"numero":1,"nombre":"Configuración de la tienda","estado":"completed","started_at":"...","completed_at":"..."}],
    "dominio":"comercio.com.ar","activa":false,
    "conversacion":[{"direction":"inbound","etapa":2,"canal":"whatsapp","body":"...","at":"..."}]
  }
}
```

- `sistema` o `tienda` es `null` cuando esa implementación no viaja. Las etapas del sistema son las siete del flujo (más la 8 heredada solo si llegó a arrancar); las de la tienda, cinco. Los nombres salen del catálogo de la base con respaldo en el código.
- `acciones`: lo que registraron el panel y `/implementar` en la etapa (`canal` null para las del panel). Solo qué, cuándo y por dónde: el texto y el teléfono de la acción no viajan.
- `conversacion`: los últimos 40 mensajes del sistema o 20 de la tienda, en orden cronológico, cada `body` recortado a 2000 caracteres (con «…» al final si se cortó). `canal` es `whatsapp_web` cuando el mensaje lo registró `/implementar` desde WhatsApp Web (`whatsapp_message_id` con prefijo `waweb-`) y `whatsapp` en otro caso.
- Una cerrada hace 30 días o menos viaja resumida: `abierta:false`, con `conversacion` y `form_link` en `null`.
- `form_link` y `conversacion` (de las dos implementaciones) solo llegan cuando el contacto del ticket es el dueño (`contact_role: owner`) y la implementación está abierta; a un empleado le llegan `null`.
- `tienda_contratada`: `true`/`false` según el contrato, `null` si el contrato no lo dice.
- `entrega_hecha` (bool): `true` si la etapa 5 o una posterior está `completed`, o si hay una acción `acceso` o `entrega` registrada en cualquier etapa (la entrega se registra mientras la 5 sigue `in_progress`). `sistema_url` viaja solo con `entrega_hecha: true`; si no, `null`.
- `instalacion`: `{estado, at}` de la última instalación `kind: completa` del cliente (de su API activa, si tiene), o `null` si no hay. `estado` es el valor real de `client_installations.status`: `pendiente`, `instalando`, `completada` o `fallida`. `at` es `finished_at`, o si no terminó `started_at` (o la creación de la fila).
- `tienda.activa` (bool): `true` si la tienda online está `status: active` o si la etapa 5 de la tienda está `completed`.
- **Datos personales.** No viajan los teléfonos de las fichas (ni el del dueño ni el del responsable de la migración), las casillas de mail ni las respuestas del formulario. En cada `body` de la conversación del dueño se enmascaran, en este orden: cualquier URL que contenga el token del formulario o empiece con la URL base del formulario (`implementation_form_url`) → `[link del formulario]`; lo que sigue a «contraseña» o «clave» (saltando `:`/`=` y un conector como «es», «era», «nueva») hasta el fin de esa palabra → `[oculta]`; y toda secuencia de 8 o más dígitos, aun con un espacio, guión o punto entre dígitos (teléfonos, CUIT, DNI, con su `+`) → `[número]`. Una fecha escrita con guiones (`2026-10-26`) también cae en la última regla.
- Si no se pudo leer, el bloque llega como `{"sistema":null,"tienda":null,"error":"..."}` en vez de `null`, para que no se lea como «no tiene implementación».

`previous_tickets` (lista, desde el 26/10/2026) resume los tickets ANTERIORES del mismo cliente para que el monitor sepa que existen sin pedirlos: los de id MENOR que el actual (uno más nuevo no es anterior), hasta 5, el más nuevo primero (por id), en cualquier estado y de cualquier canal. `mensajes` es la cantidad de mensajes reales (sin borradores). Si el contacto del ticket es un empleado, solo viajan los tickets de ESE empleado; si es el dueño, todos los del cliente (los de sus empleados incluidos). Un ticket sin cliente trae `[]`. Un consumidor que no la conozca la puede ignorar; la clave ausente (admin anterior) equivale a `[]`.

### Tickets anteriores: `GET tickets/{id}/history`

Solo lectura, sin reserva (no cambia nada del caso). El cliente sale del ticket, nunca de un parámetro. Mismos tickets y misma regla de empleados que `previous_tickets`, con la conversación:

```json
{
  "ticket_id":123,"client_id":2,"contact_role":"owner",
  "tickets":[{
    "id":98,"status":"closed","opened_at":"...","closed_at":"...",
    "contact_name":"Ana","contact_role":"owner",
    "messages":[{"id":555,"sender_type":"user","body":"...","kind":"text","created_at":"...",
                 "attachments":[{"id":7,"mime":"application/pdf","size":1234,
                                 "download_path":"/api/claude/support-codex/tickets/98/attachments/7"}]}]
  }]
}
```

- `messages`: los últimos 30 mensajes reales de cada ticket, en orden cronológico, sin borradores.
- **Contraseñas.** En `messages` del contexto y de `history`, el `body` de los mensajes del equipo (`sender_type: admin`) llega con lo que sigue a «contraseña» o «clave» reemplazado por `[oculta]` (la misma regla que la conversación de `implementation`; la de los números NO se aplica acá). Los del cliente llegan tal cual.
- `download_path` apunta a la ruta de adjuntos del ticket ANTERIOR (`tickets/{anterior}/attachments/{aid}`), que valida la pertenencia del adjunto a ese ticket; pedido con el id del ticket actual es 404.
- 404 si el ticket no existe. Contra un admin anterior al 26/10/2026 la ruta no existe (404): el monitor lo informa como «historial no disponible», sin romper.

Los últimos 100 mensajes reales se devuelven en orden cronológico; no incluye borradores. Los adjuntos traen `{id,mime,size,download_path}`; `GET tickets/{id}/attachments/{attachment_id}` sirve el binario con la misma autenticación y valida su pertenencia al ticket.

### Primera ventana de cierre para proponer una actualización

Con `ticket.capabilities.upgrade_window=true`, `GET tickets/{id}/upgrade-window` recibe `expected_message_id`, `minimum_minutes` (entero de 1 a 1440) y `not_before` opcional (ISO 8601 con offset y segundos, por ejemplo `2026-11-03T13:00:00-03:00`). El header `X-Support-Lease-Token` lleva la reserva activa de ese ticket. La clave de ingesta, el ticket abierto en soporte automático y el permiso del contacto para consultar el sistema siguen siendo obligatorios. Un mensaje posterior invalida la lectura con 409 `stale_message`.

Devuelve `window: {starts_at, ends_at, timezone}` y `reason: null`, o `window: null` y `reason: sin_configurar|sin_ventana_suficiente`. Busca desde el mayor instante entre el reloj del admin y `not_before`, redondeado hacia arriba al minuto, durante un máximo de siete días calendario. Reutiliza la precedencia de la fila del día sobre `todos` e incluye recesos y días explícitamente cerrados. El fin excluye la próxima apertura y se limita al primer día sin horario comprobable o al horizonte de búsqueda.

La ventana sirve para conversar con el negocio. La aceptación, el preflight, la actividad actual y la programación se verifican por separado. La consulta solo lee y el cliente se toma del ticket.

### Evidencia de una corrección integrada

Con `ticket.capabilities.mission_fix_evidence=true`, el monitor puede registrar `POST tickets/{id}/mission-fix-evidence` con la clave de ingesta y sin reserva de atención. El ticket debe estar abierto y tener una misión iniciada. El `mission_thread_id` debe coincidir con su hilo vinculado, distinto del chat de soporte, y `mission_hash` con el SHA-256 exacto del diagnóstico vigente. El ticket se toma de la ruta.

El cuerpo agrega `estado: verificada`, `informe` relativo con forma `informes/AAAAMMDD-slug.md`, `pruebas` (lista de 1 a 10 descripciones de 5 a 500 caracteres) y mapas `commits` y `bases_verificadas` con las mismas claves de repositorio y SHAs completos de 40 caracteres hexadecimales en minúscula. Se admiten `empresa-api`, `empresa-spa`, `admin-api`, `admin-spa`, `tienda-api` y `tienda-spa`. El monitor verifica la evidencia y la integración remota antes de enviarla. El admin valida forma y vínculo y conserva la evidencia registrada.

Repetir la misma evidencia es idempotente. Si la evidencia cambia para esa misma misión, el admin devuelve 409. La respuesta y `ticket.mission_fix_evidence` en el contexto permiten conciliar el registro. El contexto solo devuelve la evidencia que todavía corresponde a la misión vigente. Este registro no cambia el caso, la reserva ni el ticket y no envía mensajes, propone horarios, publica releases ni programa actualizaciones.

### Propuesta y acuerdo de actualización

Con `ticket.capabilities.upgrade_agreement=true`, el monitor puede pedir `GET pending?supports_upgrade_agreement=1` y pasar `supports_upgrade_agreement: true` al tomar o renovar una reserva. Si hay evidencia vigente, el dueño está en el ticket y WhatsApp admite el envío, el admin puede devolver `reason: fix_ready_for_upgrade` cuando falta proponer una ventana o la última propuesta venció sin aceptación y con resultado terminal. Un entrante nuevo conserva prioridad y un envío inconcluso impide volver a proponer. Los monitores que omiten el opt-in solo ven los motivos anteriores.

`POST tickets/{id}/upgrade-proposal` recibe `lease_token`, `expected_message_id`, `operation_id`, `thread_id` y `supports_human_approval` opcionales como en reply, más `mission_thread_id`, `mission_hash`, `starts_at`, `ends_at` y `minimum_minutes`. El servidor vuelve a comprobar la evidencia y la ventana, genera el texto con fecha y hora de Argentina y usa la operación de reply. El intento durable y la propuesta se guardan juntos. Si corresponde aprobar, se conserva el texto exacto en el borrador. La respuesta tiene la misma forma de reply.

`POST tickets/{id}/upgrade-agreement` recibe `lease_token`, `expected_message_id` (el entrante con la aceptación) y `proposal_operation_id`. Revalida que sea la última propuesta enviada para el arreglo vigente, el último mensaje del dueño, la ventana futura y el texto efectivamente enviado. WhatsApp conserva la cita como `reply_to_message_id` dentro del ticket. La aceptación usa esa cita a la propuesta más una afirmación inequívoca, o la frase con fecha y hora exactas si no hay cita. Un sí a otro mensaje se rechaza.

La respuesta y `ticket.upgrade_agreement` muestran `proposal_operation_id`, `proposal_message_id`, `status`, `starts_at`, `ends_at`, `timezone`, `confirmation_message_id` y `accepted_at`. La aceptación repetida del mismo entrante conserva el acuerdo existente. La misión sigue vinculada y el ticket abierto. Esta etapa no programa ni arranca una actualización.

### Release de empresa por acuerdo

`GET release-work` lista acuerdos confirmados vigentes que todavía necesitan un release o una programación. Cada entrada contiene el ticket, el cliente, el acuerdo, el entrante de aceptación, la ventana UTC y la evidencia de la misión. `release` es `null` o contiene `version_id`, `version`, `develop` y `status: claimed|published|reusable`. Se prioriza el reclamo de release activo. Si perdió su acuerdo, `blocked_release` identifica el reclamo que se debe conciliar sin disparar otro release.

`POST tickets/{id}/release-claim` recibe `agreement_id`, `draft_version_id`, `draft_version` y `develop` (mapa de SHA completo de `empresa-api` y `empresa-spa`). Solo reclama el borrador vigente y verifica que el arreglo sea ancestro de los candidatos que lo contienen. Si el snapshot carece de borrador, `draft_version_id: null` permite crearlo con el código candidato dentro de la transacción del reclamo. Hay un único reclamo activo para empresa. Repetir la misma identidad devuelve el reclamo. Si el acuerdo es nuevo y la misma evidencia tiene una versión reutilizable publicada, se usa su tupla exacta y se liga al nuevo acuerdo sin otro release de GitHub.

`POST tickets/{id}/release-published` recibe `agreement_id`, `version_id`, `version`, `develop`, `trigger_commits`, `tag_commits` y `artifacts` (`id`, `sha256` sin prefijo, `size`, `name` por repositorio), además de `title` y `description` opcionales. El admin consulta los dos tags, la ascendencia y los árboles exactos, la corrida exitosa de cada workflow y los ZIP con sus digests. Revalida consentimiento y borrador antes de publicar en la misma transacción que el vínculo exacto al acuerdo. El registro del release no ejecuta un upgrade.

`POST tickets/{id}/release-blocked` recibe `agreement_id`, `reason` y `partial`. Conserva el bloqueo si pudo haber efectos en GitHub. Solo libera un reclamo no publicado al verificar ambos `develop` en sus SHAs reservados y ambos tags ausentes. Un release ya publicado conserva su prueba al bloquear una programación vencida y puede reutilizarse después de un nuevo acuerdo.

`POST tickets/{id}/diagnostic` con `{lease_token,query:string}` consulta el ERP correspondiente a ese ticket, sin pasar por un modelo de API. Devuelve `{data:<payload remoto>,queried_at}`. Es el lector existente `admin-sync/sistema-query` (stock, ventas, facturas, clientes), no SQL arbitrario. Si no hay acceso/configuración/datos válidos devuelve 502, nunca un resultado vacío presentado como éxito. Las claves permanecen en el servidor. No acepta client_id, owner ni URL del consumidor.

## Respuesta idempotente

`POST tickets/{id}/reply`:

```json
{"lease_token":"...","expected_message_id":123,"operation_id":"uuid-sin-reusar","action":"responder","body":"Texto al cliente","thread_id":"...","mission":null,"confirmation_message_id":null}
```

`action`: `responder`, `solicitar_confirmacion`, `cerrar` o `escalar`. `escalar` requiere `mission` con la misión preparada. `cerrar` requiere `confirmation_message_id` igual al último mensaje del cliente y evidencia de cierre. «Salió todo de diez» después de la orientación es un resultado consumado, igual que «quedó resuelta» o «lo arreglamos». «Listo» seguido de «Me di cuenta el error» puede confirmar una explicación concreta de la causa inmediatamente anterior, dentro del mismo ticket. «Todo normal», «todo bien» y «sí, gracias» sirven en `awaiting_confirmation`, cuando `last_reply_id` identifica la pregunta de resolución o funcionamiento. Las negaciones, dudas y pruebas futuras mantienen el caso abierto. El cierre de cortesía tiene su propio criterio descrito abajo.

Si el agente manda `responder` con una despedida breve como «De nada Gonzalo!» y el entrante vigente confirma el resultado de la orientación inmediatamente anterior, el admin aplica `cerrar` después de confirmar el envío. `operation.action` informa la acción efectiva y el reintento usa el payload original. Un agradecimiento solo, una revisión pendiente o una respuesta que sigue ofreciendo ayuda conservan la acción pedida. Si el ticket requiere verificación, la despedida pasa por la aprobación habitual. Una edición que retoma el diálogo vuelve a `responder`.

Respuesta: `{operation:{id,status,message_id,action,detail},ticket:<contexto>}`. `status` es `sent` o `unknown`. Solo `sent` confirma que WhatsApp aceptó todo el mensaje. No significa que el teléfono lo haya leído. El cuerpo preparado se envía sin prefijar una presentación: el tono y el saludo según la hora argentina son responsabilidad del protocolo del asistente. El cierre agrega «De nada» si faltó. La reserva se consume al responder; no mandar saludo y respuesta como dos llamadas separadas.

Cada operación se persiste antes del HTTP. La misma clave y mismo contenido devuelven el resultado guardado. Cambiar contenido con igual clave es 409. `GET operations/{operation_id}` recupera ese resultado. Si el proceso cayó o WhatsApp no confirmó entrega, el estado es ambiguo: no reintentar automáticamente con una clave nueva. Se debe conciliar en el admin. Un envío ambiguo nunca cierra un ticket.

`POST operations/{operation_id}/reconcile` es solo para una decisión expresa de Lucas después de revisar: `{outcome:"sent"|"not_sent",reason,confirmed_by:"Lucas González",whatsapp_message_id?,evidence?}`. Sent exige ID WhatsApp y evidencia textual mínima de 15 caracteres; razón mínima 10. Guarda la auditoría completa y deja el caso en revisión manual. No reenvía, no cierra y no aplica por su cuenta la acción original; necesita resume explícito para volver al circuito. `operation.status` es `sent` o `not_sent` después de conciliar. Una operación definitiva no se reconcilia de nuevo y no se concilia mientras haya una reserva activa.

La respuesta se descarta si cambió el último mensaje, Lucas tomó la atención, venció la reserva o apagó el circuito. Fuera de la ventana de 24hs devuelve 422 sin enviar ni inventar una plantilla. El envío y los checks de entrega usan los servicios existentes del admin.

`solicitar_confirmacion` deja `awaiting_confirmation`: un solo seguimiento a las 4 horas, solamente dentro de la ventana. El worker debe pedir confirmación, no dar el caso por cerrado. El silencio no cierra. `escalar` deja `mission_pending`; no puede pedir confirmación de una corrección todavía pendiente. Al responder desde el admin, el caso queda en `manual_review` y la reserva anterior se invalida. Esa pausa por respuesta humana dura hasta el próximo mensaje real del cliente: si el agente sigue encendido, `pending` vuelve a ofrecerlo con `reason: inbound` y `claim` abre una atención con reserva nueva, conservando el hilo y cualquier misión pendiente. La pausa y la creación del mensaje humano se guardan bajo el mismo bloqueo del ticket. Un entrante anterior o un borrador no la levantan. Las revisiones por errores, conciliación o entregas inciertas siguen bloqueadas; una respuesta humana tampoco cambia su motivo para volverlas recuperables. El interruptor apagado sigue impidiendo la atención.

El interruptor por ticket (`POST /api/admin/support-ticket/{id}/toggle-claude-auto-reply`) invalida la reserva anterior al apagar. Al encender vuelve a evaluar lo pendiente sin esperar otro mensaje: si la pausa era porque Lucas tomó la atención desde el admin y hay un último entrante sin atender, devuelve el caso a `ready`, conserva el hilo y deja ese entrante disponible para `pending`/`claim`. No consume el mensaje como hace `resume`. Si no existe caso todavía, el monitor ya lo encuentra por `pending`. No reactiva revisiones por errores, misiones pendientes, operaciones ambiguas, reservas vigentes ni tickets cerrados; tampoco duplica la última respuesta de Lucas o del agente. Las restricciones de envío y ventana de WhatsApp siguen vigentes. Para el proveedor anterior, encender programa la sugerencia del último entrante únicamente si todavía no tiene respuesta.

`SupportCodexService::agent_toggled` permite recuperar una pausa humana ya encendida de forma idempotente. Su llamador debe abrir transacción y bloquear primero el ticket. `solicitar_confirmacion` sigue rechazando con `409 mission_pending` mientras haya una misión pendiente. `cerrar` admite una confirmación suficiente cuando la misión ya fue iniciada; mantiene su vínculo y no da por publicada la mejora. Si hay una actualización acordada vigente o en curso, devuelve `409 upgrade_in_progress` y conserva abierto el ticket. Una importación de prueba en curso o sin estado comprobable también conserva el ticket abierto.

`POST tickets/{id}/release` con `{lease_token,thread_id?,reason?,manual_review?:boolean}` devuelve `{released:true}`. Sin manual_review se puede reintentar el trabajo más adelante. Con manual_review el caso queda pausado. Los mensajes a los leads y el resto de las automatizaciones no se modifican.

`POST tickets/{id}/resume` es una acción explícita de Lucas: `{expected_message_id,reason,request_confirmation?:boolean}`. Requiere el último mensaje actual y ninguna reserva vigente ni entrega ambigua. Sin request_confirmation, vuelve a ready pero marca los mensajes anteriores como atendidos: esperará un nuevo entrante. Con request_confirmation=true (solo después de verificar la misión en producción), deja ready_to_confirm y entrega reason=mission_completed al monitor para pedir que el cliente pruebe, únicamente si la ventana sigue abierta. Conserva el thread_id y registra el motivo de Lucas. No inicia una misión ni manda nada por sí solo.

El modo opcional `continue_pending:true` de esa misma ruta recupera una consulta pendiente por instrucción expresa de Lucas, sin declarar resuelta una misión. Es incompatible con `request_confirmation:true` (422 `incompatible_resume_mode`). Además de las restricciones anteriores, exige que el último mensaje sea del cliente y su id sea mayor que `handled_message_id` (409 `not_pending` si no), y que la ventana de WhatsApp esté abierta (422 `window_expired`). Deja `mission_pending` si hay una misión guardada, o `ready` si no; conserva el texto de la misión, `handled_message_id`, `last_reply_id`, `thread_id` y la escalación del ticket. No consume el entrante, no envía, no cierra: `pending` vuelve a ofrecer ese mensaje como `inbound` para una nueva atención. Limpia la reserva vencida y el seguimiento anterior, y registra `Retomado por Lucas: <reason>`. El modo ausente o falso conserva íntegro el comportamiento anterior de resume. Este modo no se usa automáticamente desde los mensajes del cliente ni desde una atención de Martín.

El contexto incluye `can_query_system`. Un empleado sin ese permiso recibe 403 en diagnostic; no obtiene datos del negocio por haber escrito al soporte. Esta restricción también debe respetarse al consultar producción desde herramientas locales. El diagnóstico técnico interno no autoriza divulgar ventas/caja/clientes a un contacto sin permiso.

### Oferta de Robotito

`capabilities.support_tour_offer` habilita la oferta escrita para un entrante real del cliente cuando la versión registrada de su sistema es estrictamente superior a 3.2.47. Si falta el dato de versión, se conserva el criterio de canal u origen autenticado. `capabilities.support_dynamic_tour` exige además la aptitud del entrante y, si la versión es conocida, que admita guías personalizadas desde 4.3.44.

En una respuesta de texto que explica cómo usar el sistema, el monitor puede mandar `usage_answer: true` con `action: responder` cuando `support_tour_offer` está disponible. El cuerpo trae la explicación y el video pertinente si existe. Admin agrega la pregunta de Robotito y explica cómo actualizar o volver a entrar con la cuenta propia si corresponde. Si el monitor omite el campo, Admin puede inferir una consulta directa de uso. Los pedidos de aclaración, rechazos, adjuntos y cierres conservan su flujo. Una oferta en borrador sigue sujeta a la aprobación de la respuesta.

`tour_requested: true` sigue exigiendo la pregunta canónica en el cuerpo y la capacidad de Robotito en el entrante. Admin comprueba el umbral si conoce la versión y ajusta el texto a la capacidad de esa instalación. Para preparar y enviar el tour, el cliente debe aceptar una invitación entregada en ese ticket desde una sesión o un contacto apto. Los tickets que requieren revisión no preparan el tour automáticamente.

## Nota en la implementación: `POST tickets/{id}/implementation-note` (desde el 26/10/2026)

`{lease_token, texto (1..1000), etapa? (1..7, default la actual)}`. Martín deja asentado algo de la implementación del SISTEMA en curso del cliente del ticket. Solo agrega a `data.actions[]` de la etapa `{action:"nota", stage, at, canal:"whatsapp", origen:"martin", texto, support_ticket_id}`; no cambia estados ni etapas y no manda nada. Respuesta `{registrada, implementation_id, etapa, at}`: 201 con `registrada:true`; 200 con `registrada:false` si el mismo texto del mismo ticket ya estaba en esa etapa (no se duplica). Errores: 423 reserva inválida, 409 `sin_implementacion_abierta`, 422 validación o `sin_etapa`, 404 ticket inexistente. El cliente y la implementación salen del ticket, nunca del body.

## Hitos de la implementación por el ticket (desde el 26/10/2026)

`/implementar` manda los hitos de la implementación (presentación, link del formulario, pedido de archivos, entrega...) con `POST /api/claude/implementations/{id}/whatsapp`, por el ticket de soporte del contacto (lo describe `GET claude/catalog`). Para el monitor importa esto:

- El mensaje queda en el ticket como `sender_type: admin` (el remitente es el admin con el que firma `claude/*`), así que aparece en `messages` y en `history` como cualquier respuesta de Lucas.
- Si Martín tiene la reserva vigente del ticket que se reusaría, el envío es 423 `martin_atendiendo` y no se manda nada.
- Si el ticket tiene algo esperando a Martín (`pending_reason` no nulo: `inbound`, `follow_up` o `mission_completed`), el envío es 423 `martin_pendiente`: el hito no tapa un entrante sin contestar.
- Con el caso en `manual_review` el hito sale con el aviso `martin_en_pausa`. Si la pausa fue solo por una respuesta humana y el agente sigue encendido, el próximo entrante real vuelve a Martín con reserva nueva; una revisión por error o conciliación requiere que Lucas la retome explícitamente.
- El hito solo sale si la respuesta del cliente va a caer en el ticket (`respuesta_ira_a: ticket`, la misma decisión que el webhook); si no, 422 `respuesta_al_hilo`.
- El envío NO pasa el caso a `manual_review` (no es «Lucas tomó la atención»): cuando el cliente conteste, el ticket vuelve a `pending` con `reason: inbound` como siempre. Si llegara a mandarse entre un `claim` y su `reply`, la respuesta de Martín da 409 (`stale_message`) y el monitor relee.

## Errores

401: clave ausente/incorrecta. 404: recurso inexistente o adjunto de otro ticket. 409: mensaje viejo, ticket ineligible/atendido, misión pendiente o conflicto idempotente. 422: validación, confirmación insuficiente, ventana vencida. 423: reserva ocupada/vencida/incorrecta. 502: diagnóstico remoto no disponible. El worker relee ante 409 y no publica resultados de un contexto viejo.


## Automatización, aprobación y avisos por ticket (30/10/2026)

Cada ticket nuevo nace con `requiere_verificacion_mensajes=false`, incluso si una Cuenta o un consumidor anterior manda `true` al crearlo. La decisión posterior se toma únicamente con el botón de ese ticket. No se modifican los tickets existentes al desplegar. `SupportAiSettings.require_verification` se conserva en el JSON por compatibilidad, siempre `false`.

El contexto agrega `requires_verification` y las capacidades `human_approval=true` y `recover_dispatch=true`. El helper que entiende aprobación incluye `supports_human_approval:true` en `/reply`. Si el ticket exige verificar, se crea un `SupportMessage` con `is_ai_suggestion_draft=true` sin timer y una operación `awaiting_approval`; no se toca WhatsApp ni se marca el entrante como atendido. Un helper anterior sin esa negociación recibe 422 `verification_required` antes de persistir o enviar.

El operador usa los endpoints existentes `POST /api/admin/support-message/{id}/approve-ai-draft` con `body` opcional (hasta 3500 caracteres), y `POST .../discard-ai-draft`. Aprobar revalida ticket abierto, agente prendido, mensaje actual, misión, confirmación y ventana de WhatsApp, y reserva un solo intento antes de tocar la red. Repetir la aprobación de una operación ya enviada no vuelve a enviar. Descartar reconoce ese entrante como decisión humana, conserva la misión y no regenera el mismo texto: el próximo entrante vuelve a ser elegible. Una intervención humana, apagar el agente o un entrante nuevo invalidan el borrador anterior; no se reutiliza texto obsoleto.

Apagar verificación no envía el borrador que ya esperaba aprobación: sigue visible para enviarlo o descartarlo explícitamente. Las próximas respuestas son automáticas; si llega otro mensaje, la aprobación anterior se cancela y el nuevo se atiende con el modo vigente.

`operation.status` agrega `awaiting_approval` y `not_sent`. `raw_status=cancelled` significa rechazo determinado antes del envío, distinto de `unknown` posterior a un intento de entrega. Solo una atención con reserva vigente y mismo ticket/mensaje puede reformular una operación cancelada; los hashes de operaciones `sending`, `unknown` y `sent` conservan su inmutabilidad. El payload de aprobación es durable y nunca guarda la reserva.

`PATCH /api/admin/support-ticket/{id}/attention` exige `{needs_human:boolean}` y devuelve `{model:ticket}`. `support_attention.can_toggle=true` anuncia el control. Apagar el aviso reconoce la señal visual, sin cerrar el ticket, borrar misión, atender un entrante ni autorizar una entrega incierta. Una nueva revisión del agente lo vuelve a encender. El motivo operativo se conserva aunque Lucas apague la señal. Los casos previos conservan su cálculo heredado hasta un cambio explícito.

La push durable informa tanto intervención como aprobación pendiente, agrega `approval_pending` y enlaza `/soporte/ticket/{id}`. Se deduplica por generación y dispositivo, reintenta fallas y suprime avisos obsoletos al aprobar, descartar o apagar la alerta. La aceptación del proveedor no demuestra recepción física en el teléfono.

Una apertura incierta puede recuperar `/claim` con `dispatch_recovery:true`, `execution_id` UUID, `thread_id` y el mensaje original. Solo se admite el motivo técnico exacto de apertura sin confirmar, con el mismo mensaje esperado y sin respuesta humana posterior, reserva ajena ni operación sin terminar. Los claims nuevos pueden guardar `execution_id`; una recuperación debe coincidir con el UUID ya registrado. Para casos anteriores sin UUID, el monitor tiene que verificar en las herramientas oficiales de la app el ticket y el UUID exacto de su disparador. No usa `/resume`, no borra una misión ni reenvía una respuesta.


## Vigilancia del monitor (02/11/2026)

`POST support-codex/health`, protegido por `X-Claude-Task-Key`, recibe cada 60 segundos un pulso independiente de la consulta de pendientes. Campos opcionales: `status` (`ok` por defecto, o `error`), `error_code` (null o `starting`, `poll_stale`, `poll_error`, `queue_stale`, `app_error`, `dispatch_unknown`, `monitor_error`) y `monitor_version` (hasta 40 caracteres alfanuméricos, punto, guion o guion bajo). No recibe mensajes, datos de clientes, trazas ni secretos. Las horas confiables son las de recepción del servidor. HTTP 200 devuelve `accepted=true`, `server_time` ISO y `last_success_at` Unix o null. Un proveedor viejo puede devolver 404/405: el consumidor conserva la atención, reporta falta de vigilancia y no vuelve a probar en cada iteración.

`status=ok` certifica el circuito local saludable que el monitor comprueba, no que todos los tickets estén contestados. Los pulsos `error` no renuevan el último éxito ni reinician el primer error sostenido. La fila JSON durable `admin_settings.support_codex_monitor_health` se arma únicamente al primer pulso. No requiere migración ni cambia reservas, operaciones o tickets.

El scheduler corre `support:check-codex-health` cada minuto en segundo plano. Con el proveedor habilitado, cinco minutos sin pulso o de error continuo disparan correo a **lucasgonzalez5500@gmail.com**. No depende de la PC. El mailer `support_monitor` reutiliza MAIL_ADMIN_* con timeout de 15 segundos y exige SMTP real fuera de testing: log/array no consumen una alerta. Una fila bloqueada serializa heartbeat, comandos y scheduler; solo se guarda notificado luego de aceptación del transporte. Falla SMTP se reintenta al minuto siguiente. Mientras persiste el incidente, recordatorio cada seis horas; tras un pulso saludable se envía recuperación una vez. La recuperación no asegura resolución de tickets. Proveedor apagado intencionalmente suprime avisos.

`support:check-codex-health --prueba-correo` manda una prueba al destinatario fijo sin armar/modificar la vigilancia ni sus incidentes. SMTP aceptado no prueba lectura ni llegada a bandeja. Si el proceso muere entre aceptación SMTP y commit de la marca puede salir un duplicado al reintentar; no hay garantía exactly-once del correo. El monitor mantiene sus frenos de no repetir respuestas WhatsApp inciertas.

Límite: esta vigilancia detecta PC apagada, red caída o proceso/circuito local enfermo mientras el admin y su scheduler/correo funcionan. Una caída simultánea del VPS o del SMTP no puede avisarse por ese mismo canal; se conserva el reintento y registro del fallo.


## Solicitud manual desde Sugerencia de la IA (02/11/2026)

POST `/api/admin/support-ticket/{id}/suggest` conserva las claves de sugerencia anteriores y, para tickets administrados por Codex, agrega `codex_attention: {status, code, message}`. Estados: `queued`, `in_progress`, `awaiting_approval`, `blocked`. El texto de `reasoning` conserva un resultado legible para SPA anteriores. Es el resultado puntual de la solicitud; los eventos de mensajes invalidan el aviso de la SPA.

La solicitud bloquea ticket y caso igual que claim/reply, relee elegibilidad y no enciende el agente. Solo libera una pausa humana segura para el último entrante pendiente; conserva estados ya elegibles, hilo, misión y handled_message_id. El estado durable ready es el mismo que consulta el monitor: no hay una segunda cola ni un envío directo. Clics repetidos no crean operaciones ni reservas. Una reserva activa informa en curso; una aprobación válida se conserva; una aprobación obsoleta se invalida igual que en pending. Operaciones sin terminar, revisiones técnicas y ventana WhatsApp vencida mantienen sus guardas.

El encendido del agente sigue usando agent_toggled: retoma la pausa humana pendiente sin necesitar otro entrante. No usar resume para este objetivo porque consume el último mensaje.


## Cierre natural por agradecimiento (ampliación 02/11/2026)

`capabilities.natural_thanks_closure=true` permite al helper reconocer el backend compatible antes de pedir un cierre de cortesía. `reply` conserva su contrato: `action=cerrar`, `confirmation_message_id` del último entrante y `body` de despedida. Un agradecimiento natural después de orientación, o como único entrante sin otra consulta pendiente, permite responder de nada y cerrar sin exigir otra confirmación ni afirmar que el cliente probó una solución. Se incluyen cortesías coloquiales y elogios sin nueva consulta. Misiones, revisiones humanas pendientes, solicitudes de información, pruebas futuras, dudas, negaciones y envíos inciertos conservan el caso abierto.

Política anterior modificada por pedido explícito de Lucas: «Gracias» tras «¿Quedó resuelto?» estaba en el dataset de rechazos y en el test `test_agradecer_o_prometer_probar_no_confirma_resolucion`. Ahora tiene regresión positiva de cierre natural; las promesas de probar y condiciones siguen rechazadas. El cierre se aplica únicamente tras entrega confirmada, o después de aprobar el borrador si requiere verificación. Un entrante nuevo invalida el cierre anterior. Los replays idempotentes no repiten la despedida.

## Actualización programada desde soporte

`POST support-codex/tickets/{id}/schedule-upgrade` recibe `agreement_id` y `version_id`. Ambos tienen que corresponder a la corrección vigente y a un release publicado con evidencia. Devuelve `scheduled_upgrade` con el id durable, el horario UTC y el estado. Repetir los mismos IDs devuelve la misma fila. Si el horario ya pasó y todavía no había fila, devuelve 409.

`GET support-codex/tickets/{id}/scheduled-upgrade` devuelve `scheduled_upgrade` o null. Los estados son `scheduled`, `starting`, `post_closure`, `configuring`, `verification_pending`, `verified`, `blocked` y `failed`. El scheduler del admin ejecuta el pipeline en el lugar. Revalida cierre, señales de actividad, acuerdo y versión exacta antes de subir archivos, antes de reemplazar el SPA y antes de las etapas posteriores. El arranque acepta hasta cinco minutos de demora. Los ZIP deben coincidir con los identificadores, tamaños y huellas verificados. Una pérdida de autorización después de tocar producción deja `failed` con motivo `reconciliar_*`. Los arranques y reintentos del carril manual rechazan upgrades ligados a un acuerdo.

`POST support-codex/tickets/{id}/verify-scheduled-upgrade` recibe `agreement_id`, `upgrade_id`, `mission_thread_id`, `mission_hash`, `resultado` (`verificado` o `inconcluso`) y `evidencia` textual de reproducción de solo lectura. `verification_pending` requiere versión/frente/configuración confirmados y comprobación de HTTP. La reproducción verificada permite que el agente pida confirmación al cliente. El ticket sigue abierto y el envío conserva la ventana de WhatsApp. Con capacidad `verification_fallback`, el monitor puede adjuntar `motivo=verificacion_sin_resultado` a un inconcluso. Ese fallback conserva cualquier evidencia que haya llegado antes.

El flag `SUPPORT_CODEX_SCHEDULED_UPGRADES_ENABLED` habilita el comando por minuto y la capacidad `scheduled_upgrade`. El valor por defecto es falso. El scheduler usa la cola `database` del admin y la preparación/reproducción se mantienen en el monitor de soporte.

## Lectura del admin para soporte (11/11/2026)

Misión cruzada `acceso-soporte-al-admin-lectura`. Tres rutas de **solo lectura**, con la clave de ingesta (`X-Claude-Task-Key`) y `Accept: application/json`. No piden reserva ni ticket: el monitor las llama desde un ticket con ejecución vigente, pero el admin no lo exige. Todo es aditivo: ninguna ruta ni campo anterior cambia. Un admin que todavía no las tiene contesta 404 y el monitor lo trata como `disponible: false`.

Errores: los de todo `claude/*` — 422 `{error, errores, ayuda}` por parámetros inválidos, 404 `{error}` por un `client_id` que no existe, 405 por un verbo que la ruta no admite.

### `GET support-codex/client-versions`

Query: `client_id` (entero, opcional), `q` (texto de hasta 100 caracteres; busca en `name`, `company_name` y `slug`), `limit` (1–200, default 50; fuera de rango se recorta) y `after_id` (cursor). Sin filtros lista todos los clientes, por `id` ascendente.

```json
{"data":[{"client_id":1,"name":"…","company_name":"…","slug":"…","estado":"…","is_active":true,
  "sistema":{"version":"4.3.50","version_id":12,"published_at":"…","ultima_publicada":"4.3.51",
             "al_dia":false,"versiones_pendientes":1,"hosting_type":"vps",
             "ultimo_upgrade":{"id":9,"status":"…","to_version":"4.3.50","finished_at":"…"}},
  "tiene_ecommerce":true,
  "ecommerce":[{"id":3,"domain":"…","status":"…","version":"1.2.0","ultima_publicada":"1.2.1","al_dia":false}]}],
 "ultima_version_sistema":"4.3.51","ultima_version_ecommerce":"1.2.1","has_more":false,"next_after_id":null,"server_time":"…"}
```

- `sistema.version`, `version_id`, `published_at`, `al_dia` y `versiones_pendientes` son `null` si el cliente no tiene `current_version_id`. `versiones_pendientes` cuenta las versiones **publicadas** posteriores a la del cliente.
- `sistema.hosting_type` es el del frente activo (`clients.active_client_api_id`), o `null` si no tiene. `ultimo_upgrade` es el de `id` más alto del cliente, o `null`.
- `ecommerce` es `[]` si el cliente no tiene tienda. Solo tiendas de clientes, nunca de demos. `version` y `al_dia` son `null` si la tienda no tiene versión registrada (instalada por la vía vieja).
- «Publicada» es `versions.status = published` / `ecommerce_versions.status = published`. «Última» y «al día» se calculan por orden semántico del número (`VersionNumberComparator`), no por `id` ni como texto.
- Las fechas van en ISO 8601. `has_more` acompaña a `next_after_id` (que es `null` en la última página).

### `GET support-codex/feature-lookup`

Query: `q` (requerido, 2–100 caracteres), `client_id` (opcional), `sistema` (`empresa` | `tienda`, opcional; default los dos) y `limit` (1–50, default 20).

Busca las palabras de `q` con LIKE, sin distinguir mayúsculas ni acentos (`electronica` encuentra «electrónica»); tienen que aparecer **todas**. Antes de buscar:

- se sacan las palabras vacías: `como, cómo, con, de, del, la, el, los, las, para, por, que, qué, un, una, en, al, se, mi, su, es, lo, le, y, o, a` y toda palabra de una sola letra. Las de dos letras que no están en la lista se buscan (`QR`, `ML`, `MP`);
- se saca el plural simple de las palabras de más de 4 letras (termina en `es` → sin `es`; si no, en `s` → sin `s`): «facturas electrónicas» encuentra «factura electrónica»;
- si no queda ninguna palabra: 422 `{error, codigo: "busqueda_sin_palabras"}`.

Para `empresa` mira las novedades activas (`version_notifications.title/body`, origen `novedad`) y las versiones (`versions.title/description`, origen `version`); para `tienda`, las versiones de tienda (`ecommerce_versions.title/description`, origen `version`). Entran versiones en cualquier estado. Las más nuevas primero (por fecha de publicación; sin publicar, por fecha de alta).

```json
{"q":"…","resultados":[{"sistema":"empresa","version":"4.3.40","version_status":"published","publicada":true,
   "published_at":"…","titulo":"…","texto":"…","origen":"novedad",
   "cliente":{"tiene":true,"version_cliente":"4.3.50","motivo":"…"}}],
 "hay_mas":false,
 "cliente":null,
 "resumen_cliente":{"tiene_alguna":true,"version_mas_vieja_que_la_trae":"4.3.40","sistema":"empresa"},
 "lectura":"Si la misma función aparece en varias versiones, la que decide si el cliente la tiene es la más vieja publicada; las posteriores son mejoras.",
 "aviso":"La disponibilidad es por versión; una extensión o el plan pueden condicionarla."}
```

- `texto` va sin HTML, en una línea, con hasta 600 caracteres. `hay_mas` dice si quedaron coincidencias afuera del `limit`.
- `cliente` (arriba) es `null` sin `client_id`, o un ítem con la misma forma que los de `client-versions`.
- `resultados[].cliente` está siempre, y `motivo` lo explica siempre. `tiene` es:
  - `true` si la versión **se publicó** y la del cliente es igual o posterior. «Se publicó» es `published`, o `archived` con `published_at`: archivar una versión no les saca la función a los clientes que ya pasaron por ella;
  - `false` si la versión se publicó y la del cliente es anterior, o si la versión es un borrador (`draft`: «todavía no se publicó»);
  - `null` si no se pasó `client_id`, si el cliente no tiene versión registrada, si la versión está archivada sin fecha de publicación o, para `tienda`, si el cliente no tiene una tienda instalada con versión registrada.
- Para `tienda` deciden solo las tiendas **instaladas** (`client_ecommerces.status = active`); una `pending` o `installing` no cuenta y el motivo lo dice. Con varias instaladas, `tiene` es `true` solo si todas la tienen, y `version_cliente` es la más vieja de las registradas.
- `resumen_cliente` es `null` sin `client_id`. Con cliente se calcula sobre **todas** las coincidencias, no solo sobre la página devuelta: `tiene_alguna` es `true` si en al menos una `tiene` es `true`; `version_mas_vieja_que_la_trae` es la versión más vieja, entre las coincidencias que se publicaron, por orden semántico (o `null` si ninguna se publicó); `sistema` dice de qué sistema es esa versión (`empresa` si hubo coincidencias de los dos, `tienda` si solo de tienda, `null` si no hay versión).
- `lectura` viaja siempre: si la misma función aparece en varias versiones, decide la más vieja publicada.
- **La disponibilidad es por versión.** Que el cliente esté en la versión no dice si tiene la extensión o el plan que la habilitan.

### `POST support-codex/admin-sql`

Body: `{"sql": "SELECT …"}` (1–12000 caracteres). SQL de solo lectura sobre la base del admin. Es POST porque la consulta viaja en el cuerpo; no escribe nada. La consulta se ejecuta tal cual llegó (sin los espacios de los bordes).

```json
{"ok":true,"read_only":true,"columns":["id","name"],"rows":[{"id":1,"name":"…"}],"row_count":1,"truncated":false}
```

Hasta 200 filas (`truncated: true` si había más) y los textos cortados a 4000 caracteres (terminan en ` [recortado]`). Cada fila es un objeto por nombre de columna; si dos columnas se llaman igual (un JOIN con `*`), queda la última: poné alias.

Barreras de la consulta, todas del lado del admin (el monitor valida además por su cuenta):

- Una sola sentencia: sin `;`, `--`, `/*`, `#` ni `@` **fuera de un texto entre comillas simples** (`email = 'a@b.com'` pasa), sin `\0` y sin comillas dobles fuera de un texto. Las cadenas se vacían antes de analizar; una comilla o un backtick sin cerrar se rechazan. Entre backticks solo se admite un nombre simple (letras, números y guion bajo).
- Solo `SELECT`, `EXPLAIN SELECT`, `SHOW TABLES`, `SHOW [FULL] COLUMNS FROM tabla` y `DESCRIBE tabla`. Sin `SHOW CREATE TABLE`, `UNION`, `WITH`, `TABLE`, `VALUES` ni `FOR SHARE`.
- Palabras prohibidas iguales a `validar_sql` de `herramientas/soporte-codex/diagnostico.py` (más `UNION`, `WITH`, `TABLE`, `VALUES`, `SHARE`).
- **Lista positiva de funciones**, que es un subconjunto de la del monitor: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `COALESCE`, `IFNULL`, `NULLIF`, `IF`, `CONCAT`, `CONCAT_WS`, `LOWER`, `UPPER`, `LTRIM`, `RTRIM`, `LENGTH`, `CHAR_LENGTH`, `ROUND`, `ABS`, `CEIL`, `CEILING`, `FLOOR`, `DATE`, `DATE_FORMAT`, `YEAR`, `MONTH`, `DAY`, `DAYOFMONTH`, `HOUR`, `MINUTE`, `SECOND`, `NOW`, `CURDATE`, `CURRENT_DATE`, `CONVERT_TZ`, `DATE_ADD`, `DATE_SUB`, `DATEDIFF`, `TIMESTAMPDIFF`, `JSON_LENGTH`, `JSON_VALID`, `STR_TO_DATE`, `GREATEST`, `LEAST`, `GROUP_CONCAT`, `DATABASE`, `VERSION`.
- **Los textos y los JSON se devuelven enteros.** No se admite ninguna función que devuelva un fragmento de su argumento o que separe una etiqueta de su valor, porque la redacción trabaja sobre el texto entero («Contraseña: X») y sobre el JSON entero (por clave): `JSON_EXTRACT`, `JSON_UNQUOTE` (y `JSON_VALUE`, `JSON_SEARCH`, `JSON_KEYS`, `JSON_QUERY`, `JSON_TABLE`) y los operadores `->` / `->>` → 422 `json_no_permitido`; `SUBSTRING`, `SUBSTR`, `LEFT`, `RIGHT`, `TRIM`, `CAST`, `CONVERT` → 422 `funcion_no_permitida` (`REPLACE` ya estaba prohibida como palabra). `LTRIM` y `RTRIM` quedan: solo sacan espacios.
- Un paréntesis solo puede ir pegado a una función de la lista, a `IN`, `EXISTS`, `NOT`, `OVER`, `AS` o, como agrupación, a `AND`, `OR`, `WHERE`, `ON`, `HAVING`, `WHEN`, `THEN`, `ELSE`. `FROM (…)`, `JOIN (…)` y `SELECT (…)` se rechazan.
- Sin `base.tabla` ni `information_schema` / `mysql` / `performance_schema` / `sys`.
- `*` dentro de un paréntesis solo como `COUNT(*)` o como multiplicación (`SUM(a * b)`, `ROUND(a * 1.21, 2)`); un comodín (`(SELECT * …)`, `(t.*)`, `COUNT(DISTINCT *)`) se rechaza.
- Nombres sensibles en el texto → rechazo: `password|passwd|secret|token|api.?key|private.?key|session.?id|authorization|credential|clave.fiscal|encrypted|cifrad|p256dh|env_manual_values`. Es por subcadena: alcanza a nombres de tablas y columnas que lo contienen (`client_ai_token_usages`, `input_tokens`).
- **Columnas reservadas** (`config/claude_admin_sql.php` → `columnas_reservadas`, con el motivo de cada una): si el nombre aparece como palabra en la consulta, rechazo. Son `request`, `response`, `raw`, `setup_data`, `ecommerce_setup_data`, `approval_payload`, `env_manual_values`, `data`, `detail`, `detalle`, `template_variables`, `variables`, `line`, `log`, `referencia_base64`, `datos`, `contexto_web`, `accion_payload`, `datos_de_soporte`, `artifacts`, `proof`, `calendar_snapshot`, `accepted_endpoint_hashes`, `clave`, `contrasena`, `contraseña`, `pin`. `data` está por las etapas de implementación (`implementation_stages.data` y pares guardan `admin_password` y tokens); el estado de una implementación se lee por `claude/implementations`. Son nombres globales: valen en cualquier tabla.
- **Tablas vedadas** (como palabra en la consulta, también en `SHOW COLUMNS` / `DESCRIBE`): `admin_settings`, `env_templates`, `env_change_batches`, `env_change_items`, `client_ssh_credentials`, `admin_push_subscriptions`, `admin_calendar_connections`, `personal_access_tokens`, `password_resets`, `sessions`, `jobs`, `failed_jobs`, `job_batches`, `recall_configs`, `whatsapp_config`, `mensualidad_invoice_pdf_access_tokens`, `recursos_links`, `recursos_sesiones`, `support_codex_task_grants`, `trial_instances`.

Ejecución: en una conexión aparte, con `SET SESSION TRANSACTION READ ONLY` + transacción de solo lectura + `max_execution_time` de 8 s; siempre rollback.

Redacción del resultado:

- toda columna cuyo nombre matchee el patrón sensible o sea una columna reservada sale como `[dato reservado]` (es lo que cubre `SELECT *`), y lo mismo toda clave de un valor JSON, a cualquier profundidad;
- en los textos se tapa además el contenido de las etiquetas XML/HTML de credenciales (`Token`, `Sign`, `Password`, `Clave` y las de nombre sensible, con o sin namespace, también escapadas como `&lt;Token&gt;`), **todo el resto de la línea** después de una etiqueta de clave —`contraseña`, `contrasena`, `clave`, `password`, `passwd`, `pass`, `pwd`, `pin`, `token` y sus plurales, sin distinguir mayúsculas, aunque tenga texto pegado adelante— (`[oculta]`; si la línea termina en la etiqueta, se tapa la siguiente), un `Bearer …` y los `clave=valor` de nombres sensibles. Tapa de más a propósito: «Clave Fiscal nivel 3» pierde el resto de la línea.

**Fail-closed por columna de texto.** Toda columna `json`, `text` (cualquier tamaño) o `blob` de una tabla no vedada está decidida a mano en `config/claude_admin_sql.php`: o no se sirve (patrón sensible o `columnas_reservadas`), o figura en `columnas_de_texto_revisadas` con el motivo por el que es legible. Un test de build lo coteja contra el esquema real: una migración que agregue una columna de texto sin decidirla lo rompe. Lo que sigue siendo por nombre son las columnas `varchar`.

Rechazo: 422 `{error, codigo}` con `codigo` =

| `codigo` | Cuándo |
|---|---|
| `consulta_invalida` | no es texto, vacía, más de 12000 caracteres, no es UTF-8, comilla o backtick sin cerrar, o un backtick con algo que no es un nombre simple |
| `varias_sentencias` | `;`, `--`, `/*`, `#` o `@` fuera de un texto, o un carácter nulo |
| `comillas_dobles` | comillas dobles fuera de un texto |
| `campo_sensible` | nombra un campo sensible o una columna reservada |
| `tabla_vedada` | nombra una tabla vedada |
| `operacion_no_permitida` | una palabra prohibida (escrituras, `UNION`, `WITH`, `TABLE`, `VALUES`, bases del sistema, `SHOW CREATE TABLE`) |
| `solo_select` | no empieza con `SELECT` / `EXPLAIN SELECT` ni es una de las tres consultas de esquema |
| `otra_base` | `base.tabla` |
| `comodin_en_parentesis` | `*` comodín dentro de un paréntesis |
| `funcion_no_permitida` | una función fuera de la lista (incluidas `SUBSTRING`, `LEFT`, `RIGHT`, `TRIM`, `CAST`, `CONVERT`), o un paréntesis pegado a una palabra que no lo admite (`FROM (`, `JOIN (`, `SELECT (`) |
| `json_no_permitido` | `->`, `->>` o una función que extrae una parte de un JSON |

Si MySQL la rechaza: 422 `{error, codigo: "consulta_fallida", sqlstate, codigo_mysql}` con un resumen fijo, sin el mensaje crudo del driver.

Limitaciones conocidas: no se puede recortar un texto ni leer una clave suelta de un JSON (se devuelven enteros, hasta 4000 caracteres); no se puede filtrar ni ordenar por una columna reservada o de nombre sensible; los conteos de tokens de IA (`client_ai_token_usages`, `input_tokens`) no se pueden consultar por SQL porque su nombre matchea el patrón; no hay tablas derivadas ni subconsultas en la lista del SELECT.

Lo que se puede leer incluye datos comerciales y de contacto de clientes y leads: es para uso interno de soporte. Qué se le dice a un cliente lo siguen gobernando las reglas de Martín.

Riesgo residual que el SQL de solo lectura no cierra: sobre una columna de texto legible se puede PREGUNTAR (`WHERE body LIKE '%…%'`, `IF(…)`, `LENGTH(…)`), y con muchas preguntas se reconstruye un valor que el resultado muestra tapado. La redacción protege lo que se devuelve, no lo que se puede inferir; por eso las columnas que guardan credenciales están reservadas o en tablas vedadas, y una contraseña escrita en el cuerpo de un mensaje sigue siendo un dato mal guardado.

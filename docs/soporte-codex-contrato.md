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
  "messages":[{"id":123,"sender_type":"user","body":"No puedo vender","kind":"text","created_at":"...","attachments":[]}]
}
```

`client.slug` (string o null) es el slug del cliente en el admin: el programa local lo usa para ubicar la bitácora de la implementación cuando no tiene su `estado.json`.

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

Los últimos 100 mensajes reales se devuelven en orden cronológico; no incluye borradores. Los adjuntos traen `{id,mime,size,download_path}`; `GET tickets/{id}/attachments/{attachment_id}` sirve el binario con la misma autenticación y valida su pertenencia al ticket.

`POST tickets/{id}/diagnostic` con `{lease_token,query:string}` consulta el ERP correspondiente a ese ticket, sin pasar por un modelo de API. Devuelve `{data:<payload remoto>,queried_at}`. Es el lector existente `admin-sync/sistema-query` (stock, ventas, facturas, clientes), no SQL arbitrario. Si no hay acceso/configuración/datos válidos devuelve 502, nunca un resultado vacío presentado como éxito. Las claves permanecen en el servidor. No acepta client_id, owner ni URL del consumidor.

## Respuesta idempotente

`POST tickets/{id}/reply`:

```json
{"lease_token":"...","expected_message_id":123,"operation_id":"uuid-sin-reusar","action":"responder","body":"Texto al cliente","thread_id":"...","mission":null,"confirmation_message_id":null}
```

`action`: `responder`, `solicitar_confirmacion`, `cerrar` o `escalar`. `escalar` requiere `mission` con la misión preparada; no la ejecuta. `cerrar` requiere `confirmation_message_id` igual al último mensaje del cliente y evidencia textual de resolución. Gracias solos no alcanzan: después de pedir confirmación puede servir «sí, gracias». Una respuesta negativa o ambigua no cierra.

Respuesta: `{operation:{id,status,message_id,action,detail},ticket:<contexto>}`. `status` es `sent` o `unknown`. Solo `sent` confirma que WhatsApp aceptó todo el mensaje. No significa que el teléfono lo haya leído. El cuerpo preparado se envía sin prefijar una presentación: el tono y el saludo según la hora argentina son responsabilidad del protocolo del asistente. El cierre agrega «De nada» si faltó. La reserva se consume al responder; no mandar saludo y respuesta como dos llamadas separadas.

Cada operación se persiste antes del HTTP. La misma clave y mismo contenido devuelven el resultado guardado. Cambiar contenido con igual clave es 409. `GET operations/{operation_id}` recupera ese resultado. Si el proceso cayó o WhatsApp no confirmó entrega, el estado es ambiguo: no reintentar automáticamente con una clave nueva. Se debe conciliar en el admin. Un envío ambiguo nunca cierra un ticket.

`POST operations/{operation_id}/reconcile` es solo para una decisión expresa de Lucas después de revisar: `{outcome:"sent"|"not_sent",reason,confirmed_by:"Lucas González",whatsapp_message_id?,evidence?}`. Sent exige ID WhatsApp y evidencia textual mínima de 15 caracteres; razón mínima 10. Guarda la auditoría completa y deja el caso en revisión manual. No reenvía, no cierra y no aplica por su cuenta la acción original; necesita resume explícito para volver al circuito. `operation.status` es `sent` o `not_sent` después de conciliar. Una operación definitiva no se reconcilia de nuevo y no se concilia mientras haya una reserva activa.

La respuesta se descarta si cambió el último mensaje, Lucas tomó la atención, venció la reserva o apagó el circuito. Fuera de la ventana de 24hs devuelve 422 sin enviar ni inventar una plantilla. El envío y los checks de entrega usan los servicios existentes del admin.

`solicitar_confirmacion` deja `awaiting_confirmation`: un solo seguimiento a las 4 horas, solamente dentro de la ventana. El worker debe pedir confirmación, no dar el caso por cerrado. El silencio no cierra. `escalar` deja `mission_pending`; no puede pedir confirmación de una corrección todavía pendiente. Al responder desde el admin, el caso queda en `manual_review` y el worker deja de competir.

El interruptor por ticket (`POST /api/admin/support-ticket/{id}/toggle-claude-auto-reply`) invalida la reserva anterior al apagar. Al encender vuelve a evaluar lo pendiente sin esperar otro mensaje: si la pausa era porque Lucas tomó la atención desde el admin y hay un último entrante sin atender, devuelve el caso a `ready`, conserva el hilo y deja ese entrante disponible para `pending`/`claim`. No consume el mensaje como hace `resume`. Si no existe caso todavía, el monitor ya lo encuentra por `pending`. No reactiva revisiones por errores, misiones pendientes, operaciones ambiguas, reservas vigentes ni tickets cerrados; tampoco duplica la última respuesta de Lucas o del agente. Las restricciones de envío y ventana de WhatsApp siguen vigentes. Para el proveedor anterior, encender programa la sugerencia del último entrante únicamente si todavía no tiene respuesta.

`SupportCodexService::agent_toggled` permite recuperar una pausa humana ya encendida de forma idempotente. Su llamador debe abrir transacción y bloquear primero el ticket; no manda mensajes por sí mismo.

`POST tickets/{id}/release` con `{lease_token,thread_id?,reason?,manual_review?:boolean}` devuelve `{released:true}`. Sin manual_review se puede reintentar el trabajo más adelante. Con manual_review el caso queda pausado. Los mensajes a los leads y el resto de las automatizaciones no se modifican.

`POST tickets/{id}/resume` es una acción explícita de Lucas: `{expected_message_id,reason,request_confirmation?:boolean}`. Requiere el último mensaje actual y ninguna reserva vigente ni entrega ambigua. Sin request_confirmation, vuelve a ready pero marca los mensajes anteriores como atendidos: esperará un nuevo entrante. Con request_confirmation=true (solo después de verificar la misión en producción), deja ready_to_confirm y entrega reason=mission_completed al monitor para pedir que el cliente pruebe, únicamente si la ventana sigue abierta. Conserva el thread_id y registra el motivo de Lucas. No inicia una misión ni manda nada por sí solo.

El contexto incluye `can_query_system`. Un empleado sin ese permiso recibe 403 en diagnostic; no obtiene datos del negocio por haber escrito al soporte. Esta restricción también debe respetarse al consultar producción desde herramientas locales. El diagnóstico técnico interno no autoriza divulgar ventas/caja/clientes a un contacto sin permiso.

## Errores

401: clave ausente/incorrecta. 404: recurso inexistente o adjunto de otro ticket. 409: mensaje viejo, ticket ineligible/atendido, misión pendiente o conflicto idempotente. 422: validación, confirmación insuficiente, ventana vencida. 423: reserva ocupada/vencida/incorrecta. 502: diagnóstico remoto no disponible. El worker relee ante 409 y no publica resultados de un contexto viejo.

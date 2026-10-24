# Puente de atención automática en Codex

Base: `/api/claude/support-codex`. Todas las rutas exigen `X-Claude-Task-Key` y `Accept: application/json`. No usan una sesión de administrador ni devuelven claves de los clientes. El contrato es aditivo y permanece apagado hasta activar settings.

## Configuración y pendientes

- `GET settings`: `{enabled:boolean,activated_at:string|null}`.
- `PUT settings` con `{enabled:boolean}`: devuelve la misma forma. El primer encendido fija `activated_at` al reloj del servidor; no procesa automáticamente mensajes anteriores. El corte no se resetea al reiniciar el worker ni al apagar/encender. Un ticket viejo vuelve a ser elegible si el cliente escribe después del corte.
- `GET pending`: `{enabled,activated_at,server_time,tickets:[{id,client_id,client_name,contact_name,expected_message_id,thread_id,reason,state}]}`. Máximo 50 candidatos. `reason` es `inbound`, `follow_up` o `mission_completed` (tras retomar explícitamente una corrección verificada). No lista reservas vigentes ni casos en revisión, cerrados o con un envío ambiguo.

Cuando está encendido, el número principal recibe soporte aunque antes estuviera desconectado. Los clientes con asistente personal dejan de ser derivados a su otro número cuando escriben al principal. Se preservan el webhook del número del asistente y los procesos de implementación activos. El prefijo `sistema:` va a soporte sin llamar a la IA de API; el worker puede pedir sus datos con diagnostic.

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
  "client":{"id":2,"name":"Comercio","api_url":"https://api-cliente.example"},
  "contact_name":"Ana","client_employee_id":null,
  "state":"ready","thread_id":null,"mission":null,"review_reason":null,
  "reason":"inbound","expected_message_id":123,
  "window":{"open":true,"last_inbound_at":"...","expires_at":"...","origin":"support"},
  "client_context":"Ficha operativa y datos actuales del admin, sin notas internas",
  "messages":[{"id":123,"sender_type":"user","body":"No puedo vender","kind":"text","created_at":"...","attachments":[]}]
}
```

Los últimos 100 mensajes reales se devuelven en orden cronológico; no incluye borradores. Los adjuntos traen `{id,mime,size,download_path}`; `GET tickets/{id}/attachments/{attachment_id}` sirve el binario con la misma autenticación y valida su pertenencia al ticket.

`POST tickets/{id}/diagnostic` con `{lease_token,query:string}` consulta el ERP correspondiente a ese ticket, sin pasar por un modelo de API. Devuelve `{data:<payload remoto>,queried_at}`. Es el lector existente `admin-sync/sistema-query` (stock, ventas, facturas, clientes), no SQL arbitrario. Si no hay acceso/configuración/datos válidos devuelve 502, nunca un resultado vacío presentado como éxito. Las claves permanecen en el servidor. No acepta client_id, owner ni URL del consumidor.

## Respuesta idempotente

`POST tickets/{id}/reply`:

```json
{"lease_token":"...","expected_message_id":123,"operation_id":"uuid-sin-reusar","action":"responder","body":"Texto al cliente","thread_id":"...","mission":null,"confirmation_message_id":null}
```

`action`: `responder`, `solicitar_confirmacion`, `cerrar` o `escalar`. `escalar` requiere `mission` con la misión preparada; no la ejecuta. `cerrar` requiere `confirmation_message_id` igual al último mensaje del cliente y evidencia textual de resolución. Gracias solos no alcanzan: después de pedir confirmación puede servir «sí, gracias». Una respuesta negativa o ambigua no cierra.

Respuesta: `{operation:{id,status,message_id,action,detail},ticket:<contexto>}`. `status` es `sent` o `unknown`. Solo `sent` confirma que WhatsApp aceptó todo el mensaje. No significa que el teléfono lo haya leído. La primera respuesta incluye la presentación como asistente técnico Martín si el modelo no la escribió; el cierre agrega «De nada» si faltó. La reserva se consume al responder; no mandar saludo y respuesta como dos llamadas separadas.

Cada operación se persiste antes del HTTP. La misma clave y mismo contenido devuelven el resultado guardado. Cambiar contenido con igual clave es 409. `GET operations/{operation_id}` recupera ese resultado. Si el proceso cayó o WhatsApp no confirmó entrega, el estado es ambiguo: no reintentar automáticamente con una clave nueva. Se debe conciliar en el admin. Un envío ambiguo nunca cierra un ticket.

`POST operations/{operation_id}/reconcile` es solo para una decisión expresa de Lucas después de revisar: `{outcome:"sent"|"not_sent",reason,confirmed_by:"Lucas González",whatsapp_message_id?,evidence?}`. Sent exige ID WhatsApp y evidencia textual mínima de 15 caracteres; razón mínima 10. Guarda la auditoría completa y deja el caso en revisión manual. No reenvía, no cierra y no aplica por su cuenta la acción original; necesita resume explícito para volver al circuito. `operation.status` es `sent` o `not_sent` después de conciliar. Una operación definitiva no se reconcilia de nuevo y no se concilia mientras haya una reserva activa.

La respuesta se descarta si cambió el último mensaje, Lucas tomó la atención, venció la reserva o apagó el circuito. Fuera de la ventana de 24hs devuelve 422 sin enviar ni inventar una plantilla. El envío y los checks de entrega usan los servicios existentes del admin.

`solicitar_confirmacion` deja `awaiting_confirmation`: un solo seguimiento a las 4 horas, solamente dentro de la ventana. El worker debe pedir confirmación, no dar el caso por cerrado. El silencio no cierra. `escalar` deja `mission_pending`; no puede pedir confirmación de una corrección todavía pendiente. Al responder desde el admin, el caso queda en `manual_review` y el worker deja de competir.

`POST tickets/{id}/release` con `{lease_token,thread_id?,reason?,manual_review?:boolean}` devuelve `{released:true}`. Sin manual_review se puede reintentar el trabajo más adelante. Con manual_review el caso queda pausado. Los mensajes a los leads y el resto de las automatizaciones no se modifican.

`POST tickets/{id}/resume` es una acción explícita de Lucas: `{expected_message_id,reason,request_confirmation?:boolean}`. Requiere el último mensaje actual y ninguna reserva vigente ni entrega ambigua. Sin request_confirmation, vuelve a ready pero marca los mensajes anteriores como atendidos: esperará un nuevo entrante. Con request_confirmation=true (solo después de verificar la misión en producción), deja ready_to_confirm y entrega reason=mission_completed al monitor para pedir que el cliente pruebe, únicamente si la ventana sigue abierta. Conserva el thread_id y registra el motivo de Lucas. No inicia una misión ni manda nada por sí solo.

El contexto incluye `can_query_system`. Un empleado sin ese permiso recibe 403 en diagnostic; no obtiene datos del negocio por haber escrito al soporte. Esta restricción también debe respetarse al consultar producción desde herramientas locales. El diagnóstico técnico interno no autoriza divulgar ventas/caja/clientes a un contacto sin permiso.

## Errores

401: clave ausente/incorrecta. 404: recurso inexistente o adjunto de otro ticket. 409: mensaje viejo, ticket ineligible/atendido, misión pendiente o conflicto idempotente. 422: validación, confirmación insuficiente, ventana vencida. 423: reserva ocupada/vencida/incorrecta. 502: diagnóstico remoto no disponible. El worker relee ante 409 y no publica resultados de un contexto viejo.

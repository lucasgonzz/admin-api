# Fichas públicas por GTIN y consumo de Serper

Contrato aditivo v1. Las rutas `/api/inbound/product-facts/{gtin}`, `/claim` y
`/api/inbound/serper-usage` exigen siempre `X-Admin-Api-Key` de un cliente activo.
El middleware estricto no depende del interruptor legacy. La identidad del negocio
sale de esa clave; `client_uuid` y `client_id` del payload no tienen autoridad.

Las cuatro rutas comparten una cubeta propia de 180 pedidos/minuto por negocio
autenticado, configurable con `SHARED_CATALOG_RATE_LIMIT_PER_MINUTE`. La credencial
se valida antes del limitador y nunca se usa en claro como clave de caché. Estas
rutas quedan fuera de la cubeta general por IP para no compartir el cupo entre
empresas del mismo VPS ni consumir el de los callbacks legacy de soporte.

El GTIN se valida con su dígito verificador y se guarda como cadena de 14 dígitos.
EAN-8, UPC-A, EAN-13 y GTIN-14 equivalentes comparten clave. Los prefijos restringidos
020–029, 040–049 y 200–299 no se investigan.

GET devuelve `status: found|not_found|pending|miss`, `product` y `retry_after`.
POST `/claim` devuelve la ficha si existe o `claimed` con un `lease_token` nuevo.
Un índice único y un lock transaccional por GTIN permiten un solo titular. La reserva
dura 180 segundos; otros pedidos reciben `pending`, sin datos de su titular ni token.
PUT exige el token vigente y el mismo negocio. `found` dura 30 días, `not_found`
6 horas y `failed` libera para reintentar sin caché negativa. Una respuesta vencida
devuelve 409 y nunca sobrescribe el resultado de una reserva posterior.

`product` contiene exclusivamente `name`, `description`, `brand`, `weight_kg`,
`width_cm`, `height_cm`, `length_cm`, `net_content` y `sources:[{url,title}]`.
Las magnitudes físicas son peso bruto en kg y medidas del embalaje de envío en cm,
como los campos logísticos de Article. El contenido neto va en `net_content`; las
medidas del producto, si existen, se describen en `description`. Datos desconocidos
quedan null. Las fuentes públicas HTTP(S) son obligatorias para `found`. Se rechazan
campos privados como precios/categorías/stock. Máximos: nombre 300, descripción
10.000, marca y contenido neto 190 caracteres, hasta 10 fuentes, URL 2048 y título 300.

POST `/api/inbound/serper-usage` recibe hasta 100 `events` por lote con el contrato
del plan. UUID se normaliza a minúscula y es único por negocio. Un reenvío confirma
el lote sin duplicarlo ni modificar un evento recibido previamente. `accepted` es
la cantidad recibida durablemente, incluyendo repetidos ya persistidos. Nombre de
usuario máximo 190, proceso 100 y consulta 500. El emisor conserva el outbox hasta
recibir este acuse y puede reintentar sin riesgo.

`credits:null` indica costo desconocido (por ejemplo timeout), nunca gratis. Los
eventos conservan usuario efectivo, negocio, proceso, endpoint y resultado. La fecha
se persiste UTC; filtros y agrupación diaria usan el día argentino. La API devuelve
el instante ISO UTC para su presentación local en el navegador.

El panel requiere Sanctum: GET `/api/admin/serper/resumen` y
`/api/admin/client/{clientId}/serper` (id o UUID). Filtros `desde`, `hasta` (AAAA-MM-DD,
hasta 366 días), `client_id` (solo global), `auth_user_id` (0 = automático), `process`,
`endpoint:search|images`, `result:ok|failed|unknown_credits`, `page` (50 eventos).
Devuelve totales, cortes por negocio/proceso/usuario/día y detalle paginado.
`has_telemetry:false` distingue un negocio que nunca informó de un consumo cero.
Los créditos se agregan con contadores separados de conocidos/desconocidos; el
importe facturado queda null porque no hay una tarifa documentada/configurada.

El admin es compatible con empresas anteriores que todavía no reportan. No sale a
la red al consultar consumo, y el catálogo no conoce ni transmite categorías de cada
negocio. La empresa clasifica localmente sobre su árbol actual, incluso en cache hit.

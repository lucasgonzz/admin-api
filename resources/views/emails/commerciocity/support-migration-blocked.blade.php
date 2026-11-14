Martín necesita que revises cómo realizar una migración.

Negocio: {!! $notice['name'] !!} (cliente #{{ $notice['client_id'] }})
Contacto: {!! $notice['contact'] !!}
Ticket: #{{ $notice['ticket_id'] }}
Motivo: {!! $notice['reason'] !!}

Pedido recibido:
{!! $notice['source'] !!}

El cliente ya recibió una respuesta sobre el caso. El ticket sigue pendiente de revisión humana; la migración no se informó como terminada.
Revisar el caso: {!! $notice['url'] !!}

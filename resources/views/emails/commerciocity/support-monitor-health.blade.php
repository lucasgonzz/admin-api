Lucas,

@if ($kind === 'test')
Este es un correo de prueba de la vigilancia del monitor. No informa una caída real ni modifica sus alertas.
@elseif ($kind === 'recovery')
El monitor volvió a informar una consulta y un circuito local saludables. Esto no certifica por sí solo que todos los tickets hayan sido respondidos.
@else
El monitor de soporte lleva al menos cinco minutos sin poder confirmar que consulta y atiende normalmente. Los tickets pueden estar quedando sin respuesta.

Motivo técnico: {{ $health['reason'] }}.
@if ($kind === 'reminder')
El incidente continúa. Este es un recordatorio, no una falla nueva.
@endif
Revisá que la PC y Codex estén encendidos y con internet. Si el admin abre con datos móviles pero no con el Wi-Fi habitual, probá otra conexión y revisá la red antes de reiniciar el VPS. No repitas envíos cuyo resultado sea incierto.
@endif

Último pulso recibido: {{ $health['last_seen_at'] ?? 'Sin registro' }} (Argentina).
Último circuito saludable: {{ $health['last_success_at'] ?? 'Todavía no registrado' }} (Argentina).

Este aviso se genera en el servidor y no depende de la conexión de la PC.

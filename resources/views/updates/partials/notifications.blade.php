@if ($aggregatedNotifications->isEmpty())
    <p class="text-muted">Ninguna versión del rango tiene notificaciones configuradas.</p>
@else
<p class="text-muted mb-3">
    <small>Estas notificaciones se enviarán al cliente al sincronizar (incluye versiones intermedias del salto).</small><br>
    <small>Las lecturas se completan cuando un usuario del sistema cliente confirma en el panel y el API del cliente notifica a admin (clave <code>inbound</code> en el cliente = <code>inbound_api_key</code> de este registro de cliente).</small>
</p>
<table class="table">
    <thead>
        <tr>
            <th style="width: 120px;">Versión</th>
            <th style="width: 60px;">Orden</th>
            <th>Título</th>
            <th>Cuerpo</th>
            <th class="text-center">Activa</th>
            <th>Lecturas (usuarios del cliente)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($aggregatedNotifications as $notification)
            @php
                $reads = $readsByNotificationId->get((int) $notification->id) ?? collect();
            @endphp
            <tr>
                <td>
                    <small class="text-muted">{{ $notification->version ? $notification->version->version : '—' }}</small>
                </td>
                <td>{{ $notification->sort_order }}</td>
                <td>{{ $notification->title }}</td>
                <td><small class="text-muted">{{ $notification->body }}</small></td>
                <td class="text-center">
                    @if ($notification->is_active)
                        <span class="badge badge-success">Sí</span>
                    @else
                        <span class="badge badge-secondary">No</span>
                    @endif
                </td>
                <td>
                    @if ($reads->isEmpty())
                        <small class="text-muted">—</small>
                    @else
                        <ul class="list-unstyled mb-0 small">
                            @foreach ($reads as $read)
                                <li>
                                    {{ $read->client_user_name ?: $read->client_user_email ?: ('Usuario #' . $read->client_user_id) }}
                                    @if ($read->read_at)
                                        <span class="text-muted">· {{ $read->read_at->format('d/m/Y H:i') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

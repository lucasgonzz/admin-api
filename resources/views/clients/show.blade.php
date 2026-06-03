@extends('layouts.app')

@section('title', 'Cliente '.$client->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">{{ $client->name }}
            @if($client->is_active)
                <span class="badge badge-success">activo</span>
            @else
                <span class="badge badge-secondary">inactivo</span>
            @endif
        </h3>
        <small class="text-muted">slug: <code>{{ $client->slug }}</code> — uuid: <code>{{ $client->uuid }}</code></small>
    </div>
    <div>
        <a href="{{ route('updates.create', ['client_id' => $client->id]) }}" class="btn btn-success">
            + Nueva actualización
        </a>
        <a href="{{ route('updates.index', ['client_id' => $client->id]) }}" class="btn btn-outline-primary">
            Ver actualizaciones
        </a>
        <a href="{{ route('clients.edit', $client->id) }}" class="btn btn-outline-secondary">Editar</a>
        <form action="{{ route('clients.destroy', $client->id) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar cliente?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Eliminar</button>
        </form>
    </div>
</div>

<div class="card p-3 mb-3">
    <div class="row">
        <div class="col-md-6">
            <small class="text-muted d-block">API URL</small>
            <code>{{ $client->api_url }}</code>
        </div>
        <div class="col-md-3">
            <small class="text-muted d-block">Versión actual</small>
            {{ $client->current_version ? $client->current_version->version : '-' }}
        </div>
        <div class="col-md-3">
            <small class="text-muted d-block">API keys</small>
            <small><strong>admin→cliente:</strong> <code>{{ $client->api_key }}</code></small><br>
            <small><strong>cliente→admin:</strong> <code>{{ $client->inbound_api_key }}</code></small>
        </div>
    </div>
</div>

<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-upgrades">Historial actualizaciones <span class="badge badge-light">{{ $upgrades->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-reads">Lecturas <span class="badge badge-light">{{ $reads->count() }}</span></a></li>
</ul>

<div class="tab-content p-3 bg-white border border-top-0 rounded-bottom">
    <div class="tab-pane fade show active" id="tab-upgrades">
        <p class="text-muted mb-3">
            <small>Para crear o gestionar una actualización, usá la sección
                <a href="{{ route('updates.index', ['client_id' => $client->id]) }}">Actualizaciones</a>.
            </small>
        </p>
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Estado</th>
                    <th>Sincronizado</th>
                    <th>Notas</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($upgrades as $upgrade)
                    <tr>
                        <td><small class="text-muted">{{ $upgrade->id }}</small></td>
                        <td>{{ $upgrade->created_at->format('d/m/Y') }}</td>
                        <td>{{ $upgrade->from_version ? $upgrade->from_version->version : '-' }}</td>
                        <td>{{ $upgrade->to_version ? $upgrade->to_version->version : '-' }}</td>
                        <td>
                            @php
                                $badge = [
                                    'pendiente'             => 'badge-secondary',
                                    'listo_para_actualizar' => 'badge-info',
                                    'actualizandose'        => 'badge-warning',
                                    'terminada'             => 'badge-success',
                                    'fallida'               => 'badge-danger',
                                ][$upgrade->status] ?? 'badge-light';
                            @endphp
                            <span class="badge {{ $badge }} status-badge">{{ $upgrade->status }}</span>
                        </td>
                        <td>{{ $upgrade->synced_at ? $upgrade->synced_at->format('d/m/Y H:i') : '-' }}</td>
                        <td><small>{{ $upgrade->notes }}</small></td>
                        <td>
                            <a href="{{ route('updates.show', $upgrade->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">Sin actualizaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="tab-pane fade" id="tab-reads">
        <table class="table">
            <thead><tr><th>Versión</th><th>Notificación</th><th>Usuario cliente</th><th>Leída</th></tr></thead>
            <tbody>
                @forelse ($reads as $read)
                    <tr>
                        <td>{{ $read->version_notification && $read->version_notification->version ? $read->version_notification->version->version : '-' }}</td>
                        <td>{{ $read->version_notification ? $read->version_notification->title : '-' }}</td>
                        <td>
                            #{{ $read->client_user_id }}
                            @if($read->client_user_name)
                                — {{ $read->client_user_name }}
                            @endif
                            @if($read->client_user_email)
                                <small class="text-muted">({{ $read->client_user_email }})</small>
                            @endif
                        </td>
                        <td>{{ $read->read_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">Sin lecturas reportadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

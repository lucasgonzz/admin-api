@extends('layouts.app')

@section('title', 'Versión '.$version->version)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Versión {{ $version->version }}
            <span class="badge status-badge
                @if($version->status === 'published') badge-success
                @elseif($version->status === 'archived') badge-secondary
                @else badge-warning @endif">
                {{ $version->status }}
            </span>
        </h3>
        <small class="text-muted">{{ $version->title }}</small>
    </div>
    <div>
        <a href="{{ route('versions.edit', $version->id) }}" class="btn btn-outline-secondary">Editar</a>
        <form action="{{ route('versions.destroy', $version->id) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar versión?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Eliminar</button>
        </form>
    </div>
</div>

@if ($version->description)
    <div class="card p-3 mb-3"><small class="text-muted">Descripción</small><div>{{ $version->description }}</div></div>
@endif

<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-notifications">Notificaciones <span class="badge badge-light">{{ $version->notifications->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-seeders">Seeders <span class="badge badge-light">{{ $version->seeders->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-commands">Comandos <span class="badge badge-light">{{ $version->commands->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-manual-tasks">Tareas manuales <span class="badge badge-light">{{ $version->manual_tasks->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-actualizaciones">Actualizaciones</a></li>
</ul>

<div class="tab-content p-3 bg-white border border-top-0 rounded-bottom">
    <div class="tab-pane fade show active" id="tab-notifications">
        @include('versions.partials.notifications', ['version' => $version, 'clients' => $clients])
    </div>
    <div class="tab-pane fade" id="tab-seeders">
        @include('versions.partials.seeders', ['version' => $version, 'clients' => $clients])
    </div>
    <div class="tab-pane fade" id="tab-commands">
        @include('versions.partials.commands', ['version' => $version, 'clients' => $clients])
    </div>
    <div class="tab-pane fade" id="tab-manual-tasks">
        @include('versions.partials.manual_tasks', ['version' => $version, 'clients' => $clients])
    </div>
    <div class="tab-pane fade" id="tab-actualizaciones">
        <p class="text-muted mb-3">
            Para publicar esta versión a un cliente, creá una actualización desde la sección
            <a href="{{ route('updates.create', ['to_version_id' => $version->id]) }}">Actualizaciones</a>.
        </p>
        @php
            $version_upgrades = \App\Models\ClientVersionUpgrade::with('client', 'created_by_admin')
                ->where('to_version_id', $version->id)
                ->orderBy('id', 'desc')
                ->get();
            $status_labels = [
                'pendiente'             => 'Pendiente',
                'listo_para_actualizar' => 'Listo para actualizar',
                'actualizandose'        => 'Actualizándose',
                'terminada'             => 'Terminada',
                'fallida'               => 'Fallida',
            ];
        @endphp
        @if ($version_upgrades->isNotEmpty())
            <table class="table">
                <thead>
                    <tr><th>Cliente</th><th>Estado</th><th>Sincronizado</th><th>Creada</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($version_upgrades as $upgrade)
                        <tr>
                            <td>{{ $upgrade->client ? $upgrade->client->name : '-' }}</td>
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
                                <span class="badge {{ $badge }} status-badge">{{ $status_labels[$upgrade->status] ?? $upgrade->status }}</span>
                            </td>
                            <td>{{ $upgrade->synced_at ? $upgrade->synced_at->format('d/m/Y H:i') : '-' }}</td>
                            <td>{{ $upgrade->created_at->format('d/m/Y') }}</td>
                            <td>
                                <a href="{{ route('updates.show', $upgrade->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-muted">No hay actualizaciones para esta versión aún.</p>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function () {
    var hash = window.location.hash;
    if (hash) {
        var tab = document.querySelector('[href="' + hash + '"]');
        if (tab) { $(tab).tab('show'); }
    }
})();
</script>
@endpush
@endsection

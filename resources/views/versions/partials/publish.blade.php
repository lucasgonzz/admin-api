@php
    $activeClients = \App\Models\Client::where('is_active', true)->orderBy('name')->get();
@endphp

@if ($version->status !== 'published')
    <div class="alert alert-warning">
        La versión debe estar en status <strong>published</strong> para poder publicarse a un cliente.
    </div>
@endif

<form method="POST" action="{{ route('versions.publish', $version->id) }}">
    @csrf
    <div class="form-group">
        <label class="form-label">Cliente destino *</label>
        <select name="client_id" class="form-control" required>
            <option value="">-- seleccionar --</option>
            @foreach ($activeClients as $client)
                <option value="{{ $client->id }}">{{ $client->name }} ({{ $client->api_url }})</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Notas (opcional)</label>
        <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>
    <button type="submit" class="btn btn-success"
            @if($version->status !== 'published') disabled @endif>
        Publicar esta versión al cliente
    </button>
    <small class="d-block text-muted mt-2">
        Se envía al API cliente únicamente la versión y sus {{ $version->notifications->count() }} notificaciones.
        Seeders, comandos y tareas manuales quedan solo en este Admin API.
    </small>
</form>

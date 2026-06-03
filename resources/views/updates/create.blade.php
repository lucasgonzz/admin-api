@extends('layouts.app')

@section('title', 'Nueva Actualización')

@section('content')
<div class="mb-3">
    <a href="{{ route('updates.index') }}" class="text-muted">&larr; Actualizaciones</a>
</div>

<h3 class="mb-4">Nueva Actualización</h3>

<div class="card p-4" style="max-width: 600px;">
    <form method="POST" action="{{ route('updates.store') }}">
        @csrf

        <div class="form-group">
            <label class="form-label">Cliente *</label>
            <select name="client_id" class="form-control" required>
                <option value="">-- seleccionar cliente --</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}"
                        @if($selected_client == $client->id) selected @endif>
                        {{ $client->name }}
                        @if($client->current_version)
                            (versión actual: {{ $client->current_version->version }})
                        @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Versión destino *</label>
            <select name="to_version_id" class="form-control" required>
                <option value="">-- seleccionar versión --</option>
                @foreach ($versions as $v)
                    <option value="{{ $v->id }}">{{ $v->version }} — {{ $v->title }}</option>
                @endforeach
            </select>
            <small class="text-muted">Solo se muestran versiones publicadas. La versión origen se deriva automáticamente desde la versión actual del cliente.</small>
        </div>

        <div class="form-group">
            <label class="form-label">Notas iniciales (opcional)</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>

        <button type="submit" class="btn btn-success">Crear actualización</button>
        <a href="{{ route('updates.index') }}" class="btn btn-link text-muted">Cancelar</a>
    </form>
</div>
@endsection

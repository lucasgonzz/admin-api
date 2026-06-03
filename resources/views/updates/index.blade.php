@extends('layouts.app')

@section('title', 'Actualizaciones')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Actualizaciones</h3>
    <a href="{{ route('updates.create') }}" class="btn btn-success">+ Nueva actualización</a>
</div>

{{-- Filtros --}}
<form method="GET" action="{{ route('updates.index') }}" class="card p-3 mb-3">
    <div class="form-row align-items-end">
        <div class="form-group col-md-3 mb-0">
            <label class="form-label">Cliente</label>
            <select name="client_id" class="form-control form-control-sm">
                <option value="">Todos</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @if(request('client_id') == $client->id) selected @endif>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-md-3 mb-0">
            <label class="form-label">Versión destino</label>
            <select name="to_version_id" class="form-control form-control-sm">
                <option value="">Todas</option>
                @foreach ($versions as $v)
                    <option value="{{ $v->id }}" @if(request('to_version_id') == $v->id) selected @endif>
                        {{ $v->version }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-md-3 mb-0">
            <label class="form-label">Estado</label>
            <select name="status" class="form-control form-control-sm">
                <option value="">Todos</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @if(request('status') === $value) selected @endif>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-md-3 mb-0">
            <button type="submit" class="btn btn-outline-secondary btn-sm btn-block">Filtrar</button>
        </div>
    </div>
</form>

<table class="table bg-white">
    <thead>
        <tr>
            <th>#</th>
            <th>Cliente</th>
            <th>Desde</th>
            <th>Hasta</th>
            <th>Estado</th>
            <th>Progreso</th>
            <th>Fallos</th>
            <th>Creada</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($updates as $upgrade)
            <tr>
                <td><small class="text-muted">{{ $upgrade->id }}</small></td>
                <td>{{ $upgrade->client ? $upgrade->client->name : '-' }}</td>
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
                    <span class="badge {{ $badge }} status-badge">{{ $statuses[$upgrade->status] ?? $upgrade->status }}</span>
                </td>
                <td>
                    @php
                        $total = $upgrade->seeders_total_count + $upgrade->commands_total_count;
                        $done  = $upgrade->seeders_done_count + $upgrade->commands_done_count;
                    @endphp
                    @if ($total > 0)
                        <small>{{ $done }}/{{ $total }} items</small>
                    @else
                        <small class="text-muted">—</small>
                    @endif
                </td>
                <td>
                    @php $fails = $upgrade->seeders_failed_count + $upgrade->commands_failed_count; @endphp
                    @if ($fails > 0)
                        <span class="badge badge-danger">{{ $fails }} fallo(s)</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td><small>{{ $upgrade->created_at->format('d/m/Y') }}</small></td>
                <td class="text-right">
                    <a href="{{ route('updates.show', $upgrade->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center text-muted">No hay actualizaciones.</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{ $updates->links() }}
@endsection

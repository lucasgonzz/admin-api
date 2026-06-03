@extends('layouts.app')

@section('title', 'Leads')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Leads</h3>
    <a href="{{ route('leads.create') }}" class="btn btn-primary">+ Nuevo lead</a>
</div>

{{-- Filtros simples por estado y cliente destino --}}
<form method="GET" action="{{ route('leads.index') }}" class="card p-3 mb-3">
    <div class="form-row align-items-end">
        <div class="form-group col-md-4">
            <label class="form-label mb-1">Estado</label>
            <select name="status" class="form-control">
                <option value="">Todos</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" @if(request('status') === $key) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-md-4">
            <label class="form-label mb-1">Empresa-api destino</label>
            <select name="target_client_id" class="form-control">
                <option value="">Todos</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @if((int) request('target_client_id') === (int) $client->id) selected @endif>{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-md-4">
            <button type="submit" class="btn btn-outline-primary">Filtrar</button>
            <a href="{{ route('leads.index') }}" class="btn btn-link">Limpiar</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Prospecto</th>
                    <th>Empresa</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Empresa-api destino</th>
                    <th>Demo</th>
                    <th>Mail</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leads as $lead)
                    <tr>
                        <td><small class="text-muted">{{ $lead->id }}</small></td>
                        <td>{{ $lead->contact_name ?? '-' }}</td>
                        <td>{{ $lead->company_name ?? '-' }}</td>
                        <td>{{ $lead->email ?? '-' }}</td>
                        <td>
                            <span class="badge badge-info status-badge">{{ $statuses[$lead->status] ?? $lead->status }}</span>
                        </td>
                        <td>
                            {{ $lead->target_client ? $lead->target_client->name : '-' }}
                        </td>
                        <td>
                            @php
                                $demo_badge = [
                                    'pendiente'     => 'badge-secondary',
                                    'ejecutandose'  => 'badge-warning',
                                    'exitoso'       => 'badge-success',
                                    'fallido'       => 'badge-danger',
                                ][$lead->demo_setup_status] ?? 'badge-light';
                            @endphp
                            <span class="badge {{ $demo_badge }} status-badge">{{ $lead->demo_setup_status }}</span>
                        </td>
                        <td>
                            @if($lead->presentation_mail_sent_at)
                                <small class="text-success">{{ $lead->presentation_mail_sent_at->format('d/m/Y H:i') }}</small>
                            @else
                                <small class="text-muted">no enviado</small>
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('leads.show', $lead->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Sin leads todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $leads->links() }}
</div>
@endsection

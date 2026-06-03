@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Clientes</h3>
    <a href="{{ route('clients.create') }}" class="btn btn-primary">+ Nuevo cliente</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th>API URL</th>
                    <th>Versión actual</th>
                    <th class="text-center">Activo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td>{{ $client->name }}</td>
                        <td>{{ $client->slug }}</td>
                        <td><code>{{ $client->api_url }}</code></td>
                        <td>{{ $client->current_version ? $client->current_version->version : '-' }}</td>
                        <td class="text-center">
                            @if($client->is_active)
                                <span class="badge badge-success">sí</span>
                            @else
                                <span class="badge badge-secondary">no</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('clients.show', $client->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Sin clientes todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

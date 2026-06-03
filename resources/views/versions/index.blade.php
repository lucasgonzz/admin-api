@extends('layouts.app')

@section('title', 'Versiones')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Versiones</h3>
    <a href="{{ route('versions.create') }}" class="btn btn-primary">+ Nueva versión</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Versión</th>
                    <th>Título</th>
                    <th>Status</th>
                    <th class="text-center">Notif.</th>
                    <th class="text-center">Seeders</th>
                    <th class="text-center">Cmds</th>
                    <th class="text-center">Tareas</th>
                    <th>Publicado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($versions as $version)
                    <tr>
                        <td><strong>{{ $version->version }}</strong></td>
                        <td>{{ $version->title }}</td>
                        <td>
                            <span class="badge status-badge
                                @if($version->status === 'published') badge-success
                                @elseif($version->status === 'archived') badge-secondary
                                @else badge-warning @endif">
                                {{ $version->status }}
                            </span>
                        </td>
                        <td class="text-center">{{ $version->notifications_count }}</td>
                        <td class="text-center">{{ $version->seeders_count }}</td>
                        <td class="text-center">{{ $version->commands_count }}</td>
                        <td class="text-center">{{ $version->manual_tasks_count }}</td>
                        <td>{{ $version->published_at ? $version->published_at->format('Y-m-d H:i') : '-' }}</td>
                        <td class="text-right">
                            <a href="{{ route('versions.show', $version->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Sin versiones todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

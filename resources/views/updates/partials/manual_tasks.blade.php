@if ($aggregatedManualTasks->isEmpty())
    <p class="text-muted">Ninguna versión del rango tiene tareas manuales.</p>
@else
<table class="table">
    <thead>
        <tr>
            <th style="width: 120px;">Versión</th>
            <th style="width: 60px;">Orden</th>
            <th>Título</th>
            <th>Descripción</th>
            <th class="text-center">Requerida</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($aggregatedManualTasks as $task)
            <tr>
                <td>
                    <small class="text-muted">{{ $task->version ? $task->version->version : '—' }}</small>
                </td>
                <td>{{ $task->execution_order }}</td>
                <td>{{ $task->title }}</td>
                <td><small class="text-muted">{{ $task->description }}</small></td>
                <td class="text-center">
                    @if ($task->is_required)
                        <span class="badge badge-warning">Sí</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

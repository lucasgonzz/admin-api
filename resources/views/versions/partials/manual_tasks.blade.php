<table class="table">
    <thead>
        <tr>
            <th style="width: 60px;">Orden</th>
            <th>Título</th>
            <th>Descripción</th>
            <th class="text-center">Requerida</th>
            <th style="width: 160px;">Alcance</th>
            <th style="width: 180px;">Clientes (edición)</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($version->manual_tasks as $task)
            <tr>
                <form method="POST" action="{{ route('versions.manual-tasks.update', [$version->id, $task->id]) }}">
                    @csrf @method('PUT')
                    <td><input type="number" name="execution_order" value="{{ $task->execution_order }}" class="form-control form-control-sm"></td>
                    <td><input type="text" name="title" value="{{ $task->title }}" class="form-control form-control-sm" required></td>
                    <td><input type="text" name="description" value="{{ $task->description }}" class="form-control form-control-sm"></td>
                    <td class="text-center"><input type="checkbox" name="is_required" value="1" @if($task->is_required) checked @endif></td>
                    <td>@include('versions.partials.client_scope_badge', ['item' => $task])</td>
                    <td>
                        @include('versions.partials.client_multiselect', [
                            'clients' => $clients,
                            'selectedIds' => $task->restrictedClients->pluck('id')->all(),
                        ])
                    </td>
                    <td class="text-right text-nowrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>
                        <form method="POST" action="{{ route('versions.manual-tasks.destroy', [$version->id, $task->id]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
            </tr>
        @endforeach
        <tr>
            <form method="POST" action="{{ route('versions.manual-tasks.store', $version->id) }}">
                @csrf
                <td><input type="number" name="execution_order" value="0" class="form-control form-control-sm"></td>
                <td><input type="text" name="title" class="form-control form-control-sm" placeholder="Título" required></td>
                <td><input type="text" name="description" class="form-control form-control-sm" placeholder="Descripción"></td>
                <td class="text-center"><input type="checkbox" name="is_required" value="1" checked></td>
                <td class="text-muted small">—</td>
                <td>@include('versions.partials.client_multiselect', ['clients' => $clients, 'selectedIds' => []])</td>
                <td class="text-right"><button type="submit" class="btn btn-sm btn-success">+ Agregar</button></td>
            </form>
        </tr>
    </tbody>
</table>
<small class="text-muted">Las tareas manuales no se envían al cliente; se administran aquí. Sin clientes seleccionados, aplica a todos.</small>

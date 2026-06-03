<table class="table">
    <thead>
        <tr>
            <th style="width: 60px;">Orden</th>
            <th>Comando</th>
            <th>Descripción</th>
            <th class="text-center">Requerido</th>
            <th class="text-center" title="No se ejecuta por deployment SSH; queda pendiente para correr a mano">Manual</th>
            <th style="width: 150px;" title="Por base de datos: una vez por DB · Por usuario: una vez por tenant">Scope ejecución</th>
            <th style="width: 160px;">Alcance</th>
            <th style="width: 180px;">Clientes (edición)</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($version->commands as $command)
            <tr>
                <form method="POST" action="{{ route('versions.commands.update', [$version->id, $command->id]) }}">
                    @csrf @method('PUT')
                    <td><input type="number" name="execution_order" value="{{ $command->execution_order }}" class="form-control form-control-sm"></td>
                    <td class="copy-cell">
                        <div class="input-group input-group-sm">
                            <input type="text" name="command" id="command-text-{{ $command->id }}" value="{{ $command->command }}" class="form-control form-control-sm" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary btn-sm" title="Copiar al portapapeles" onclick="adminApiCopyInputById('command-text-{{ $command->id }}', this)">Copiar</button>
                            </div>
                        </div>
                    </td>
                    <td><input type="text" name="description" value="{{ $command->description }}" class="form-control form-control-sm"></td>
                    <td class="text-center"><input type="checkbox" name="is_required" value="1" @if($command->is_required) checked @endif></td>
                    <td class="text-center"><input type="checkbox" name="run_manually" value="1" @if($command->run_manually) checked @endif></td>
                    <td>
                        @include('versions.partials.run_scope_select', [
                            'name' => 'run_scope',
                            'value' => $command->run_scope,
                            'default' => 'per_user',
                        ])
                    </td>
                    <td>@include('versions.partials.client_scope_badge', ['item' => $command])</td>
                    <td>
                        @include('versions.partials.client_multiselect', [
                            'clients' => $clients,
                            'selectedIds' => $command->restrictedClients->pluck('id')->all(),
                        ])
                    </td>
                    <td class="text-right text-nowrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>
                        <form method="POST" action="{{ route('versions.commands.destroy', [$version->id, $command->id]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
            </tr>
        @endforeach
        <tr>
            <form method="POST" action="{{ route('versions.commands.store', $version->id) }}">
                @csrf
                <td class="text-muted small" title="Se asigna al final al guardar">Al final</td>
                <td><input type="text" name="command" class="form-control form-control-sm" placeholder="php artisan ..." required></td>
                <td><input type="text" name="description" class="form-control form-control-sm" placeholder="Descripción"></td>
                <td class="text-center"><input type="checkbox" name="is_required" value="1" checked></td>
                <td class="text-center"><input type="checkbox" name="run_manually" value="1"></td>
                <td>
                    @include('versions.partials.run_scope_select', [
                        'name' => 'run_scope',
                        'value' => null,
                        'default' => 'per_user',
                    ])
                </td>
                <td class="text-muted small">—</td>
                <td>@include('versions.partials.client_multiselect', ['clients' => $clients, 'selectedIds' => []])</td>
                <td class="text-right"><button type="submit" class="btn btn-sm btn-success">+ Agregar</button></td>
            </form>
        </tr>
    </tbody>
</table>
<small class="text-muted">Los comandos no se envían al cliente; se administran aquí. Sin clientes seleccionados, aplica a todos.</small>

<table class="table">
    <thead>
        <tr>
            <th style="width: 60px;">Orden</th>
            <th>Seeder Class</th>
            <th>Descripción</th>
            <th class="text-center">Requerido</th>
            <th style="width: 150px;" title="Por base de datos: una vez por DB · Por usuario: una vez por tenant">Scope ejecución</th>
            <th style="width: 160px;">Alcance</th>
            <th style="width: 180px;">Clientes (edición)</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($version->seeders as $seeder)
            <tr>
                <form method="POST" action="{{ route('versions.seeders.update', [$version->id, $seeder->id]) }}">
                    @csrf @method('PUT')
                    <td><input type="number" name="execution_order" value="{{ $seeder->execution_order }}" class="form-control form-control-sm"></td>
                    <td class="copy-cell">
                        <div class="input-group input-group-sm">
                            <input type="text" name="seeder_class" id="seeder-class-{{ $seeder->id }}" value="{{ $seeder->seeder_class }}" class="form-control form-control-sm" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary btn-sm" title="Copiar al portapapeles" onclick="adminApiCopyInputById('seeder-class-{{ $seeder->id }}', this)">Copiar</button>
                            </div>
                        </div>
                    </td>
                    <td><input type="text" name="description" value="{{ $seeder->description }}" class="form-control form-control-sm"></td>
                    <td class="text-center"><input type="checkbox" name="is_required" value="1" @if($seeder->is_required) checked @endif></td>
                    <td>
                        @include('versions.partials.run_scope_select', [
                            'name' => 'run_scope',
                            'value' => $seeder->run_scope,
                            'default' => 'per_database',
                        ])
                    </td>
                    <td>@include('versions.partials.client_scope_badge', ['item' => $seeder])</td>
                    <td>
                        @include('versions.partials.client_multiselect', [
                            'clients' => $clients,
                            'selectedIds' => $seeder->restrictedClients->pluck('id')->all(),
                        ])
                    </td>
                    <td class="text-right text-nowrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>
                        <form method="POST" action="{{ route('versions.seeders.destroy', [$version->id, $seeder->id]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
            </tr>
        @endforeach
        <tr>
            <form method="POST" action="{{ route('versions.seeders.store', $version->id) }}">
                @csrf
                <td class="text-muted small" title="Se asigna al final al guardar">Al final</td>
                <td><input type="text" name="seeder_class" class="form-control form-control-sm" placeholder="Database\\Seeders\\MySeeder" required></td>
                <td><input type="text" name="description" class="form-control form-control-sm" placeholder="Descripción"></td>
                <td class="text-center"><input type="checkbox" name="is_required" value="1" checked></td>
                <td>
                    @include('versions.partials.run_scope_select', [
                        'name' => 'run_scope',
                        'value' => null,
                        'default' => 'per_database',
                    ])
                </td>
                <td class="text-muted small">—</td>
                <td>@include('versions.partials.client_multiselect', ['clients' => $clients, 'selectedIds' => []])</td>
                <td class="text-right"><button type="submit" class="btn btn-sm btn-success">+ Agregar</button></td>
            </form>
        </tr>
    </tbody>
</table>
<small class="text-muted">Los seeders no se envían al cliente; son administrados localmente en este Admin API. Sin clientes seleccionados, aplica a todos.</small>

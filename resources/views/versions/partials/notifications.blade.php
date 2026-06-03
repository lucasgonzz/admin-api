<table class="table">
    <thead>
        <tr>
            <th style="width: 60px;">Orden</th>
            <th>Título</th>
            <th>Cuerpo</th>
            <th class="text-center">Activa</th>
            <th style="width: 160px;">Alcance</th>
            <th style="width: 180px;">Clientes (edición)</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($version->notifications as $notification)
            <tr>
                <form method="POST" action="{{ route('versions.notifications.update', [$version->id, $notification->id]) }}">
                    @csrf @method('PUT')
                    <td><input type="number" name="sort_order" value="{{ $notification->sort_order }}" class="form-control form-control-sm"></td>
                    <td><input type="text" name="title" value="{{ $notification->title }}" class="form-control form-control-sm" required></td>
                    <td><textarea name="body" class="form-control form-control-sm" rows="1" required>{{ $notification->body }}</textarea></td>
                    <td class="text-center"><input type="checkbox" name="is_active" value="1" @if($notification->is_active) checked @endif></td>
                    <td>@include('versions.partials.client_scope_badge', ['item' => $notification])</td>
                    <td>
                        @include('versions.partials.client_multiselect', [
                            'clients' => $clients,
                            'selectedIds' => $notification->restrictedClients->pluck('id')->all(),
                        ])
                    </td>
                    <td class="text-right text-nowrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>
                        <form method="POST" action="{{ route('versions.notifications.destroy', [$version->id, $notification->id]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
            </tr>
        @endforeach
        <tr>
            <form method="POST" action="{{ route('versions.notifications.store', $version->id) }}">
                @csrf
                <td><input type="number" name="sort_order" value="0" class="form-control form-control-sm"></td>
                <td><input type="text" name="title" class="form-control form-control-sm" placeholder="Título" required></td>
                <td><textarea name="body" class="form-control form-control-sm" rows="1" placeholder="Cuerpo" required></textarea></td>
                <td class="text-center"><input type="checkbox" name="is_active" value="1" checked></td>
                <td class="text-muted small">—</td>
                <td>@include('versions.partials.client_multiselect', ['clients' => $clients, 'selectedIds' => []])</td>
                <td class="text-right"><button type="submit" class="btn btn-sm btn-success">+ Agregar</button></td>
            </form>
        </tr>
    </tbody>
</table>
<small class="text-muted">Sin clientes seleccionados, la notificación aplica a todos al publicar. Con clientes, solo a los indicados (incl. sincronización al API del cliente).</small>

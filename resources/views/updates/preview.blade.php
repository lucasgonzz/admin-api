@extends('layouts.app')

@section('title', 'Confirmar actualización')

@section('content')
<div class="mb-3">
    <a href="{{ route('updates.create') }}" class="text-muted">&larr; Nueva actualización</a>
</div>

<h3 class="mb-1">Confirmar actualización</h3>
<p class="text-muted mb-4">
    {{ $client->name }}
    &mdash;
    desde {{ $from ? $from->version : '(sin versión actual)' }}
    &rarr;
    hasta {{ $to->version }}
</p>

{{-- Paso 2 de 2: acá recién se crea el ClientVersionUpgrade. Todo lo del paso 1 viaja
     como hidden para no perder lo que el admin ya cargó. --}}
<form method="POST" action="{{ route('updates.store') }}">
    @csrf
    <input type="hidden" name="client_id" value="{{ $client->id }}">
    <input type="hidden" name="to_version_id" value="{{ $to->id }}">
    <input type="hidden" name="notes" value="{{ $notes }}">
    <input type="hidden" name="scheduled_date" value="{{ $scheduled_date }}">
    @if($target_client_api_id)
        <input type="hidden" name="target_client_api_id" value="{{ $target_client_api_id }}">
    @endif

    @if($candidates->isEmpty())
        <div class="alert alert-info">
            El cliente ya está en la versión destino o no hay versiones publicadas en el rango.
        </div>
    @else
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Versiones a incluir</label>
                <div>
                    <button type="button" id="btn-marcar-todas" class="btn btn-sm btn-outline-secondary">Marcar todas</button>
                    <button type="button" id="btn-solo-troncal" class="btn btn-sm btn-outline-secondary">Solo troncal</button>
                </div>
            </div>
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Versión</th>
                        <th>Título</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($candidates as $c)
                        @php $es_destino = ((int) $c->id === (int) $to->id); @endphp
                        <tr>
                            <td>
                                <input type="checkbox" name="version_ids[]" value="{{ $c->id }}"
                                       @if($es_destino) checked disabled @elseif(!$c->is_hotfix) checked @endif>
                                @if($es_destino)
                                    {{-- un checkbox disabled no viaja en el POST, así que lo arrastramos aparte --}}
                                    <input type="hidden" name="version_ids[]" value="{{ $to->id }}">
                                @endif
                            </td>
                            <td>
                                <code>{{ $c->version }}</code>
                                @if($c->is_hotfix)
                                    <span class="badge badge-warning">Hotfix</span>
                                @endif
                            </td>
                            <td>{{ $c->title }}</td>
                            <td class="text-muted">{{ $c->description }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <button type="submit" class="btn btn-success" @if($candidates->isEmpty()) disabled @endif>Crear actualización</button>
    <a href="{{ route('updates.create') }}" class="btn btn-link text-muted">&larr; Volver</a>
</form>
@endsection

@push('scripts')
<script>
(function () {
    // Solo tocamos los checkboxes habilitados: el de la versión destino queda
    // siempre tildado (va disabled + hidden, ver arriba).
    function checkboxesEditables() {
        return document.querySelectorAll('input[name="version_ids[]"]:not([disabled])');
    }

    var btnTodas = document.getElementById('btn-marcar-todas');
    if (btnTodas) {
        btnTodas.addEventListener('click', function () {
            checkboxesEditables().forEach(function (chk) { chk.checked = true; });
        });
    }

    var btnTroncal = document.getElementById('btn-solo-troncal');
    if (btnTroncal) {
        btnTroncal.addEventListener('click', function () {
            checkboxesEditables().forEach(function (chk) {
                // "type=checkbox" no tiene atributo con el hotfix, así que lo leemos de la fila.
                var esHotfix = chk.closest('tr').querySelector('.badge-warning') !== null;
                chk.checked = !esHotfix;
            });
        });
    }
})();
</script>
@endpush

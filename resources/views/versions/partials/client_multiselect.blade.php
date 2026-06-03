{{-- Multiselect de clientes: sin selección = aplica a todos. --}}
@php
    $name = $name ?? 'client_ids';
    $selectedIds = $selectedIds ?? [];
@endphp
<select name="{{ $name }}[]" class="form-control form-control-sm" multiple size="2" style="min-width: 150px;" title="Vacío = todos los clientes">
    @foreach ($clients as $c)
        <option value="{{ $c->id }}"
            @if (in_array((int) $c->id, array_map('intval', $selectedIds), true)) selected @endif>
            {{ $c->name }}
        </option>
    @endforeach
</select>
<small class="text-muted d-block">Vacío = todos</small>

@if ($item->restrictedClients->isEmpty())
    <span class="text-muted">Todos</span>
@else
    <small title="Solo para estos clientes">{{ $item->restrictedClients->pluck('name')->implode(', ') }}</small>
@endif

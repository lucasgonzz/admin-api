@php
$steps = [
    'sistema_actualizado_at'  => ['label' => 'Sistema actualizado', 'group' => 'pre', 'hint' => 'Compilar y subir SPA + API del cliente'],
    'migraciones_corridas_at' => ['label' => 'Migraciones corridas', 'group' => 'pre', 'hint' => 'Ejecutar las migraciones pendientes'],
    'crons_supervisor_at'     => ['label' => 'Crons / Supervisor configurados', 'group' => 'post', 'hint' => 'Realizar después del cierre del negocio'],
    'seeders_ejecutados_at'   => ['label' => 'Seeders ejecutados', 'group' => 'post', 'hint' => 'Ver tab Seeders para detalle'],
    'comandos_ejecutados_at'  => ['label' => 'Comandos ejecutados', 'group' => 'post', 'hint' => 'Ver tab Comandos para detalle'],
    'sistema_configurado_at'  => ['label' => 'Sistema configurado', 'group' => 'post', 'hint' => 'Cambio de URL / versión por defecto. Disparar sincronización al cliente desde el botón superior.'],
];
@endphp

<div class="row">
    <div class="col-md-6">
        <h6 class="font-weight-bold text-muted mb-3">Tareas previas</h6>
        @foreach ($steps as $field => $step)
            @if ($step['group'] === 'pre')
                @php $done = !is_null($upgrade->$field); @endphp
                <div class="d-flex align-items-start mb-3 p-2 rounded {{ $done ? 'bg-light' : '' }}">
                    <div class="mr-3 pt-1">
                        @if ($done)
                            <span class="text-success" style="font-size:1.3rem;">&#10003;</span>
                        @else
                            <span class="text-muted" style="font-size:1.3rem;">&#9675;</span>
                        @endif
                    </div>
                    <div class="flex-grow-1">
                        <div class="font-weight-semibold">{{ $step['label'] }}</div>
                        <small class="text-muted">{{ $step['hint'] }}</small>
                        @if ($done)
                            <div><small class="text-success">{{ $upgrade->$field->format('d/m/Y H:i') }}</small></div>
                        @endif
                    </div>
                    <div class="ml-2">
                        <form method="POST" action="{{ route('updates.mark_step', $upgrade->id) }}">
                            @csrf
                            <input type="hidden" name="step" value="{{ $field }}">
                            @if ($done)
                                <input type="hidden" name="unmark" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Desmarcar</button>
                            @else
                                <button type="submit" class="btn btn-sm btn-outline-success">Marcar</button>
                            @endif
                        </form>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="col-md-6">
        <h6 class="font-weight-bold text-muted mb-3">
            Tareas post-cierre del negocio
            <small class="text-warning font-weight-normal">&#9888; Realizar cuando el negocio esté cerrado</small>
        </h6>
        @foreach ($steps as $field => $step)
            @if ($step['group'] === 'post')
                @php $done = !is_null($upgrade->$field); @endphp
                <div class="d-flex align-items-start mb-3 p-2 rounded {{ $done ? 'bg-light' : '' }}">
                    <div class="mr-3 pt-1">
                        @if ($done)
                            <span class="text-success" style="font-size:1.3rem;">&#10003;</span>
                        @else
                            <span class="text-muted" style="font-size:1.3rem;">&#9675;</span>
                        @endif
                    </div>
                    <div class="flex-grow-1">
                        <div class="font-weight-semibold">{{ $step['label'] }}</div>
                        <small class="text-muted">{{ $step['hint'] }}</small>
                        @if ($done)
                            <div><small class="text-success">{{ $upgrade->$field->format('d/m/Y H:i') }}</small></div>
                        @endif
                    </div>
                    <div class="ml-2">
                        <form method="POST" action="{{ route('updates.mark_step', $upgrade->id) }}">
                            @csrf
                            <input type="hidden" name="step" value="{{ $field }}">
                            @if ($done)
                                <input type="hidden" name="unmark" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Desmarcar</button>
                            @else
                                <button type="submit" class="btn btn-sm btn-outline-success">Marcar</button>
                            @endif
                        </form>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>

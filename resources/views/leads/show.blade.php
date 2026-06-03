@extends('layouts.app')

@section('title', 'Lead ' . ($lead->contact_name ?? $lead->company_name ?? $lead->id))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">
            {{ $lead->contact_name ?? $lead->company_name ?? 'Lead #' . $lead->id }}
            <span class="badge badge-info status-badge">{{ $statuses[$lead->status] ?? $lead->status }}</span>
        </h3>
        <small class="text-muted">uuid: <code>{{ $lead->uuid }}</code></small>
    </div>
    <div>
        <a href="{{ route('leads.edit', $lead->id) }}" class="btn btn-outline-secondary">Editar</a>
        <form action="{{ route('leads.destroy', $lead->id) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar lead?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Eliminar</button>
        </form>
    </div>
</div>

{{-- Acciones del flujo de ventas agrupadas en cards --}}

{{-- Card: mail + demo --}}
<div class="card p-3 mb-3">
    <h6 class="mb-3 text-muted text-uppercase" style="font-size:0.75rem;letter-spacing:0.05em;">Prospección</h6>
    <div class="row align-items-start">
        <div class="col-md-4">
            <p class="mb-1"><strong>Mail de presentación</strong></p>
            <form action="{{ route('leads.send_presentation_mail', $lead->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary"
                        @if(empty($lead->email)) disabled title="El lead no tiene email" @endif>
                    Enviar mail de presentación
                </button>
            </form>
            @if($lead->presentation_mail_sent_at)
                <small class="text-muted d-block mt-1">Último envío: {{ $lead->presentation_mail_sent_at->format('d/m/Y H:i') }}</small>
            @endif
            @if($lead->presentation_mail_last_error)
                <small class="text-danger d-block mt-1">Error: {{ $lead->presentation_mail_last_error }}</small>
            @endif
        </div>
        <div class="col-md-4">
            <p class="mb-1"><strong>Mail de seguimiento</strong></p>
            <form action="{{ route('leads.send_followup_mail', $lead->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary"
                        @if(empty($lead->email)) disabled title="El lead no tiene email" @endif>
                    Enviar mail de seguimiento
                </button>
            </form>
            @if($lead->followup_mail_sent_at)
                <small class="text-muted d-block mt-1">Último envío: {{ $lead->followup_mail_sent_at->format('d/m/Y H:i') }}</small>
            @endif
            @if($lead->followup_mail_last_error)
                <small class="text-danger d-block mt-1">Error: {{ $lead->followup_mail_last_error }}</small>
            @endif
        </div>
        <div class="col-md-4">
            <p class="mb-1"><strong>Sistema demo</strong>
                @php
                    $demo_badge = ['pendiente'=>'badge-secondary','ejecutandose'=>'badge-warning','exitoso'=>'badge-success','fallido'=>'badge-danger'][$lead->demo_setup_status] ?? 'badge-light';
                @endphp
                <span class="badge {{ $demo_badge }} ml-1">{{ $lead->demo_setup_status }}</span>
            </p>
            <form action="{{ route('leads.run_demo_setup', $lead->id) }}" method="POST" class="d-inline"
                  onsubmit="return confirm('Esto ejecuta migrate:fresh en el empresa-api demo. ¿Continuar?')">
                @csrf
                <button type="submit" class="btn btn-sm btn-success"
                        @if(!$lead->target_client_id) disabled title="Primero elegí un empresa-api destino" @endif>
                    Disparar setup demo
                </button>
            </form>
            @if($lead->demo_setup_last_run_at)
                <small class="text-muted d-block mt-1">Última corrida: {{ $lead->demo_setup_last_run_at->format('d/m/Y H:i') }}</small>
            @endif
            @if($lead->demo_setup_last_error)
                <small class="text-danger d-block mt-1">Error: {{ $lead->demo_setup_last_error }}</small>
            @endif
        </div>
    </div>
</div>

{{-- Card mínima: el flujo principal de producción está en admin-spa --}}
<div class="card p-3 mb-3 @if($lead->status === 'cerrado_ganado') border-success @endif">
    <h6 class="mb-3 text-muted text-uppercase" style="font-size:0.75rem;letter-spacing:0.05em;">Sistema real (producción)</h6>

    @if($lead->status !== 'cerrado_ganado')
        <p class="text-muted mb-2 small">Promoción y user setup: usá el panel <strong>admin-spa</strong>. Esta vista ofrece solo el enlace clásico si lo necesitás.</p>
        <a href="{{ route('leads.promote', $lead->id) }}" class="btn btn-sm btn-warning">
            Promover a cliente
        </a>
    @else
        <p class="text-muted small mb-3">Operá producción desde <strong>admin-spa</strong> (API URL en el lead, «Correr user setup»). Debajo hay acciones de respaldo sin validación de UI.</p>
        @if($lead->promoted_client_id && $lead->promoted_client)
            <p class="mb-2">
                <a href="{{ route('clients.show', $lead->promoted_client->id) }}">Client #{{ $lead->promoted_client->id }} — {{ $lead->promoted_client->name }}</a>
            </p>
        @endif
        <p class="mb-1">
            <strong>User-setup</strong>
            @php
                $user_badge = ['pendiente'=>'badge-secondary','ejecutandose'=>'badge-warning','exitoso'=>'badge-success','fallido'=>'badge-danger'][$lead->user_setup_status] ?? 'badge-light';
            @endphp
            <span class="badge {{ $user_badge }} ml-1">{{ $lead->user_setup_status }}</span>
        </p>
        <form action="{{ route('leads.run_user_setup', $lead->id) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Esto ejecuta migrate:fresh + seeders en el sistema REAL del cliente. Los datos existentes se perderán. ¿Continuar?')">
            @csrf
            <button type="submit" class="btn btn-sm btn-danger">Crear sistema real (respaldo)</button>
        </form>
        @if($lead->user_setup_last_run_at)
            <small class="text-muted d-block mt-1">Última corrida: {{ $lead->user_setup_last_run_at->format('d/m/Y H:i') }}</small>
        @endif
        @if($lead->user_setup_last_error)
            <small class="text-danger d-block mt-1">Error: {{ $lead->user_setup_last_error }}</small>
        @endif
    @endif
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card p-3 mb-3">
            <h5 class="mb-3">Prospecto</h5>
            <dl class="row mb-0">
                <dt class="col-sm-5">Contacto</dt><dd class="col-sm-7">{{ $lead->contact_name ?? '-' }}</dd>
                <dt class="col-sm-5">Empresa</dt><dd class="col-sm-7">{{ $lead->company_name ?? '-' }}</dd>
                <dt class="col-sm-5">Email</dt><dd class="col-sm-7">{{ $lead->email ?? '-' }}</dd>
                <dt class="col-sm-5">Teléfono</dt><dd class="col-sm-7">{{ $lead->phone ?? '-' }}</dd>
                <dt class="col-sm-5">Documento</dt><dd class="col-sm-7">{{ $lead->doc_number ?? '-' }}</dd>
                <dt class="col-sm-5">Reunión agendada</dt>
                <dd class="col-sm-7">{{ $lead->meeting_scheduled_at ? $lead->meeting_scheduled_at->format('d/m/Y H:i') : '-' }}</dd>
                <dt class="col-sm-5">Notas</dt><dd class="col-sm-7">{{ $lead->notes ?? '-' }}</dd>
            </dl>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-3 mb-3">
            <h5 class="mb-3">Configuración técnica</h5>
            <dl class="row mb-0">
                <dt class="col-sm-6">Empresa-api destino</dt>
                <dd class="col-sm-6">
                    @if($lead->target_client)
                        {{ $lead->target_client->name }}<br>
                        <small class="text-muted"><code>{{ $lead->target_client->api_url }}</code></small>
                    @else
                        <span class="text-muted">sin seleccionar</span>
                    @endif
                </dd>
                <dt class="col-sm-6">User name</dt><dd class="col-sm-6">{{ $lead->user_name ?? '-' }}</dd>
                <dt class="col-sm-6">User ID</dt><dd class="col-sm-6">{{ $lead->user_id ?? '-' }}</dd>
                <dt class="col-sm-6">Total a pagar</dt><dd class="col-sm-6">{{ $lead->total_a_pagar ?? '-' }}</dd>

                @php
                    // Iteramos los flags para mostrarlos compactos como "Sí/No"
                    $display_flags = [
                        'use_deposits'                 => 'Usa depósitos',
                        'use_price_lists'              => 'Usa listas de precios',
                        'iva_included'                 => 'IVA incluido en precios',
                        'ventas_con_fecha_de_entrega'  => 'Ventas con fecha de entrega',
                        'cajas'                        => 'Usa cajas',
                        'usar_codigos_de_barra'        => 'Usa códigos de barra',
                        'codigos_de_barra_por_defecto' => 'Cod. barra por defecto',
                        'consultora_de_precios'        => 'Consultoras de precio',
                        'imagenes'                     => 'Imágenes en artículos',
                        'produccion'                   => 'Módulo de producción',
                        'ask_amount_in_vender'         => 'Preguntar cantidad al vender',
                        'redondear_centenas_en_vender' => 'Redondear centenas',
                        'omitir_cuentas_corrientes'    => 'Omitir cuentas corrientes',
                    ];
                @endphp
                @foreach($display_flags as $flag => $label)
                    <dt class="col-sm-6">{{ $label }}</dt>
                    <dd class="col-sm-6">{{ $lead->{$flag} ? 'Sí' : 'No' }}</dd>
                @endforeach

                @if($lead->use_deposits)
                    <dt class="col-sm-6">Direcciones</dt>
                    <dd class="col-sm-6">
                        @foreach(['address_1','address_2','address_3'] as $addr)
                            @if($lead->{$addr})<div>{{ $lead->{$addr} }}</div>@endif
                        @endforeach
                    </dd>
                @endif
                @if($lead->use_price_lists)
                    <dt class="col-sm-6">Listas de precios</dt>
                    <dd class="col-sm-6">
                        @foreach(['price_type_1','price_type_2','price_type_3'] as $pt)
                            @if($lead->{$pt})<div>{{ $lead->{$pt} }}</div>@endif
                        @endforeach
                    </dd>
                @endif
            </dl>
        </div>
    </div>
</div>
@endsection

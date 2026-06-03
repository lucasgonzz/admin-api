{{-- Form unificado para create/edit de Lead. Espera $lead (null en create) + $clients, $statuses, $business_types --}}

<h5 class="mt-2 mb-3">Sistema destino</h5>
<div class="form-group">
    <label class="form-label">Empresa-api donde se hará la demo</label>
    <select name="target_client_id" class="form-control">
        <option value="">-- Seleccionar cliente --</option>
        @foreach($clients as $client)
            <option value="{{ $client->id }}" @if(old('target_client_id', $lead->target_client_id ?? null) == $client->id) selected @endif>
                {{ $client->name }} ({{ $client->api_url }})
            </option>
        @endforeach
    </select>
    <small class="text-muted">Al disparar la demo desde la vista del lead, admin-api le pegará al endpoint admin-sync/demo-setup del empresa-api elegido.</small>
</div>

<hr>
<h5 class="mt-2 mb-3">Prospecto</h5>
<div class="form-row">
    <div class="form-group col-md-6">
        <label class="form-label">Nombre de contacto</label>
        <input type="text" name="contact_name" class="form-control" value="{{ old('contact_name', $lead->contact_name ?? '') }}" maxlength="150">
    </div>
    <div class="form-group col-md-6">
        <label class="form-label">Nombre empresa</label>
        <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $lead->company_name ?? '') }}" maxlength="150">
    </div>
</div>
<div class="form-row">
    <div class="form-group col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="{{ old('email', $lead->email ?? '') }}" maxlength="150">
    </div>
    <div class="form-group col-md-4">
        <label class="form-label">Teléfono</label>
        <input type="text" name="phone" class="form-control" value="{{ old('phone', $lead->phone ?? '') }}" maxlength="50">
    </div>
    <div class="form-group col-md-4">
        <label class="form-label">Documento</label>
        <input type="text" name="doc_number" class="form-control" value="{{ old('doc_number', $lead->doc_number ?? '') }}" maxlength="50">
    </div>
</div>
<div class="form-row">
    <div class="form-group col-md-6">
        <label class="form-label">Estado</label>
        <select name="status" class="form-control">
            @foreach($statuses as $key => $label)
                <option value="{{ $key }}" @if(old('status', $lead->status ?? 'nuevo') === $key) selected @endif>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group col-md-6">
        <label class="form-label">Reunión agendada</label>
        <input type="datetime-local" name="meeting_scheduled_at" class="form-control"
               value="{{ old('meeting_scheduled_at', isset($lead) && $lead->meeting_scheduled_at ? $lead->meeting_scheduled_at->format('Y-m-d\TH:i') : '') }}">
    </div>
</div>
<div class="form-group">
    <label class="form-label">Notas</label>
    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $lead->notes ?? '') }}</textarea>
</div>

<hr>
<h5 class="mt-2 mb-3">Configuración técnica (demo)</h5>
<div class="form-row">
    <div class="form-group col-md-4">
        <label class="form-label">Nombre usuario del sistema</label>
        <input type="text" name="user_name" class="form-control" value="{{ old('user_name', $lead->user_name ?? '') }}" maxlength="150">
    </div>
    <div class="form-group col-md-4">
        <label class="form-label">User ID</label>
        @php
            // En create sugerimos el siguiente bloque; en edit priorizamos el valor ya guardado
            $current_user_id = old('user_id', $lead->user_id ?? ($suggested_user_id ?? ''));
            $suggested_user_id_value = isset($suggested_user_id) ? (string) $suggested_user_id : '';
            $is_manual_override = $suggested_user_id_value !== '' && (string) $current_user_id !== $suggested_user_id_value;
        @endphp
        <input type="text" name="user_id" class="form-control" value="{{ $current_user_id }}" maxlength="80">
        <small class="text-muted d-block mt-1">
            Sugerido automáticamente: <strong>{{ $suggested_user_id ?? '-' }}</strong>.
            Debe ser múltiplo de 100 y cada bloque representa hasta 100 usuarios del sistema.
        </small>
        @if($is_manual_override)
            <small class="text-warning d-block mt-1">
                Advertencia: estás usando un user_id distinto al sugerido. Se validará que el bloque no esté reservado.
            </small>
        @endif
    </div>
    <div class="form-group col-md-4">
        <label class="form-label">Valor del mes</label>
        <input type="text" name="total_a_pagar" class="form-control" value="{{ old('total_a_pagar', $lead->total_a_pagar ?? '') }}" maxlength="40">
    </div>
</div>

<div class="form-group">
    <label class="form-label">Tipo de negocio</label>
    <select name="business_type" class="form-control">
        <option value="">-- Seleccionar --</option>
        @foreach($business_types as $key => $label)
            <option value="{{ $key }}" @if(old('business_type', $lead->business_type ?? '') === $key) selected @endif>{{ $label }}</option>
        @endforeach
    </select>
</div>

<div class="card p-3 mb-3">
    <p class="mb-1"><strong>Sucursales</strong></p>
    <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="use_deposits" name="use_deposits" value="1"
               @if(old('use_deposits', $lead->use_deposits ?? false)) checked @endif>
        <label class="form-check-label" for="use_deposits">Usa depósitos</label>
    </div>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label class="form-label">Dirección 1</label>
            <input type="text" name="address_1" class="form-control" value="{{ old('address_1', $lead->address_1 ?? '') }}">
        </div>
        <div class="form-group col-md-4">
            <label class="form-label">Dirección 2</label>
            <input type="text" name="address_2" class="form-control" value="{{ old('address_2', $lead->address_2 ?? '') }}">
        </div>
        <div class="form-group col-md-4">
            <label class="form-label">Dirección 3</label>
            <input type="text" name="address_3" class="form-control" value="{{ old('address_3', $lead->address_3 ?? '') }}">
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <p class="mb-1"><strong>Precios</strong></p>
    <div class="form-check mb-1">
        <input type="checkbox" class="form-check-input" id="use_price_lists" name="use_price_lists" value="1"
               @if(old('use_price_lists', $lead->use_price_lists ?? false)) checked @endif>
        <label class="form-check-label" for="use_price_lists">Usa listas de precios</label>
    </div>
    <!-- <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="iva_included" name="iva_included" value="1"
               @if(old('iva_included', $lead->iva_included ?? false)) checked @endif>
        <label class="form-check-label" for="iva_included">IVA ya incluido en los precios (monotributistas)</label>
    </div> -->
    <div class="form-row">
        <div class="form-group col-md-4">
            <label class="form-label">Lista 1</label>
            <input type="text" name="price_type_1" class="form-control" value="{{ old('price_type_1', $lead->price_type_1 ?? '') }}">
        </div>
        <div class="form-group col-md-4">
            <label class="form-label">Lista 2</label>
            <input type="text" name="price_type_2" class="form-control" value="{{ old('price_type_2', $lead->price_type_2 ?? '') }}">
        </div>
        <div class="form-group col-md-4">
            <label class="form-label">Lista 3</label>
            <input type="text" name="price_type_3" class="form-control" value="{{ old('price_type_3', $lead->price_type_3 ?? '') }}">
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <p class="mb-1"><strong>Vender</strong></p>
    @php
        // Listado declarativo de checkboxes de setup para mantener el blade corto y legible
        $setup_flags = [
            'ventas_con_fecha_de_entrega'  => 'Ventas con fecha de entrega (hojas de ruta)',
            'ask_amount_in_vender'         => 'Preguntar la cantidad en vender',
            'redondear_centenas_en_vender' => 'Redondear precios de a centenas',
            'omitir_cuentas_corrientes'    => 'Por defecto, omitir siempre en cuenta corriente',
            'cajas'                        => 'Usa cajas',
            'usar_codigos_de_barra'        => 'Usa códigos de barra',
            'codigos_de_barra_por_defecto' => 'Códigos de barra por defecto (generados con el número interno)',
            'consultora_de_precios'        => 'Ofrece consultoras de precio en el local',
            'imagenes'                     => 'Usa imágenes en los artículos',
            'produccion'                   => 'Usa módulo de producción',
        ];
    @endphp
    @foreach($setup_flags as $flag => $label)
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="{{ $flag }}" name="{{ $flag }}" value="1"
                   @if(old($flag, $lead->{$flag} ?? false)) checked @endif>
            <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
        </div>
    @endforeach
</div>

@extends('layouts.app')

@section('title', 'Promover lead a cliente')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Promover a cliente: {{ $lead->contact_name ?? $lead->company_name ?? 'Lead #' . $lead->id }}</h3>
    <a href="{{ route('leads.show', $lead->id) }}" class="btn btn-link">Volver</a>
</div>

<div class="alert alert-info small mb-3">
    El flujo recomendado es <strong>admin-spa</strong>. Esta pantalla es un respaldo mínimo.
</div>

<form method="POST" action="{{ route('leads.store_promote', $lead->id) }}" class="card p-4">
    @csrf

    <div class="form-group">
        <label class="form-label"><strong>URL del empresa-api de producción *</strong></label>
        <input type="url" name="api_url" class="form-control" required
               placeholder="http://ip-del-servidor/empresa/empresa-api/public"
               value="{{ old('api_url') }}">
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-success">Confirmar promoción</button>
        <a href="{{ route('leads.show', $lead->id) }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

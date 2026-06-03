<div class="form-group">
    <label class="form-label">Nombre *</label>
    <input type="text" name="name" class="form-control" required value="{{ old('name', $client->name ?? '') }}" maxlength="150">
</div>
<div class="form-group">
    <label class="form-label">Empresa</label>
    <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $client->company_name ?? '') }}" maxlength="150">
</div>
<div class="form-group">
    <label class="form-label">Slug</label>
    <input type="text" name="slug" class="form-control" value="{{ old('slug', $client->slug ?? '') }}" maxlength="80">
    <small class="text-muted">Se genera automáticamente si se deja vacío.</small>
</div>
<div class="form-group">
    <label class="form-label">API URL *</label>
    <input type="url" name="api_url" class="form-control" required
           value="{{ old('api_url', $client->api_url ?? '') }}"
           placeholder="http://localhost/empresa/empresa-api/public">
    <small class="text-muted">Base URL del empresa-api del cliente (sin / final).</small>
</div>
<div class="form-group">
    <label class="form-label">api_key (admin → cliente)</label>
    <input type="text" name="api_key" class="form-control" value="{{ old('api_key', $client->api_key ?? '') }}" maxlength="120">
    <small class="text-muted">Header X-Admin-Api-Key que envía admin-api al publicar. Se genera automáticamente si se deja vacío.</small>
</div>
<div class="form-group">
    <label class="form-label">inbound_api_key (cliente → admin)</label>
    <input type="text" name="inbound_api_key" class="form-control" value="{{ old('inbound_api_key', $client->inbound_api_key ?? '') }}" maxlength="120">
    <small class="text-muted">El cliente la envía como X-Admin-Api-Key al reportar lecturas. Se genera automáticamente si se deja vacío.</small>
</div>
<div class="form-check">
    <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
        @if(old('is_active', $client->is_active ?? true)) checked @endif>
    <label class="form-check-label" for="is_active">Activo</label>
</div>

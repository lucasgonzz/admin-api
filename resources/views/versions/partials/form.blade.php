<div class="form-group">
    <label class="form-label">Versión *</label>
    <input type="text" name="version" class="form-control" required
           value="{{ old('version', $version->version ?? '') }}" maxlength="30" placeholder="2.5.0">
</div>
<div class="form-group">
    <label class="form-label">Título</label>
    <input type="text" name="title" class="form-control" value="{{ old('title', $version->title ?? '') }}" maxlength="200">
</div>
<div class="form-group">
    <label class="form-label">Descripción</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $version->description ?? '') }}</textarea>
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select name="status" class="form-control">
        @foreach (['draft' => 'Borrador', 'published' => 'Publicada', 'archived' => 'Archivada'] as $k => $v)
            <option value="{{ $k }}" @if(old('status', $version->status ?? 'draft') === $k) selected @endif>{{ $v }}</option>
        @endforeach
    </select>
    <small class="text-muted">Al pasar a "publicada" por primera vez se registra `published_at = ahora`.</small>
</div>

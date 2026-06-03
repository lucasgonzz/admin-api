{{--
  Selector de scope de ejecución para seeders/comandos de versión.
  @param string $name Nombre del campo del formulario (run_scope).
  @param string $value Valor actual (per_database | per_user).
  @param string $default Default si $value está vacío.
--}}
@php
    $current = old($name, $value ?? $default ?? 'per_database');
@endphp
<select name="{{ $name }}" class="form-control form-control-sm" title="Por base de datos: una vez por DB · Por usuario: una vez por tenant">
    <option value="per_database" @if($current === 'per_database') selected @endif>Por base de datos</option>
    <option value="per_user" @if($current === 'per_user') selected @endif>Por usuario</option>
</select>

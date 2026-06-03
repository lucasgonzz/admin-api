@extends('layouts.app')

@section('title', 'Editar versión')

@section('content')
<h3>Editar versión {{ $version->version }}</h3>

<form method="POST" action="{{ route('versions.update', $version->id) }}" class="card p-4">
    @csrf
    @method('PUT')
    @include('versions.partials.form', ['version' => $version])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="{{ route('versions.show', $version->id) }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

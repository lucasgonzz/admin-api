@extends('layouts.app')

@section('title', 'Nueva versión')

@section('content')
<h3>Nueva versión</h3>

<form method="POST" action="{{ route('versions.store') }}" class="card p-4">
    @csrf
    @include('versions.partials.form', ['version' => null])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Crear</button>
        <a href="{{ route('versions.index') }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

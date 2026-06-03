@extends('layouts.app')

@section('title', 'Nuevo cliente')

@section('content')
<h3>Nuevo cliente</h3>
<form method="POST" action="{{ route('clients.store') }}" class="card p-4">
    @csrf
    @include('clients.partials.form', ['client' => null])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Crear</button>
        <a href="{{ route('clients.index') }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

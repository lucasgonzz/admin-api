@extends('layouts.app')

@section('title', 'Editar cliente')

@section('content')
<h3>Editar cliente</h3>
<form method="POST" action="{{ route('clients.update', $client->id) }}" class="card p-4">
    @csrf
    @method('PUT')
    @include('clients.partials.form', ['client' => $client])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="{{ route('clients.show', $client->id) }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

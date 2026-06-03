@extends('layouts.app')

@section('title', 'Editar lead')

@section('content')
<h3>Editar lead</h3>
<form method="POST" action="{{ route('leads.update', $lead->id) }}" class="card p-4">
    @csrf
    @method('PUT')
    @include('leads.partials.form', ['lead' => $lead])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <a href="{{ route('leads.show', $lead->id) }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

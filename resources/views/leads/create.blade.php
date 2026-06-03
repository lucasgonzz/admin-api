@extends('layouts.app')

@section('title', 'Nuevo lead')

@section('content')
<h3>Nuevo lead</h3>
<form method="POST" action="{{ route('leads.store') }}" class="card p-4">
    @csrf
    @include('leads.partials.form', ['lead' => null])
    <div class="mt-3">
        <button type="submit" class="btn btn-primary">Crear lead</button>
        <a href="{{ route('leads.index') }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
@endsection

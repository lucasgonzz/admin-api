@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
<div class="row justify-content-center mt-5">
    <div class="col-md-5">
        <div class="card">
            <div class="card-body">
                <h4 class="mb-4">Iniciar sesión</h4>
                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="text" name="email" class="form-control" required value="{{ old('email') }}" autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Recordarme</label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

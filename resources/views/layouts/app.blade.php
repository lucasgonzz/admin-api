<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — @yield('title', 'Admin')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding-top: 4.5rem; background: #f7f8fa; }
        .main-container { max-width: 1200px; margin: 0 auto; }
        .table td, .table th { vertical-align: middle; }
        .form-label { font-weight: 600; font-size: 0.9rem; }
        .card { border: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .status-badge { text-transform: capitalize; }
        .copy-cell .input-group { min-width: 0; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container">
        <a class="navbar-brand" href="{{ route('versions.index') }}">{{ config('app.name') }}</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav mr-auto">
                @auth
                    <li class="nav-item {{ request()->routeIs('versions.*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('versions.index') }}">Versiones</a>
                    </li>
                    <li class="nav-item {{ request()->routeIs('clients.*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('clients.index') }}">Clientes</a>
                    </li>
                    <li class="nav-item {{ request()->routeIs('leads.*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('leads.index') }}">Leads</a>
                    </li>
                    <li class="nav-item {{ request()->routeIs('updates.*') ? 'active' : '' }}">
                        <a class="nav-link" href="{{ route('updates.index') }}">Actualizaciones</a>
                    </li>
                @endauth
            </ul>
            <ul class="navbar-nav">
                @auth
                    <li class="nav-item">
                        <span class="navbar-text mr-3">{{ Auth::user()->name }}</span>
                    </li>
                    <li class="nav-item">
                        <form action="{{ route('logout') }}" method="POST" class="form-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-light btn-sm">Salir</button>
                        </form>
                    </li>
                @endauth
            </ul>
        </div>
    </div>
</nav>

<main class="container main-container">

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<script src="https://code.jquery.com/jquery-3.6.0.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    window.adminApiCopyInputById = function (inputId, btn) {
        var el = document.getElementById(inputId);
        if (!el) return;
        var text = el.value !== undefined ? String(el.value) : '';
        if (!text) return;
        var done = function () {
            if (!btn) return;
            if (!btn.getAttribute('data-original-label')) {
                btn.setAttribute('data-original-label', btn.textContent.trim());
            }
            var orig = btn.getAttribute('data-original-label');
            btn.textContent = 'Copiado';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-secondary');
            setTimeout(function () {
                btn.textContent = orig;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(el, done);
            });
        } else {
            fallbackCopy(el, done);
        }
    };
    function fallbackCopy(el, done) {
        el.focus();
        el.select();
        el.setSelectionRange(0, 99999);
        try {
            document.execCommand('copy');
        } catch (e) {}
        if (done) done();
    }
})();
</script>
@stack('scripts')
</body>
</html>
